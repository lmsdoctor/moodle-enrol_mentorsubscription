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
 * Subscription Manager — lifecycle management for mentor subscriptions.
 *
 * Handles creation, renewal, cancellation and expiry of subscription
 * records in enrol_mentorsub_subscriptions following the immutable
 * ledger pattern (one record per billing cycle).
 *
 * Full implementation: M-2
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\subscription;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages the full lifecycle of mentor subscriptions.
 */
class subscription_manager {

    /**
     * Returns the current active subscription for a mentor, or null if none.
     *
     * Uses INDEX(userid, status) for efficient O(log n) lookup.
     *
     * @param int $userid Mentor user ID.
     * @return \stdClass|null Subscription record or null.
     */
    public function get_active_subscription(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('enrol_mentorsub_subscriptions', [
            'userid' => $userid,
            'status' => 'active',
        ]) ?: null;
    }

    /**
     * Returns the current live subscription for a mentor (active OR paused), or null.
     *
     * Used by the dashboard so paused mentors can still see their panel with a banner.
     * Use get_active_subscription() everywhere else where 'active' semantics are needed.
     *
     * @param int $userid Mentor user ID.
     * @return \stdClass|null Subscription record or null.
     */
    public function get_current_subscription(int $userid): ?\stdClass {
        global $DB;
        $sql = "SELECT *
                  FROM {enrol_mentorsub_subscriptions}
                 WHERE userid = :uid
                   AND status IN ('active', 'paused')
              ORDER BY timecreated DESC
                 LIMIT 1";
        return $DB->get_record_sql($sql, ['uid' => $userid]) ?: null;
    }

    /**
     * Returns the full payment history for a mentor, newest first.
     *
     * @param int $userid Mentor user ID.
     * @return array Array of subscription records.
     */
    public function get_history(int $userid): array {
        global $DB;
        return array_values($DB->get_records(
            'enrol_mentorsub_subscriptions',
            ['userid' => $userid],
            'timecreated DESC'
        ));
    }

    /**
     * Creates the initial subscription record after Stripe checkout.session.completed.
     *
     * @param int    $userid          Mentor user ID.
     * @param int    $subtypeid       Subscription type ID.
     * @param float  $billedPrice     Actual price charged (snapshot).
     * @param int    $billedMaxMentees Mentee limit (snapshot).
     * @param string $billingCycle    'monthly' | 'annual'.
     * @param string $stripeSubId     Stripe subscription ID.
     * @param string $stripeCusId     Stripe customer ID.
     * @param string $stripePriceId   Stripe price ID used.
     * @param int    $periodStart     Billing period start (Unix).
     * @param int    $periodEnd       Billing period end (Unix).
     * @param int|null $overrideid    Override ID if applicable.
     * @return int New subscription record ID.
     */
    public function create_active_subscription(
        int $userid,
        int $subtypeid,
        float $billedPrice,
        int $billedMaxMentees,
        string $billingCycle,
        string $stripeSubId,
        string $stripeCusId,
        string $stripePriceId,
        int $periodStart,
        int $periodEnd,
        ?int $overrideid = null
    ): int {
        global $DB;

        $now    = time();
        $record = (object) [
            'userid'                 => $userid,
            'subtypeid'              => $subtypeid,
            'overrideid'             => $overrideid,
            'billed_price'           => $billedPrice,
            'billed_max_mentees'     => $billedMaxMentees,
            'billing_cycle'          => $billingCycle,
            'status'                 => 'active',
            'stripe_subscription_id' => $stripeSubId,
            'stripe_customer_id'     => $stripeCusId,
            'stripe_payment_intent_id' => null,
            'stripe_invoice_id'      => null,
            'stripe_price_id_used'   => $stripePriceId,
            'period_start'           => $periodStart,
            'period_end'             => $periodEnd,
            'cancelled_at'           => null,
            'cancel_at_period_end'   => 0,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ];

        $newId = $DB->insert_record('enrol_mentorsub_subscriptions', $record);

        // Enrol the mentor in all subscription-gated courses.
        (new \enrol_mentorsubscription\mentorship\enrolment_sync())->enrol_mentor($userid);

        return $newId;
    }

    /**
     * Processes a subscription renewal: marks previous cycle as
     * 'superseded' and creates a new 'active' record.
     *
     * Runs inside a DB transaction to ensure atomicity.
     * Called by stripe_handler on invoice.paid events.
     *
     * M-2.6
     *
     * @param int   $previousId  ID of the current active subscription.
     * @param array $newData     New cycle snapshot from Stripe invoice.paid. Keys:
     *                           userid, subtypeid, billed_price, billed_max_mentees,
     *                           billing_cycle, stripe_subscription_id, stripe_customer_id,
     *                           stripe_price_id_used, period_start, period_end,
     *                           [overrideid], [stripe_invoice_id], [stripe_payment_intent_id]
     * @return int  New subscription record ID.
     */
    public function process_renewal(int $previousId, array $newData): int {
        global $DB;

        $now         = time();
        $transaction = $DB->start_delegated_transaction();

        try {
            // 1. Mark previous cycle as superseded.
            $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'superseded',
                           ['id' => $previousId]);
            $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', $now,
                           ['id' => $previousId]);

            // 2. Create the new active cycle — snapshot price/limit at renewal time.
            $record = (object) [
                'userid'                   => $newData['userid'],
                'subtypeid'                => $newData['subtypeid'],
                'overrideid'               => $newData['overrideid'] ?? null,
                'billed_price'             => $newData['billed_price'],
                'billed_max_mentees'       => $newData['billed_max_mentees'],
                'billing_cycle'            => $newData['billing_cycle'],
                'status'                   => 'active',
                'stripe_subscription_id'   => $newData['stripe_subscription_id'],
                'stripe_customer_id'       => $newData['stripe_customer_id'],
                'stripe_payment_intent_id' => $newData['stripe_payment_intent_id'] ?? null,
                'stripe_invoice_id'        => $newData['stripe_invoice_id'] ?? null,
                'stripe_price_id_used'     => $newData['stripe_price_id_used'],
                'period_start'             => $newData['period_start'],
                'period_end'               => $newData['period_end'],
                'cancelled_at'             => null,
                'cancel_at_period_end'     => 0,
                'timecreated'              => $now,
                'timemodified'             => $now,
            ];

            $newId = $DB->insert_record('enrol_mentorsub_subscriptions', $record);
            $transaction->allow_commit();

            return $newId;

        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Marks a subscription as expired and bulk-unenrols all mentees.
     *
     * Called by stripe_handler on customer.subscription.deleted events,
     * and by sync_stripe_subscriptions task.
     *
     * M-2.8 + M-3.7
     *
     * @param int $subscriptionId Subscription record ID.
     * @return void
     */
    public function expire_subscription(int $subscriptionId): void {
        global $DB;

        $subscription = $DB->get_record('enrol_mentorsub_subscriptions',
                                        ['id' => $subscriptionId], '*', MUST_EXIST);

        $now         = time();
        $transaction = $DB->start_delegated_transaction();

        try {
            // 1. Mark subscription expired.
            $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'expired',
                           ['id' => $subscriptionId]);
            $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', $now,
                           ['id' => $subscriptionId]);

            // 2. Deactivate all mentees for this mentor.
            $mentees = $DB->get_records('enrol_mentorsub_mentees',
                                        ['mentorid' => $subscription->userid,
                                         'is_active' => 1]);

            foreach ($mentees as $mentee) {
                $DB->set_field('enrol_mentorsub_mentees', 'is_active', 0,
                               ['id' => $mentee->id]);
                $DB->set_field('enrol_mentorsub_mentees', 'timemodified', $now,
                               ['id' => $mentee->id]);
            }

            $transaction->allow_commit();

        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // 3. Unenrol mentees and mentor OUTSIDE the transaction (enrol API has its own transactions).
        $sync = new \enrol_mentorsubscription\mentorship\enrolment_sync();
        if (!empty($mentees)) {
            foreach ($mentees as $mentee) {
                $sync->unenrol_mentee((int) $mentee->menteeid);
            }
        }
        // Unenrol the mentor from all subscription courses.
        $sync->unenrol_mentor((int) $subscription->userid);
    }

    // =========================================================================
    // Active Stripe subscription management (admin / mentor-initiated)
    // =========================================================================

    /**
     * Request cancellation of a subscription in Stripe and reflect it locally.
     *
     * Cancellation modes:
     * - $immediately = false (default): sets cancel_at_period_end = true.
     *   The mentor retains access until the current billing period ends.
     *   Stripe will fire customer.subscription.deleted at period end, which
     *   triggers expire_subscription() via the webhook.
     * - $immediately = true: Stripe terminates NOW and fires the deleted event
     *   immediately, which triggers expire_subscription() via the webhook.
     *   This method also pre-emptively updates the local record so the UI
     *   reflects the intent without waiting for the webhook.
     *
     * @param int  $subscriptionId Local subscription record ID.
     * @param bool $immediately    True to cancel now; false to cancel at period end.
     * @return void
     * @throws \moodle_exception If the subscription has no stripe_subscription_id.
     */
    public function request_cancellation(int $subscriptionId, bool $immediately = false): void {
        global $DB;

        $sub = $DB->get_record('enrol_mentorsub_subscriptions',
                               ['id' => $subscriptionId], '*', MUST_EXIST);

        if (empty($sub->stripe_subscription_id)) {
            throw new \moodle_exception('error_no_stripe_subscription', 'enrol_mentorsubscription');
        }

        $handler = new stripe_handler();
        $handler->cancel_subscription($sub->stripe_subscription_id, $immediately);

        $now = time();

        if ($immediately) {
            // Webhook will also fire, but update locally now so the admin panel
            // reflects the change without waiting for the webhook round-trip.
            $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'cancelled',
                           ['id' => $subscriptionId]);
            $DB->set_field('enrol_mentorsub_subscriptions', 'cancelled_at', $now,
                           ['id' => $subscriptionId]);
            $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', $now,
                           ['id' => $subscriptionId]);
        } else {
            // Mark cancel_at_period_end locally so the dashboard can show a warning.
            $DB->set_field('enrol_mentorsub_subscriptions', 'cancel_at_period_end', 1,
                           ['id' => $subscriptionId]);
            $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', $now,
                           ['id' => $subscriptionId]);
        }
    }

    /**
     * Pause a subscription: tells Stripe to stop collecting payments temporarily.
     *
     * The local status is set to 'paused'. Mentee access is retained while paused.
     * Use resume_subscription() to re-enable payment collection.
     *
     * @param int $subscriptionId Local subscription record ID.
     * @return void
     * @throws \moodle_exception If the subscription has no stripe_subscription_id.
     */
    public function pause_subscription(int $subscriptionId): void {
        global $DB;

        $sub = $DB->get_record('enrol_mentorsub_subscriptions',
                               ['id' => $subscriptionId], '*', MUST_EXIST);

        if (empty($sub->stripe_subscription_id)) {
            throw new \moodle_exception('error_no_stripe_subscription', 'enrol_mentorsubscription');
        }

        (new stripe_handler())->pause_subscription($sub->stripe_subscription_id);

        $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'paused',
                       ['id' => $subscriptionId]);
        $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', time(),
                       ['id' => $subscriptionId]);
    }

    /**
     * Resume a previously paused subscription.
     *
     * Re-enables Stripe payment collection on the next billing date.
     * The local status is restored to 'active'.
     *
     * @param int $subscriptionId Local subscription record ID.
     * @return void
     * @throws \moodle_exception If the subscription has no stripe_subscription_id or is not paused.
     */
    public function resume_subscription(int $subscriptionId): void {
        global $DB;

        $sub = $DB->get_record('enrol_mentorsub_subscriptions',
                               ['id' => $subscriptionId], '*', MUST_EXIST);

        if (empty($sub->stripe_subscription_id)) {
            throw new \moodle_exception('error_no_stripe_subscription', 'enrol_mentorsubscription');
        }

        if ($sub->status !== 'paused') {
            throw new \moodle_exception('error_subscription_not_paused', 'enrol_mentorsubscription');
        }

        (new stripe_handler())->resume_subscription($sub->stripe_subscription_id);

        $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'active',
                       ['id' => $subscriptionId]);
        $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', time(),
                       ['id' => $subscriptionId]);
    }

    /**
     * Change a subscription's plan (upgrade / downgrade) in Stripe.
     *
     * Switches the Stripe subscription to $newPriceId with immediate proration.
     * Updates the local snapshot fields (stripe_price_id_used, billed_max_mentees,
     * billing_cycle) to reflect the new plan.
     *
     * @param int    $subscriptionId  Local subscription record ID.
     * @param int    $newSubtypeId    Target sub_type ID.
     * @param string $newPriceId      New Stripe Price ID.
     * @param int    $newMaxMentees   New mentee limit (from new plan or override).
     * @param string $newBillingCycle 'monthly' or 'annual'.
     * @return void
     */
    public function change_plan(
        int $subscriptionId,
        int $newSubtypeId,
        string $newPriceId,
        int $newMaxMentees,
        string $newBillingCycle
    ): void {
        global $DB;

        $sub = $DB->get_record('enrol_mentorsub_subscriptions',
                               ['id' => $subscriptionId], '*', MUST_EXIST);

        if (empty($sub->stripe_subscription_id)) {
            throw new \moodle_exception('error_no_stripe_subscription', 'enrol_mentorsubscription');
        }

        (new stripe_handler())->change_plan($sub->stripe_subscription_id, $newPriceId);

        $DB->update_record('enrol_mentorsub_subscriptions', (object) [
            'id'                   => $subscriptionId,
            'subtypeid'            => $newSubtypeId,
            'stripe_price_id_used' => $newPriceId,
            'billed_max_mentees'   => $newMaxMentees,
            'billing_cycle'        => $newBillingCycle,
            'timemodified'         => time(),
        ]);
    }
}
