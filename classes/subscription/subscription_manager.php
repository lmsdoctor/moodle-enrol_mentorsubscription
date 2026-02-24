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

        return $DB->insert_record('enrol_mentorsub_subscriptions', $record);
    }

    /**
     * Processes a subscription renewal: marks previous cycle as
     * 'superseded' and creates a new 'active' record.
     *
     * Runs inside a DB transaction to ensure atomicity.
     *
     * Full implementation: M-2.6
     *
     * @param int    $previousId   ID of the current active subscription.
     * @param array  $newData      New cycle data from Stripe invoice.paid event.
     * @return int   New subscription record ID.
     */
    public function process_renewal(int $previousId, array $newData): int {
        global $DB;

        // TODO M-2.6: Implement transactional renewal.
        // 1. $DB->start_delegated_transaction()
        // 2. UPDATE previous record: status = 'superseded'
        // 3. INSERT new record: status = 'active' with new snapshot
        // 4. Commit transaction.
        throw new \coding_exception('process_renewal() not yet implemented — scheduled for M-2.6.');
    }

    /**
     * Marks a subscription as expired and triggers bulk unenrolment.
     *
     * Full implementation: M-2.8
     *
     * @param int $subscriptionId Subscription record ID.
     * @return void
     */
    public function expire_subscription(int $subscriptionId): void {
        // TODO M-2.8 + M-3.7: Set status=expired, dispatch bulk unenrolment via Ad-hoc Task.
        throw new \coding_exception('expire_subscription() not yet implemented — scheduled for M-2.8.');
    }
}
