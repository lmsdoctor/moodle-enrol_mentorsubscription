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

use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages Parent Role creation and assignment for mentors.
 */
class role_manager {

    /** Shortname for the parent role. */
    const PARENT_ROLE_SHORTNAME = 'parent';

    /**
     * Capabilities to assign to the parent role.
     * All granted at CONTEXT_USER level.
     */
    private const PARENT_ROLE_CAPABILITIES = [
        'moodle/user:viewdetails',
        'moodle/user:viewalldetails',
        'moodle/user:viewhiddendetails',
        'gradereport/user:view',
        'moodle/grade:viewall',
    ];

    /**
     * Ensures the "parent" role exists in Moodle with correct configuration.
     *
     * Idempotent: if the role already exists it is returned without modification.
     * On creation:
     *   1. create_role() with shortname 'parent'                         (M-1.1)
     *   2. set_role_contextlevels() — restricts to CONTEXT_USER only     (M-1.2)
     *   3. assign_capability() for each required capability              (M-1.3)
     *
     * @return int Role ID.
     */
    public function ensure_parent_role_exists(): int {
        global $DB;

        // --- M-1.1: Return existing role — idempotent. -------------------
        $role = $DB->get_record('role', ['shortname' => self::PARENT_ROLE_SHORTNAME]);
        if ($role) {
            return (int) $role->id;
        }

        // Create the role.
        $roleid = create_role(
            get_string('parentrole', 'enrol_mentorsubscription'),  // full name
            self::PARENT_ROLE_SHORTNAME,                            // shortname
            get_string('parentrole_desc', 'enrol_mentorsubscription'), // description
            'teacher'                                               // archetype
        );

        if (!$roleid) {
            throw new moodle_exception('cannotcreateparentrole', 'enrol_mentorsubscription');
        }

        // --- M-1.2: Restrict to CONTEXT_USER only. -----------------------
        set_role_contextlevels($roleid, [CONTEXT_USER]);

        // --- M-1.3: Assign required capabilities. ------------------------
        $systemcontext = \context_system::instance();
        foreach (self::PARENT_ROLE_CAPABILITIES as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }

        return (int) $roleid;
    }

    /**
     * Assigns the mentor as "parent" in the mentee's CONTEXT_USER.
     *
     * Idempotent — role_assign() in Moodle silently ignores duplicate
     * assignments, so calling this method twice is safe.
     *
     * Full implementation: M-1.4
     *
     * @param int $mentorid Mentor user ID (the principal being assigned the role).
     * @param int $menteeid Mentee user ID (whose context is used).
     * @return void
     */
    public function assign_mentor_as_parent(int $mentorid, int $menteeid): void {
        $roleid  = $this->ensure_parent_role_exists();
        $context = \context_user::instance($menteeid);
        role_assign($roleid, $mentorid, $context->id);
    }

    /**
     * Removes the mentor's parent role from the mentee's CONTEXT_USER.
     *
     * Safe to call even when the assignment no longer exists —
     * role_unassign() in Moodle is a no-op in that case.
     *
     * Full implementation: M-1.5
     *
     * @param int $mentorid Mentor user ID.
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function unassign_mentor_as_parent(int $mentorid, int $menteeid): void {
        $roleid  = $this->ensure_parent_role_exists();
        $context = \context_user::instance($menteeid);
        role_unassign($roleid, $mentorid, $context->id);
    }
}
