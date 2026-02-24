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
 * Scheduled Task: sync_stripe_subscriptions
 *
 * Runs every hour. Acts as a safety net in case Stripe webhook events
 * were not received (network issues, server unavailability, etc.).
 *
 * Queries all active and past_due subscriptions in local DB and retrieves
 * their current status from the Stripe API. If a mismatch is detected,
 * the local status is updated and relevant actions are triggered
 * (e.g., unenrolment on expiry).
 *
 * Full implementation: M-5.6
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Hourly Stripe status synchronisation task.
 */
class sync_stripe_subscriptions extends \core\task\scheduled_task {

    /**
     * Returns the display name shown in Site Admin → Scheduled Tasks.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_stripe_subscriptions', 'enrol_mentorsubscription');
    }

    /**
     * Execute the task.
     *
     * Full logic implemented in M-5.6. Stub logs execution for validation.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $secret = get_config('enrol_mentorsubscription', 'stripe_secret_key');
        if (empty($secret)) {
            mtrace('enrol_mentorsubscription: sync_stripe_subscriptions — Stripe secret key not configured; skipping.');
            return;
        }

        require_once(__DIR__ . '/../../../vendor/autoload.php');
        $stripe = new \Stripe\StripeClient($secret);

        // Fetch all local subscriptions that still appear live.
        $locals = $DB->get_records_select(
            'enrol_mentorsub_subscriptions',
            "status IN ('active','past_due') AND stripe_subscription_id IS NOT NULL"
        );

        if (empty($locals)) {
            mtrace('enrol_mentorsubscription: sync_stripe_subscriptions — nothing to sync.');
            return;
        }

        $subManager = new \enrol_mentorsubscription\subscription\subscription_manager();
        $synced     = 0;
        $expired    = 0;

        foreach ($locals as $local) {
            try {
                $stripeSub = $stripe->subscriptions->retrieve($local->stripe_subscription_id);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                mtrace("  [WARN] Could not retrieve {$local->stripe_subscription_id}: {$e->getMessage()}");
                continue;
            }

            $stripeStatus = $stripeSub->status; // active | past_due | canceled | unpaid | trialing

            // Map Stripe status to local status.
            if (in_array($stripeStatus, ['canceled', 'unpaid'], true)
                    && in_array($local->status, ['active', 'past_due'], true)) {
                // Stripe subscription gone — expire locally.
                $subManager->expire_subscription((int) $local->id);
                $expired++;
                mtrace("  Expired subscription {$local->id} (stripe: {$local->stripe_subscription_id}).");

            } elseif ($stripeStatus === 'past_due' && $local->status === 'active') {
                $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'past_due', ['id' => $local->id]);
                $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', time(), ['id' => $local->id]);
                $synced++;

            } elseif ($stripeStatus === 'active' && $local->status === 'past_due') {
                $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'active', ['id' => $local->id]);
                $DB->set_field('enrol_mentorsub_subscriptions', 'timemodified', time(), ['id' => $local->id]);
                $synced++;
            }
        }

        mtrace("enrol_mentorsubscription: sync_stripe_subscriptions — {$synced} updated, {$expired} expired.");
    }
}
