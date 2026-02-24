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
 * Mentorship Manager — CRUD for mentor-mentee relationships.
 *
 * Handles: add_mentee(), toggle_mentee_status() and removal logic.
 * Enforces: subscription validation, mentee limit, system-wide uniqueness.
 *
 * Full implementation: M-3
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\mentorship;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages the mentor-mentee relationship lifecycle.
 */
class mentorship_manager {

    /**
     * Adds a new mentee to a mentor's list.
     *
     * Validation chain (all must pass):
     *   1. Mentor has an active subscription.
     *   2. Active mentee count < billed_max_mentees.
     *   3. Mentee user exists in Moodle.
     *   4. UNIQUE(menteeid) not violated.
     *
     * On success: INSERT mentee record + role_assign() + enrol_user() in transaction.
     * On success: dispatches mentee_enrolled event.
     *
     * Full implementation: M-3.1
     *
     * @param int $mentorid  Mentor user ID.
     * @param int $menteeid  Mentee user ID.
     * @return \stdClass Newly created mentee record.
     * @throws \moodle_exception On validation failure.
     */
    public function add_mentee(int $mentorid, int $menteeid): \stdClass {
        // TODO M-3.1: Full implementation.
        throw new \coding_exception('add_mentee() not yet implemented — scheduled for M-3.1.');
    }

    /**
     * Toggles a mentee's active/inactive state.
     *
     * Deactivation: always allowed — unenrolls from all plugin courses.
     * Activation:   validates limit first — re-enrols from all plugin courses.
     *
     * Full implementation: M-3.4
     *
     * @param int $mentorid  Mentor user ID.
     * @param int $menteeid  Mentee user ID.
     * @param int $isActive  1 = activate, 0 = deactivate.
     * @return array {bool success, string reason, int limit, int active}
     */
    public function toggle_mentee_status(int $mentorid, int $menteeid, int $isActive): array {
        // TODO M-3.4: Full implementation.
        throw new \coding_exception('toggle_mentee_status() not yet implemented — scheduled for M-3.4.');
    }

    /**
     * Returns the count of currently active mentees for a mentor.
     *
     * Uses INDEX(mentorid, is_active) — O(log n), no full table scan.
     *
     * @param int $mentorid Mentor user ID.
     * @return int Count of active mentees.
     */
    public function count_active_mentees(int $mentorid): int {
        global $DB;
        return (int) $DB->count_records('enrol_mentorsub_mentees', [
            'mentorid'  => $mentorid,
            'is_active' => 1,
        ]);
    }

    /**
     * Returns all mentees (active and inactive) for a mentor.
     *
     * @param int $mentorid Mentor user ID.
     * @return array Array of mentee records joined with user data.
     */
    public function get_mentees(int $mentorid): array {
        global $DB;

        $sql = "SELECT m.*, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                  FROM {enrol_mentorsub_mentees} m
                  JOIN {user} u ON u.id = m.menteeid
                 WHERE m.mentorid = :mentorid
              ORDER BY u.lastname ASC, u.firstname ASC";

        return array_values($DB->get_records_sql($sql, ['mentorid' => $mentorid]));
    }
}
