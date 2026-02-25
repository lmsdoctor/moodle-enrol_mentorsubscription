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
     * Validation chain:
     *   1. Mentor has an active subscription.
     *   2. Active mentee count < billed_max_mentees.
     *   3. Mentee user exists and is not deleted.
     *   4. UNIQUE(menteeid) — mentee has no other mentor.
     *
     * On success: INSERT + role_assign() + enrol in a DB transaction,
     * then dispatches the mentee_enrolled event.
     *
     * M-3.1
     *
     * @param int $mentorid  Mentor user ID.
     * @param int $menteeid  Mentee user ID.
     * @return \stdClass Newly created mentee record.
     * @throws \moodle_exception On any validation failure.
     */
    public function add_mentee(int $mentorid, int $menteeid): \stdClass {
        global $DB;

        // --- 1. Active subscription check. --------------------------------
        $submanager   = new \enrol_mentorsubscription\subscription\subscription_manager();
        $subscription = $submanager->get_active_subscription($mentorid);

        if (!$subscription) {
            throw new \moodle_exception('error_no_active_subscription', 'enrol_mentorsubscription');
        }

        // --- 2. Mentee limit. ---------------------------------------------
        $active = $this->count_active_mentees($mentorid);
        if ($active >= (int) $subscription->billed_max_mentees) {
            throw new \moodle_exception('error_limit_reached', 'enrol_mentorsubscription');
        }

        // --- 3. Mentee user must exist. -----------------------------------
        if (!$DB->record_exists_select('user', 'id = :id AND deleted = 0', ['id' => $menteeid])) {
            throw new \moodle_exception('error_mentee_not_found', 'enrol_mentorsubscription');
        }

        // --- 4. System-wide uniqueness — one mentor per mentee. -----------
        if ($DB->record_exists('enrol_mentorsub_mentees', ['menteeid' => $menteeid])) {
            throw new \moodle_exception('error_mentee_already_assigned', 'enrol_mentorsubscription');
        }

        // --- Atomic insert inside a DB transaction. -----------------------
        $now         = time();
        $transaction = $DB->start_delegated_transaction();

        try {
            $record = (object) [
                'mentorid'       => $mentorid,
                'menteeid'       => $menteeid,
                'subscriptionid' => $subscription->id,
                'is_active'      => 1,
                'timecreated'    => $now,
                'timemodified'   => $now,
            ];

            $record->id = $DB->insert_record('enrol_mentorsub_mentees', $record);

            // Assign Parent Role in mentee's CONTEXT_USER.
            $rolemgr = new role_manager();
            $rolemgr->assign_mentor_as_parent($mentorid, $menteeid);

            $transaction->allow_commit();

        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // --- Enrol in subscription courses (outside transaction). ---------
        $sync = new enrolment_sync();
        $sync->enrol_mentee($menteeid);

        // --- Dispatch event. ----------------------------------------------
        $event = \enrol_mentorsubscription\event\mentee_enrolled::create([
            'context'       => \context_system::instance(),
            'objectid'      => $record->id,
            'relateduserid' => $menteeid,
            'userid'        => $mentorid,
        ]);
        $event->trigger();

        return $record;
    }

    /**
     * Toggles a mentee's active/inactive state.
     *
     * - Deactivation (is_active → 0): always allowed; unenrols immediately.
     * - Activation (is_active → 1): validates limit; re-enrols on success.
     *
     * M-3.4
     *
     * @param int $mentorid  Mentor user ID (ownership check).
     * @param int $menteeid  Mentee user ID.
     * @param int $isActive  1 = activate, 0 = deactivate.
     * @return array ['success' => bool, 'reason' => string, 'limit' => int, 'active' => int]
     */
    public function toggle_mentee_status(int $mentorid, int $menteeid, int $isActive): array {
        global $DB;

        $mentee = $DB->get_record('enrol_mentorsub_mentees',
                                  ['mentorid' => $mentorid, 'menteeid' => $menteeid]);

        if (!$mentee) {
            return ['success' => false, 'reason' => 'notfound', 'limit' => 0, 'active' => 0];
        }

        $sync   = new enrolment_sync();
        $subman = new \enrol_mentorsubscription\subscription\subscription_manager();
        $sub    = $subman->get_active_subscription($mentorid);
        $limit  = $sub ? (int) $sub->billed_max_mentees : 0;

        // --- Deactivation — always allowed. --------------------------------
        if ($isActive === 0) {
            $DB->set_field('enrol_mentorsub_mentees', 'is_active', 0,
                           ['id' => $mentee->id]);
            $DB->set_field('enrol_mentorsub_mentees', 'timemodified', time(),
                           ['id' => $mentee->id]);
            $sync->unenrol_mentee($menteeid);

            $event = \enrol_mentorsubscription\event\mentee_status_changed::create([
                'context'       => \context_system::instance(),
                'objectid'      => $mentee->id,
                'relateduserid' => $menteeid,
                'userid'        => $mentorid,
                'other'         => ['is_active' => 0],
            ]);
            $event->trigger();

            return [
                'success' => true,
                'reason'  => 'deactivated',
                'limit'   => $limit,
                'active'  => $this->count_active_mentees($mentorid),
            ];
        }

        // --- Activation — validate limit first. ---------------------------
        $active = $this->count_active_mentees($mentorid);
        if ($active >= $limit) {
            return [
                'success' => false,
                'reason'  => 'limitreached',
                'limit'   => $limit,
                'active'  => $active,
            ];
        }

        $DB->set_field('enrol_mentorsub_mentees', 'is_active', 1, ['id' => $mentee->id]);
        $DB->set_field('enrol_mentorsub_mentees', 'timemodified', time(), ['id' => $mentee->id]);
        $sync->enrol_mentee($menteeid);

        $event = \enrol_mentorsubscription\event\mentee_status_changed::create([
            'context'       => \context_system::instance(),
            'objectid'      => $mentee->id,
            'relateduserid' => $menteeid,
            'userid'        => $mentorid,
            'other'         => ['is_active' => 1],
        ]);
        $event->trigger();

        return [
            'success' => true,
            'reason'  => 'activated',
            'limit'   => $limit,
            'active'  => $this->count_active_mentees($mentorid),
        ];
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
