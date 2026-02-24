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
        // TODO M-5.6: Retrieve active/past_due subscriptions, call Stripe API,
        // reconcile status and trigger actions on mismatch.
        mtrace('enrol_mentorsubscription: sync_stripe_subscriptions — stub executed.');
    }
}
