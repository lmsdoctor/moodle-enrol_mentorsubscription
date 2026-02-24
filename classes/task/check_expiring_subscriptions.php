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
 * Scheduled Task: check_expiring_subscriptions
 *
 * Runs daily at 08:00. Queries subscriptions with status='active'
 * and period_end within the configured warning window (N days).
 * Sends a renewal reminder via notification_manager if not already sent.
 *
 * Deduplication is guaranteed by UNIQUE(subscriptionid, type, days_before)
 * in enrol_mentorsub_notifications — idempotent on re-run.
 *
 * Full implementation: M-5.1
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily expiry warning task.
 */
class check_expiring_subscriptions extends \core\task\scheduled_task {

    /**
     * Returns the display name shown in Site Admin → Scheduled Tasks.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_check_expiring_subscriptions', 'enrol_mentorsubscription');
    }

    /**
     * Execute the task.
     *
     * Full logic implemented in M-5.1. Stub logs execution for validation.
     *
     * @return void
     */
    public function execute(): void {
        // TODO M-5.1: Query subscriptions nearing expiry and call notification_manager.
        mtrace('enrol_mentorsubscription: check_expiring_subscriptions — stub executed.');
    }
}
