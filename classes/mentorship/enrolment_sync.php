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
 * Enrolment Sync — course enrolment/unenrolment for mentors and mentees.
 *
 * Wraps the enrol_mentorsubscription_plugin enrol/unenrol methods to
 * provide batch operations across all subscription courses.
 *
 * Course list is read from the plugin setting 'included_course_ids'
 * (comma-separated integer IDs), configured at:
 * Site administration → Plugins → Enrolments → Mentor Subscription.
 *
 * Critical design rule: only touches enrolments created by THIS plugin.
 * Never removes enrolments from other methods (manual, cohort, etc.).
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\mentorship;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles course enrolment synchronisation for mentees and mentors.
 */
class enrolment_sync {

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the list of course IDs configured in the plugin settings.
     *
     * Reads the comma-separated 'included_course_ids' config value, splits it,
     * filters out empty/non-numeric entries and returns an array of ints.
     *
     * @return int[]
     */
    private function get_course_ids(): array {
        $raw = get_config('enrol_mentorsubscription', 'included_course_ids');

        if (empty($raw)) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // Mentee enrolment
    // -------------------------------------------------------------------------

    /**
     * Enrols a mentee in all courses defined in 'included_course_ids' setting.
     *
     * Called when a mentor assigns a new mentee (mentorship_manager::add_mentee)
     * or re-activates an existing one (toggle_mentee_status).
     *
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function enrol_mentee(int $menteeid): void {
        $courseids = $this->get_course_ids();

        if (empty($courseids)) {
            debugging(
                'enrol_mentorsubscription: no courses configured in included_course_ids — mentee not enrolled.',
                DEBUG_DEVELOPER
            );
            return;
        }

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courseids as $courseid) {
            try {
                $plugin->enrol_mentee($menteeid, $courseid);
            } catch (\Throwable $e) {
                debugging(
                    "enrol_mentorsubscription: failed to enrol mentee {$menteeid} " .
                    "in course {$courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * Unenrols a mentee from all courses defined in 'included_course_ids' setting.
     *
     * Only removes enrolments created by this plugin; never touches manual,
     * self-enrol or cohort enrolments.
     *
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function unenrol_mentee(int $menteeid): void {
        $courseids = $this->get_course_ids();

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courseids as $courseid) {
            try {
                $plugin->unenrol_mentee($menteeid, $courseid);
            } catch (\Throwable $e) {
                debugging(
                    "enrol_mentorsubscription: failed to unenrol mentee {$menteeid} " .
                    "from course {$courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Mentor enrolment
    // -------------------------------------------------------------------------

    /**
     * Enrols a mentor in all courses defined in 'included_course_ids' setting.
     *
     * Called when a new subscription is activated (checkout.session.completed /
     * fulfill_checkout_session).
     *
     * @param int $mentorid Mentor user ID.
     * @return void
     */
    /**
     * Append a structured line to the plugin's debug log file.
     *
     * Writes to enrol/mentorsubscription/checkout_debug.log so errors that
     * happen inside background contexts (webhook, cron) are visible without
     * requiring Moodle's developer debug mode to be active.
     *
     * @param string $step  Short label for the log entry.
     * @param array  $data  Data to serialise as JSON.
     */
    private function file_log(string $step, array $data = []): void {
        $logfile = dirname(__DIR__, 2) . '/checkout_debug.log';
        $line    = '[' . date('Y-m-d H:i:s') . '] [ENROL_SYNC/' . $step . '] ' .
                   json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);
    }

    public function enrol_mentor(int $mentorid): void {
        $courseids = $this->get_course_ids();

        $this->file_log('ENROL_MENTOR_START', [
            'mentorid'  => $mentorid,
            'courseids' => $courseids,
        ]);

        if (empty($courseids)) {
            $msg = 'enrol_mentorsubscription: no courses configured in included_course_ids — mentor not enrolled.';
            debugging($msg, DEBUG_DEVELOPER);
            $this->file_log('ENROL_MENTOR_NO_COURSES', ['mentorid' => $mentorid]);
            return;
        }

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courseids as $courseid) {
            try {
                $this->file_log('ENROL_MENTOR_BEFORE_CALL', [
                    'mentorid' => $mentorid,
                    'courseid' => $courseid,
                ]);
                $plugin->enrol_mentor($mentorid, $courseid);
                $this->file_log('ENROL_MENTOR_SUCCESS', [
                    'mentorid' => $mentorid,
                    'courseid' => $courseid,
                ]);
            } catch (\Throwable $e) {
                $this->file_log('ENROL_MENTOR_ERROR', [
                    'mentorid' => $mentorid,
                    'courseid' => $courseid,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);
                debugging(
                    "enrol_mentorsubscription: failed to enrol mentor {$mentorid} " .
                    "in course {$courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        $this->file_log('ENROL_MENTOR_END', ['mentorid' => $mentorid]);
    }

    /**
     * Unenrols a mentor from all courses defined in 'included_course_ids' setting.
     *
     * Called when a subscription is cancelled or expires.
     *
     * @param int $mentorid Mentor user ID.
     * @return void
     */
    public function unenrol_mentor(int $mentorid): void {
        $courseids = $this->get_course_ids();

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courseids as $courseid) {
            try {
                $plugin->unenrol_mentor($mentorid, $courseid);
            } catch (\Throwable $e) {
                debugging(
                    "enrol_mentorsubscription: failed to unenrol mentor {$mentorid} " .
                    "from course {$courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }
}
