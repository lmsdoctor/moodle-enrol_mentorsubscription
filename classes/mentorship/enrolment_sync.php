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
 * Enrolment Sync — course enrolment/unenrolment for mentees.
 *
 * Wraps the enrol_mentorsubscription_plugin enrol/unenrol methods to
 * provide batch operations across all subscription courses.
 *
 * Critical design rule: only touches enrolments created by THIS plugin.
 * Never removes enrolments from other methods (manual, cohort, etc.).
 *
 * Full implementation: M-3.5 and M-3.6
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\mentorship;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles course enrolment synchronisation for mentees.
 */
class enrolment_sync {

    /**
     * Enrols a mentee in all courses managed by this plugin.
     *
     * Fetches the list from enrol_mentorsub_courses and calls
     * enrol_mentorsubscription_plugin::enrol_mentee() for each.
     *
     * Full implementation: M-3.5
     *
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function enrol_mentee(int $menteeid): void {
        global $DB;

        $courses = $DB->get_records('enrol_mentorsub_courses', [], 'sortorder ASC');

        if (empty($courses)) {
            debugging('enrol_mentorsubscription: no courses configured for enrolment.', DEBUG_DEVELOPER);
            return;
        }

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courses as $course) {
            try {
                $plugin->enrol_mentee($menteeid, (int) $course->courseid);
            } catch (\Throwable $e) {
                // Log but do not abort — attempt all courses.
                debugging(
                    "enrol_mentorsubscription: failed to enrol mentee {$menteeid} " .
                    "in course {$course->courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * Unenrols a mentee from all courses managed by this plugin.
     *
     * Only removes enrolments whose method is 'mentorsubscription'.
     * Does not touch manual, self-enrol or cohort enrolments.
     *
     * Full implementation: M-3.6
     *
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function unenrol_mentee(int $menteeid): void {
        global $DB;

        $courses = $DB->get_records('enrol_mentorsub_courses', []);

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courses as $course) {
            try {
                $plugin->unenrol_mentee($menteeid, (int) $course->courseid);
            } catch (\Throwable $e) {
                debugging(
                    "enrol_mentorsubscription: failed to unenrol mentee {$menteeid} " .
                    "from course {$course->courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * Enrols a mentor in all courses managed by this plugin.
     *
     * Called when a new subscription is activated (checkout.session.completed).
     *
     * @param int $mentorid Mentor user ID.
     * @return void
     */
    public function enrol_mentor(int $mentorid): void {
        global $DB;

        $courses = $DB->get_records('enrol_mentorsub_courses', [], 'sortorder ASC');

        if (empty($courses)) {
            return;
        }

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courses as $course) {
            try {
                $plugin->enrol_mentor($mentorid, (int) $course->courseid);
            } catch (\Throwable $e) {
                debugging(
                    "enrol_mentorsubscription: failed to enrol mentor {$mentorid} " .
                    "in course {$course->courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * Unenrols a mentor from all courses managed by this plugin.
     *
     * Called when a subscription is cancelled or expires.
     *
     * @param int $mentorid Mentor user ID.
     * @return void
     */
    public function unenrol_mentor(int $mentorid): void {
        global $DB;

        $courses = $DB->get_records('enrol_mentorsub_courses', []);

        /** @var \enrol_mentorsubscription_plugin $plugin */
        $plugin = enrol_get_plugin('mentorsubscription');

        foreach ($courses as $course) {
            try {
                $plugin->unenrol_mentor($mentorid, (int) $course->courseid);
            } catch (\Throwable $e) {
                debugging(
                    "enrol_mentorsubscription: failed to unenrol mentor {$mentorid} " .
                    "from course {$course->courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }
}
