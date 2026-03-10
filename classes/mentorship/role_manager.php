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

    /** Shortname of the custom profile field that holds the user's subscription plan. */
    const PLAN_PROFILE_FIELD_SHORTNAME = 'plan_profile_field_name';

    /**
     * Capabilities to assign to the parent role.
     * All granted at CONTEXT_USER level.
     */
    private const PARENT_ROLE_CAPABILITIES = [
        'moodle/user:viewdetails',
        'moodle/user:viewalldetails',
        'moodle/user:viewprofilepictures',
        'moodle/user:readuserposts',
        'moodle/user:readuserblogs',
        'moodle/user:viewuseractivitiesreport',
        'moodle/user:editownprofile',
        'moodle/user:editownmessageprofile',
        'moodle/user:changeownpassword',
        'moodle/grade:view',
        'moodle/grade:viewall',
        'moodle/grade:viewhidden',
        'moodle/grade:export'
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
            ''                                               // archetype
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
     * Checks whether a user holds the parent role in any CONTEXT_USER.
     *
     * Uses a direct DB lookup instead of ensure_parent_role_exists() to avoid
     * creating the role as a side-effect of a read-only check.
     *
     * @param int $userid User to check. Defaults to the current session user (0).
     * @return bool True if the user has at least one parent-role assignment.
     */
    public function user_has_parent_role(int $userid = 0): bool {
        global $DB, $USER;

        $userid = $userid > 0 ? $userid : (int) $USER->id;

        $role = $DB->get_record('role', ['shortname' => self::PARENT_ROLE_SHORTNAME]);
        if (!$role) {
            return false;
        }

        return $DB->record_exists('role_assignments', [
            'userid'  => $userid,
            'roleid'  => (int) $role->id,
        ]);
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

    /**
     * Returns the value the user has selected in the 'plan_profile_field_name'
     * custom profile field, or an empty string if not set / field not found.
     *
     * @param int $userid  User ID (0 = current user).
     * @return string Selected option value, e.g. 'Mentor Plan', or ''.
     */
    public function get_plan_profile_field_value(int $userid = 0): string {
        global $DB, $USER;

        $userid = $userid > 0 ? $userid : (int) $USER->id;

        $field = $DB->get_record('user_info_field', ['shortname' => self::PLAN_PROFILE_FIELD_SHORTNAME]);
        if (!$field) {
            return '';
        }

        $data = $DB->get_record('user_info_data', [
            'userid'  => $userid,
            'fieldid' => (int) $field->id,
        ]);

        return $data ? (string) $data->data : '';
    }

    /**
     * Checks whether the user's plan profile field value matches any of the
     * configured mentor values (comma-separated config string).
     *
     * Comparison is case-insensitive and trims surrounding whitespace.
     *
     * @param int    $userid              User ID (0 = current user).
     * @param string $mentorValuesConfig  Raw config string, e.g. "mentor, b2b".
     * @return bool True if the user's value matches any of the allowed mentor values.
     */
    public function valid_plan_profile_field(int $userid = 0, string $mentorValuesConfig = ''): bool {
        $userValue = trim($this->get_plan_profile_field_value($userid));
        if ($userValue === '' || $mentorValuesConfig === '') {
            return false;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower($mentorValuesConfig))));

        return in_array(strtolower($userValue), $allowed, true);
    }

    /**
     * Checks whether the user is a mentor by reading the plan_profile_field_option
     * snapshot stored on their active subscription record.
     *
     * This is an alternative to valid_plan_profile_field() which reads from the
     * user profile. Use this method when you want to base the check on what the
     * user actually paid for, regardless of any profile edits.
     *
     * @param int    $userid              User ID (0 = current user).
     * @param string $mentorValuesConfig  Comma-separated mentor values, e.g. "mentor, b2b".
     * @return bool True if the active subscription's plan_profile_field_option
     *              matches any of the configured mentor values.
     */
    public function valid_plan_profile_field_from_subscription(int $userid = 0, string $mentorValuesConfig = ''): bool {
        global $DB, $USER;

        $userid = $userid > 0 ? $userid : (int) $USER->id;

        if ($mentorValuesConfig === '') {
            return false;
        }

        $subValue = $DB->get_field_select(
            'enrol_mentorsub_subscriptions',
            'plan_profile_field_option',
            "userid = :uid AND status IN ('active', 'paused') ORDER BY id DESC",
            ['uid' => $userid],
            IGNORE_MISSING
        );

        if ($subValue === false || $subValue === null || trim($subValue) === '') {
            return false;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower($mentorValuesConfig))));

        return in_array(strtolower(trim($subValue)), $allowed, true);
    }

    /**
     * Sets (or clears) the user's plan profile field value.
     *
     * Only runs when:
     *   - The 'enable_plan_profile_field' plugin setting is ON.
     *   - $planOption is a non-empty string that matches one of the configured
     *     options in 'plan_profile_field_options'.
     *
     * Idempotent: safe to call on every activation / renewal.
     *
     * @param int    $userid      User ID.
     * @param string $planOption  Value to store (e.g. 'mentor'). Pass '' to clear.
     * @return void
     */
    public function sync_plan_profile_to_user(int $userid, string $planOption): void {
        global $DB;

        // Feature disabled — nothing to do.
        if (!get_config('enrol_mentorsubscription', 'enable_plan_profile_field')) {
            return;
        }

        $field = $DB->get_record('user_info_field', ['shortname' => self::PLAN_PROFILE_FIELD_SHORTNAME]);
        if (!$field) {
            return;
        }

        // Validate that the option is among the configured choices.
        if ($planOption !== '') {
            $rawOptions = get_config('enrol_mentorsubscription', 'plan_profile_field_options');
            $validOptions = array_filter(array_map('trim', explode("\n", (string) $rawOptions)));
            $validLower   = array_map('strtolower', $validOptions);
            if (!in_array(strtolower($planOption), $validLower, true)) {
                debugging(
                    "enrol_mentorsubscription: plan_profile_field_option '{$planOption}' " .
                    "is not a valid option — profile field not updated for user {$userid}.",
                    DEBUG_DEVELOPER
                );
                return;
            }
        }

        $existing = $DB->get_record('user_info_data', [
            'userid'  => $userid,
            'fieldid' => (int) $field->id,
        ]);

        if ($existing) {
            $DB->update_record('user_info_data', (object) [
                'id'   => $existing->id,
                'data' => $planOption,
            ]);
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid'  => $userid,
                'fieldid' => (int) $field->id,
                'data'    => $planOption,
                'dataformat' => 0,
            ]);
        }
    }
}
