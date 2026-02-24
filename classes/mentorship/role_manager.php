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
 * Role Manager — programmatic Parent Role creation and assignment.
 *
 * Manages the Moodle "parent" role in CONTEXT_USER so mentors can
 * view mentee grades and profile information natively.
 *
 * Key design decisions:
 *   - ensure_parent_role_exists() is idempotent — safe to call multiple times.
 *   - Roles are restricted to CONTEXT_USER via set_role_contextlevels().
 *   - assign_mentor_as_parent() is also idempotent (role_assign() handles duplicates).
 *
 * Full implementation: M-1
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\mentorship;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages Parent Role creation and assignment for mentors.
 */
class role_manager {

    /** Shortname for the parent role. */
    const PARENT_ROLE_SHORTNAME = 'parent';

    /**
     * Ensures the "parent" role exists in Moodle with correct configuration.
     *
     * Idempotent: if the role already exists, it is returned without changes.
     * Restricts context level to CONTEXT_USER only.
     * Assigns required capabilities: viewdetails, viewalldetails, grade views.
     *
     * Full implementation: M-1.1 to M-1.3
     *
     * @return int Role ID.
     */
    public function ensure_parent_role_exists(): int {
        global $DB;

        // TODO M-1.1: create_role() if not exists; set contextlevels; assign capabilities.
        $role = $DB->get_record('role', ['shortname' => self::PARENT_ROLE_SHORTNAME]);
        if ($role) {
            return (int) $role->id;
        }

        throw new \coding_exception('ensure_parent_role_exists() not yet implemented — scheduled for M-1.1.');
    }

    /**
     * Assigns the mentor as "parent" in the mentee's user context.
     *
     * Idempotent: role_assign() in Moodle does not duplicate existing assignments.
     *
     * Full implementation: M-1.4
     *
     * @param int $mentorid Mentor user ID.
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function assign_mentor_as_parent(int $mentorid, int $menteeid): void {
        // TODO M-1.4: role_assign($roleid, $mentorid, context_user::instance($menteeid)->id).
        throw new \coding_exception('assign_mentor_as_parent() not yet implemented — scheduled for M-1.4.');
    }

    /**
     * Removes the mentor's parent role assignment from the mentee's user context.
     *
     * Safe to call even if the assignment does not exist.
     *
     * Full implementation: M-1.5
     *
     * @param int $mentorid Mentor user ID.
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function unassign_mentor_as_parent(int $mentorid, int $menteeid): void {
        // TODO M-1.5: role_unassign($roleid, $mentorid, context_user::instance($menteeid)->id).
        throw new \coding_exception('unassign_mentor_as_parent() not yet implemented — scheduled for M-1.5.');
    }
}
