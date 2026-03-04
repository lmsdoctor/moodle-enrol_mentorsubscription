<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Stripe Handler — Checkout Session creation and webhook event processing.
 *
 * Encapsulates all Stripe SDK interactions:
 *   - create_checkout_session(): initiates payment flow.
 *   - construct_event():         verifies HMAC signature (used by webhook.php).
 *   - handle_event():            dispatches each Stripe event type to the
 *                                appropriate subscription_manager method.
 *
 * Full implementation: M-2.4 to M-2.10
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\subscription;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__, 3) . '/vendor/autoload.php');


use \Stripe\StripeClient;

/**
 * Handles all Stripe API interactions for the plugin.
 */
class stripe_handler {

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Load Stripe autoloader and return an authenticated StripeClient.
     *
     * @return StripeClient
     * @throws \moodle_exception If the secret key is not configured.
     */
    private function get_stripe_client(): StripeClient {

        $mode      = get_config('enrol_mentorsubscription', 'stripe_mode') ?: 'live';
        $configkey = ($mode === 'sandbox') ? 'stripe_sandbox_secret_key' : 'stripe_secret_key';
        $secret    = get_config('enrol_mentorsubscription', $configkey);

        if (empty($secret)) {
            throw new \moodle_exception('errornostripekey', 'enrol_mentorsubscription');
        }

        return new StripeClient($secret);
    }

    // -------------------------------------------------------------------------
    // Active subscription management (admin / mentor-initiated actions)
    // -------------------------------------------------------------------------

    /**
     * Cancel a Stripe subscription.
     *
     * By default cancels at period end (mentor retains access until billing cycle ends).
     * Pass $immediately = true to cancel and revoke access now.
     *
     * @param string $stripeSubId  Stripe subscription ID (sub_xxx).
     * @param bool   $immediately  If true, cancel now. If false, cancel at period end.
     * @return \Stripe\Subscription Updated Stripe Subscription object.
     */
    public function cancel_subscription(string $stripeSubId, bool $immediately = false): \Stripe\Subscription {
        $stripe = $this->get_stripe_client();

        if ($immediately) {
            return $stripe->subscriptions->cancel($stripeSubId);
        }

        // cancel_at_period_end = true — Stripe keeps subscription active until cycle end,
        // then fires customer.subscription.deleted which our webhook handles.
        return $stripe->subscriptions->update($stripeSubId, [
            'cancel_at_period_end' => true,
        ]);
    }

    /**
     * Pause a Stripe subscription's payment collection.
     *
     * The subscription remains in Stripe as "active" but invoices are not charged.
     * Useful for temporary suspensions without losing the subscription record.
     *
     * @param string $stripeSubId  Stripe subscription ID.
     * @return \Stripe\Subscription Updated Stripe Subscription object.
     */
    public function pause_subscription(string $stripeSubId): \Stripe\Subscription {
        $stripe = $this->get_stripe_client();

        return $stripe->subscriptions->update($stripeSubId, [
            'pause_collection' => ['behavior' => 'keep_as_draft'],
        ]);
    }

    /**
     * Resume a paused Stripe subscription.
     *
     * Clears pause_collection so invoicing resumes on the next billing date.
     *
     * @param string $stripeSubId  Stripe subscription ID.
     * @return \Stripe\Subscription Updated Stripe Subscription object.
     */
    public function resume_subscription(string $stripeSubId): \Stripe\Subscription {
        $stripe = $this->get_stripe_client();

        return $stripe->subscriptions->update($stripeSubId, [
            'pause_collection' => '',   // empty string clears the pause_collection hash.
        ]);
    }

    /**
     * Change a subscription to a different Stripe Price (plan upgrade / downgrade).
     *
     * Uses proration mode 'always_invoice' so the change takes effect immediately
     * and a prorated invoice is generated.
     *
     * @param string $stripeSubId   Stripe subscription ID.
     * @param string $newPriceId    The new Stripe Price ID to switch to.
     * @return \Stripe\Subscription Updated Stripe Subscription object.
     */
    public function change_plan(string $stripeSubId, string $newPriceId): \Stripe\Subscription {
        $stripe = $this->get_stripe_client();

        // Retrieve current subscription to get the subscription item ID.
        $sub        = $stripe->subscriptions->retrieve($stripeSubId);
        $itemId     = $sub->items->data[0]->id;

        return $stripe->subscriptions->update($stripeSubId, [
            'items'          => [['id' => $itemId, 'price' => $newPriceId]],
            'proration_behavior' => 'always_invoice',
        ]);
    }

    /**
     * Retrieve a single Stripe subscription by ID.
     *
     * @param string $stripeSubId  Stripe subscription ID.
     * @return \Stripe\Subscription
     */
    public function retrieve_subscription(string $stripeSubId): \Stripe\Subscription {
        $stripe = $this->get_stripe_client();
        return $stripe->subscriptions->retrieve($stripeSubId);
    }

    /**
     * List all Stripe subscriptions for a customer.
     *
     * @param string $stripeCustomerId  Stripe customer ID (cus_xxx).
     * @param int    $limit             Max results (1–100).
     * @return \Stripe\Collection<\Stripe\Subscription>
     */
    public function list_subscriptions_for_customer(
        string $stripeCustomerId,
        int $limit = 10
    ): \Stripe\Collection {
        $stripe = $this->get_stripe_client();

        return $stripe->subscriptions->all([
            'customer' => $stripeCustomerId,
            'limit'    => min(max(1, $limit), 100),
            'expand'   => ['data.default_payment_method'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Webhook signature verification — M-2.10
    // -------------------------------------------------------------------------

    /**
     * Verify the Stripe webhook signature and return the parsed event object.
     *
     * Called by webhook.php before any processing begins.
     * Rejects requests without a valid HMAC-SHA256 signature (HTTP 400).
     *
     * @param string $payload       Raw request body (php://input).
     * @param string $sigHeader     Value of the Stripe-Signature header.
     * @param string $webhookSecret Webhook signing secret from plugin settings.
     * @return \Stripe\Event Verified event object.
     * @throws \UnexpectedValueException On invalid payload format.
     * @throws \Stripe\Exception\SignatureVerificationException On HMAC mismatch.
     */
    public function construct_event(string $payload, string $sigHeader, string $webhookSecret): \Stripe\Event {
        require_once(__DIR__ . '/../../../vendor/autoload.php');
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }

    // -------------------------------------------------------------------------
    // Webhook event dispatcher — M-2.5 to M-2.8
    // -------------------------------------------------------------------------

    /**
     * Dispatch a verified Stripe event to the correct handler method.
     *
     * Supported event types:
     *   - checkout.session.completed   → on_checkout_completed()      (M-2.5)
     *   - invoice.paid                 → on_invoice_paid()            (M-2.6)
     *   - invoice.payment_failed       → on_invoice_payment_failed()  (M-2.7)
     *   - customer.subscription.deleted → on_subscription_deleted()   (M-2.8)
     *
     * Unknown types are silently ignored with a developer debug message.
     *
     * @param \Stripe\Event $event Verified Stripe event.
     * @return void
     */
    public function handle_event(\Stripe\Event $event): void {
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->on_checkout_completed($event->data->object);
                break;

            case 'invoice.paid':
                $this->on_invoice_paid($event->data->object);
                break;

            case 'invoice.payment_failed':
                $this->on_invoice_payment_failed($event->data->object);
                break;

            case 'customer.subscription.deleted':
                $this->on_subscription_deleted($event->data->object);
                break;

            default:
                debugging("enrol_mentorsubscription: unhandled Stripe event type '{$event->type}'",
                          DEBUG_DEVELOPER);
        }
    }

    // -------------------------------------------------------------------------
    // Checkout Session creation — M-2.4
    // -------------------------------------------------------------------------

    /**
     * Create a Stripe Checkout Session and return the redirect URL.
     *
     * Stores userid, subtypeid and orderid in session metadata so the webhook
     * can identify which mentor triggered the checkout and which order record
     * to mark as completed.
     *
     * After the session is created the local order record is updated with
     * the Stripe session ID so both subscribe.php (success/cancel return)
     * and the webhook can look it up.
     *
     * @param int    $userid      Mentor Moodle user ID.
     * @param int    $subtypeid   Subscription type ID (from enrol_mentorsub_sub_types).
     * @param string $priceId     Stripe Price ID (resolved by pricing_manager).
     * @param string $successUrl  Redirect URL after successful payment.
     * @param string $cancelUrl   Redirect URL if the mentor cancels.
     * @param int    $orderid     Local order record ID (enrol_mentorsub_orders.id).
     * @return string Stripe-hosted Checkout URL.
     */
    public function create_checkout_session(
        int $userid,
        int $subtypeid,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        int $orderid = 0
    ): string {
        global $DB;

        $stripe = $this->get_stripe_client();

        $params = [
            'mode'                 => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'metadata'    => [
                'userid'    => $userid,
                'subtypeid' => $subtypeid,
                'orderid'   => $orderid,
            ],
        ];

        // Reuse existing Stripe customer if the mentor has subscribed before.
        $existing = $DB->get_record_select(
            'enrol_mentorsub_subscriptions',
            'userid = :uid AND stripe_customer_id IS NOT NULL',
            ['uid' => $userid],
            'stripe_customer_id',
            IGNORE_MULTIPLE
        );

        if ($existing && !empty($existing->stripe_customer_id)) {
            $params['customer'] = $existing->stripe_customer_id;
        } else {
            $user = $DB->get_record('user', ['id' => $userid], 'email', MUST_EXIST);
            $params['customer_email'] = $user->email;
        }

        $session = $stripe->checkout->sessions->create($params);

        // Persist the Stripe session ID on the local order record immediately
        // so subscribe.php (success/cancel) and the webhook can all look it up.
        if ($orderid > 0) {
            $DB->update_record('enrol_mentorsub_orders', (object) [
                'id'               => $orderid,
                'stripe_session_id' => $session->id,
                'timemodified'     => time(),
            ]);
        }

        return $session->url;
    }

    // -------------------------------------------------------------------------
    // Private event handlers
    // -------------------------------------------------------------------------

    /**
     * Public entry point for fulfilling a Stripe Checkout Session by session ID.
     *
     * Called by subscribe.php immediately after the user returns from Stripe
     * (success URL) so the subscription is created without waiting for the
     * webhook. The webhook calls on_checkout_completed() which delegates to
     * fulfill_session_object() — the idempotency guard prevents double processing.
     *
     * Returns true if the session was fulfilled (subscription created),
     * false if already processed or if payment is not yet confirmed.
     *
     * @param string $sessionId Stripe Checkout Session ID (cs_xxx).
     * @return bool
     */
    public function fulfill_checkout_session(string $sessionId): bool {
        $stripe  = $this->get_stripe_client();
        $session = $stripe->checkout->sessions->retrieve($sessionId, [
            'expand' => ['subscription'],
        ]);

        // Only fulfil sessions where Stripe has confirmed the payment.
        // 'unpaid' means asynchronous method (e.g. bank transfer) — webhook
        // will fire later when it clears.
        if ($session->status !== 'complete' || $session->payment_status !== 'paid') {
            return false;
        }

        return $this->fulfill_session_object($session);
    }

    /**
     * Handles checkout.session.completed webhook event.
     *
     * Thin wrapper: verifies the event object is a completed+paid session
     * then delegates to fulfill_session_object() for the actual work.
     *
     * M-2.5
     *
     * @param \Stripe\Checkout\Session $session
     */
    private function on_checkout_completed(\Stripe\Checkout\Session $session): void {
        // Webhook delivers this event only for completed sessions, but guard
        // against edge cases where payment may still be pending (BNPL, etc.).
        if ($session->payment_status !== 'paid') {
            debugging(
                'enrol_mentorsubscription: checkout.session.completed received but payment_status=' .
                $session->payment_status . '. Waiting for webhook confirmation.',
                DEBUG_DEVELOPER
            );
            return;
        }

        $this->fulfill_session_object($session);
    }

    /**
     * Core fulfillment logic: creates the active subscription from a confirmed
     * Stripe Checkout Session object.
     *
     * Shared by on_checkout_completed() (webhook path) and
     * fulfill_checkout_session() (success-URL path).
     *
     * Idempotency: returns false immediately if a subscription record for this
     * Stripe subscription ID already exists (covers webhook + page-return race).
     *
     * @param \Stripe\Checkout\Session $session A complete+paid session object.
     * @return bool True if subscription was created, false if already existed.
     */
    private function fulfill_session_object(\Stripe\Checkout\Session $session): bool {
        global $DB;

        $userid    = (int) ($session->metadata->userid    ?? 0);
        $subtypeid = (int) ($session->metadata->subtypeid ?? 0);
        $orderid   = (int) ($session->metadata->orderid   ?? 0);

        if (!$userid || !$subtypeid) {
            debugging('enrol_mentorsubscription: checkout session missing userid/subtypeid metadata.',
                      DEBUG_DEVELOPER);
            return false;
        }

        // --- Idempotency: subscription already created for this session ------
        if ($DB->record_exists('enrol_mentorsub_subscriptions',
                               ['stripe_subscription_id' => $session->subscription])) {
            // Sync the order record in case this is the webhook arriving after
            // subscribe.php already fulfilled it.
            if ((bool) $orderid) {
                $existing = $DB->get_record('enrol_mentorsub_subscriptions',
                                            ['stripe_subscription_id' => $session->subscription],
                                            'id', IGNORE_MISSING);
                if ($existing) {
                    $DB->update_record('enrol_mentorsub_orders', (object) [
                        'id'               => $orderid,
                        'status'           => 'completed',
                        'stripe_session_id' => $session->id,
                        'subscriptionid'   => $existing->id,
                        'timemodified'     => time(),
                    ]);
                }
            }
            return false;
        }

        // --- Retrieve full Stripe subscription for period dates ---------------
        // If the session was expanded (via fulfill_checkout_session) the
        // subscription may already be a full object; otherwise fetch it.
        $stripe    = $this->get_stripe_client();
        $stripeSub = is_string($session->subscription)
            ? $stripe->subscriptions->retrieve($session->subscription)
            : $session->subscription;

        // --- Resolve pricing snapshot -----------------------------------------
        $pricing = (new pricing_manager())->resolve($userid, $subtypeid);

        $submanager     = new subscription_manager();
        $subscriptionid = $submanager->create_active_subscription(
            $userid,
            $subtypeid,
            (float) $session->amount_subtotal / 100,
            $pricing->billed_max_mentees,
            $stripeSub->items->data[0]->plan->interval === 'year' ? 'annual' : 'monthly',
            $stripeSub->id,
            $session->customer,
            $pricing->stripe_price_id,
            (int) $stripeSub->current_period_start,
            (int) $stripeSub->current_period_end,
            $pricing->overrideid
        );

        // --- Mark the order as completed --------------------------------------
        if ((bool) $orderid) {
            $DB->update_record('enrol_mentorsub_orders', (object) [
                'id'               => $orderid,
                'status'           => 'completed',
                'stripe_session_id' => $session->id,
                'subscriptionid'   => $subscriptionid,
                'timemodified'     => time(),
            ]);
        }

        return true;
    }

    /**
     * Handles invoice.paid — processes subscription renewals.
     *
     * Skips the first invoice (already handled by checkout.session.completed).
     * On renewals, calls process_renewal() to create a new immutable cycle record.
     *
     * M-2.6
     *
     * @param \Stripe\Invoice $invoice
     */
    private function on_invoice_paid(\Stripe\Invoice $invoice): void {
        global $DB;

        if (empty($invoice->subscription)) {
            return;
        }

        // Idempotency: if this invoice ID is already stored as a subscription
        // record, the renewal has already been processed — skip silently.
        // Stripe may deliver the same invoice.paid event more than once on retries.
        if (!empty($invoice->id) &&
            $DB->record_exists('enrol_mentorsub_subscriptions', ['stripe_invoice_id' => $invoice->id])) {
            debugging("enrol_mentorsubscription: invoice {$invoice->id} already processed, skipping.",
                      DEBUG_DEVELOPER);
            return;
        }

        $existing = $DB->get_record('enrol_mentorsub_subscriptions',
                                    ['stripe_subscription_id' => $invoice->subscription,
                                     'status' => 'active']);

        // No active local record = first payment, already handled by checkout.session.completed.
        if (!$existing) {
            return;
        }

        // Build renewal snapshot from the invoice line.
        $line        = $invoice->lines->data[0];
        $billingCycle = ($line->plan->interval ?? 'month') === 'year' ? 'annual' : 'monthly';

        $pricing = (new pricing_manager())->resolve(
            (int) $existing->userid,
            (int) $existing->subtypeid
        );

        (new subscription_manager())->process_renewal((int) $existing->id, [
            'userid'                   => (int) $existing->userid,
            'subtypeid'                => (int) $existing->subtypeid,
            'overrideid'               => $pricing->overrideid,
            'billed_price'             => (float) $invoice->amount_paid / 100,
            'billed_max_mentees'       => $pricing->billed_max_mentees,
            'billing_cycle'            => $billingCycle,
            'stripe_subscription_id'   => $invoice->subscription,
            'stripe_customer_id'       => $invoice->customer,
            'stripe_payment_intent_id' => $invoice->payment_intent ?? null,
            'stripe_invoice_id'        => $invoice->id,
            'stripe_price_id_used'     => $pricing->stripe_price_id,
            'period_start'             => (int) $line->period->start,
            'period_end'               => (int) $line->period->end,
        ]);
    }

    /**
     * Handles invoice.payment_failed — marks subscription as past_due.
     *
     * M-2.7
     *
     * @param \Stripe\Invoice $invoice
     */
    private function on_invoice_payment_failed(\Stripe\Invoice $invoice): void {
        global $DB;

        if (empty($invoice->subscription)) {
            return;
        }

        $now = time();
        $DB->set_field_select(
            'enrol_mentorsub_subscriptions',
            'status',
            'past_due',
            'stripe_subscription_id = :sid AND status = :st',
            ['sid' => $invoice->subscription, 'st' => 'active']
        );
        $DB->set_field_select(
            'enrol_mentorsub_subscriptions',
            'timemodified',
            $now,
            'stripe_subscription_id = :sid',
            ['sid' => $invoice->subscription]
        );

        debugging("enrol_mentorsubscription: subscription {$invoice->subscription} marked past_due.",
                  DEBUG_DEVELOPER);
    }

    /**
     * Handles customer.subscription.deleted — expires the subscription
     * and bulk-unenrols all active mentees.
     *
     * M-2.8
     *
     * @param \Stripe\Subscription $stripeSub
     */
    private function on_subscription_deleted(\Stripe\Subscription $stripeSub): void {
        global $DB;

        $local = $DB->get_record_select(
            'enrol_mentorsub_subscriptions',
            "stripe_subscription_id = :sid AND status IN ('active','past_due')",
            ['sid' => $stripeSub->id]
        );

        if (!$local) {
            return;
        }

        (new subscription_manager())->expire_subscription((int) $local->id);
    }
}
