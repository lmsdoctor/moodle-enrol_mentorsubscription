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
     * @return \Stripe\StripeClient
     * @throws \moodle_exception If the secret key is not configured.
     */
    private function get_stripe_client(): \Stripe\StripeClient {
        require_once(__DIR__ . '/../../../vendor/autoload.php');

        $secret = get_config('enrol_mentorsubscription', 'stripe_secret_key');
        if (empty($secret)) {
            throw new \moodle_exception('errornostripekey', 'enrol_mentorsubscription');
        }

        return new \Stripe\StripeClient($secret);
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
     * Stores userid and subtypeid in session metadata so the webhook
     * can identify which mentor triggered the checkout.
     *
     * @param int    $userid      Mentor Moodle user ID.
     * @param int    $subtypeid   Subscription type ID (from enrol_mentorsub_sub_types).
     * @param string $priceId     Stripe Price ID (resolved by pricing_manager).
     * @param string $successUrl  Redirect URL after successful payment.
     * @param string $cancelUrl   Redirect URL if the mentor cancels.
     * @return string Stripe-hosted Checkout URL.
     */
    public function create_checkout_session(
        int $userid,
        int $subtypeid,
        string $priceId,
        string $successUrl,
        string $cancelUrl
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
        return $session->url;
    }

    // -------------------------------------------------------------------------
    // Private event handlers
    // -------------------------------------------------------------------------

    /**
     * Handles checkout.session.completed — creates the initial active subscription.
     *
     * Retrieves the Stripe Subscription object to capture the billing period.
     * Skips if a subscription for this stripe_subscription_id already exists
     * (idempotency guard against duplicate webhook delivery).
     *
     * M-2.5
     *
     * @param \Stripe\Checkout\Session $session
     */
    private function on_checkout_completed(\Stripe\Checkout\Session $session): void {
        global $DB;

        $userid    = (int) ($session->metadata->userid ?? 0);
        $subtypeid = (int) ($session->metadata->subtypeid ?? 0);

        if (!$userid || !$subtypeid) {
            debugging('enrol_mentorsubscription: checkout.session.completed missing metadata.',
                      DEBUG_DEVELOPER);
            return;
        }

        // Idempotency: skip if already processed.
        if ($DB->record_exists('enrol_mentorsub_subscriptions',
                               ['stripe_subscription_id' => $session->subscription])) {
            return;
        }

        // Retrieve full subscription data for period dates.
        $stripe   = $this->get_stripe_client();
        $stripeSub = $stripe->subscriptions->retrieve($session->subscription);

        // Resolve pricing snapshot for this mentor + type.
        $pricing = (new pricing_manager())->resolve($userid, $subtypeid);

        $submanager = new subscription_manager();
        $submanager->create_active_subscription(
            $userid,
            $subtypeid,
            (float) $session->amount_subtotal / 100,   // Convert cents to dollars.
            $pricing->billed_max_mentees,
            $stripeSub->items->data[0]->plan->interval === 'year' ? 'annual' : 'monthly',
            $stripeSub->id,
            $session->customer,
            $pricing->stripe_price_id,
            (int) $stripeSub->current_period_start,
            (int) $stripeSub->current_period_end,
            $pricing->overrideid
        );
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
