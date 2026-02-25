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
        global $DB;

        // Warning thresholds (days before expiry). Configurable via plugin settings.
        $rawDays = get_config('enrol_mentorsubscription', 'expiry_warning_days');
        // Support both a single integer ("7") and a comma-separated list ("14,7,3").
        if (!empty($rawDays)) {
            $thresholds = array_filter(
                array_map('intval', explode(',', $rawDays)),
                static fn($d) => $d > 0
            );
        }
        if (empty($thresholds)) {
            $thresholds = [14, 7, 3];
        }

        $now     = time();
        $manager = new \enrol_mentorsubscription\notification_manager();
        $total   = 0;

        foreach ($thresholds as $days) {
            $windowStart = $now + ($days * DAYSECS);
            $windowEnd   = $windowStart + DAYSECS;

            $subscriptions = $DB->get_records_select(
                'enrol_mentorsub_subscriptions',
                "status = 'active' AND period_end >= :wstart AND period_end < :wend",
                ['wstart' => $windowStart, 'wend' => $windowEnd]
            );

            foreach ($subscriptions as $sub) {
                $sent = $manager->notify_expiry_warning((int) $sub->id, $days);
                if ($sent) {
                    $total++;
                }
            }
        }

        mtrace("enrol_mentorsubscription: check_expiring_subscriptions — sent {$total} warning(s).");
    }
}
