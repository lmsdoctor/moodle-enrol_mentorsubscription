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
 * PHPUnit tests for role_manager.
 *
 * Covers: ensure_parent_role_exists (idempotency), assign_mentor_as_parent,
 *         unassign_mentor_as_parent, idempotent double-assign.
 *
 * M-6.7
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

use enrol_mentorsubscription\mentorship\role_manager;
use advanced_testcase;

/**
 * Tests for role_manager.
 *
 * @covers \enrol_mentorsubscription\mentorship\role_manager
 */
class role_manager_test extends advanced_testcase {

    /** @var role_manager SUT */
    private role_manager $manager;

    /** @var \stdClass Test mentor. */
    private \stdClass $mentor;

    /** @var \stdClass Test mentee. */
    private \stdClass $mentee;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = new role_manager();
        $this->mentor  = $this->getDataGenerator()->create_user();
        $this->mentee  = $this->getDataGenerator()->create_user();
    }

    // -------------------------------------------------------------------------
    // ensure_parent_role_exists
    // -------------------------------------------------------------------------

    /**
     * First call creates the parent role and returns a valid role ID.
     */
    public function test_ensure_parent_role_exists_returns_role_id(): void {
        global $DB;
        $roleId = $this->manager->ensure_parent_role_exists();
        $this->assertIsInt($roleId);
        $this->assertGreaterThan(0, $roleId);
        $this->assertTrue($DB->record_exists('role', ['id' => $roleId]));
    }

    /**
     * Second call returns the same role ID — idempotent.
     */
    public function test_ensure_parent_role_exists_is_idempotent(): void {
        $first  = $this->manager->ensure_parent_role_exists();
        $second = $this->manager->ensure_parent_role_exists();
        $this->assertSame($first, $second);
    }

    /**
     * Role shortname matches the defined constant.
     */
    public function test_ensure_parent_role_has_correct_shortname(): void {
        global $DB;
        $roleId = $this->manager->ensure_parent_role_exists();
        $role   = $DB->get_record('role', ['id' => $roleId]);
        $this->assertEquals(role_manager::PARENT_ROLE_SHORTNAME, $role->shortname);
    }

    // -------------------------------------------------------------------------
    // assign_mentor_as_parent
    // -------------------------------------------------------------------------

    /**
     * Assigns the parent role to the mentor in the mentee's context.
     */
    public function test_assign_mentor_as_parent_creates_role_assignment(): void {
        global $DB;
        $this->manager->ensure_parent_role_exists();
        $this->manager->assign_mentor_as_parent($this->mentor->id, $this->mentee->id);

        $ctx    = \context_user::instance($this->mentee->id);
        $roleId = $DB->get_field('role', 'id', ['shortname' => role_manager::PARENT_ROLE_SHORTNAME]);

        $exists = $DB->record_exists('role_assignments', [
            'userid'    => $this->mentor->id,
            'contextid' => $ctx->id,
            'roleid'    => $roleId,
        ]);
        $this->assertTrue($exists);
    }

    /**
     * Calling assign twice does NOT duplicate the role_assignment (idempotent).
     *
     * M-1.6
     */
    public function test_assign_mentor_as_parent_is_idempotent(): void {
        global $DB;
        $this->manager->ensure_parent_role_exists();
        $this->manager->assign_mentor_as_parent($this->mentor->id, $this->mentee->id);
        $this->manager->assign_mentor_as_parent($this->mentor->id, $this->mentee->id);

        $ctx    = \context_user::instance($this->mentee->id);
        $roleId = $DB->get_field('role', 'id', ['shortname' => role_manager::PARENT_ROLE_SHORTNAME]);

        $count = $DB->count_records('role_assignments', [
            'userid'    => $this->mentor->id,
            'contextid' => $ctx->id,
            'roleid'    => $roleId,
        ]);
        $this->assertEquals(1, $count);
    }

    // -------------------------------------------------------------------------
    // unassign_mentor_as_parent
    // -------------------------------------------------------------------------

    /**
     * Removes the parent role assignment after it has been created.
     */
    public function test_unassign_mentor_as_parent_removes_assignment(): void {
        global $DB;
        $this->manager->ensure_parent_role_exists();
        $this->manager->assign_mentor_as_parent($this->mentor->id, $this->mentee->id);
        $this->manager->unassign_mentor_as_parent($this->mentor->id, $this->mentee->id);

        $ctx    = \context_user::instance($this->mentee->id);
        $roleId = $DB->get_field('role', 'id', ['shortname' => role_manager::PARENT_ROLE_SHORTNAME]);

        $exists = $DB->record_exists('role_assignments', [
            'userid'    => $this->mentor->id,
            'contextid' => $ctx->id,
            'roleid'    => $roleId,
        ]);
        $this->assertFalse($exists);
    }

    /**
     * Calling unassign when no assignment exists does NOT throw.
     *
     * M-1.5
     */
    public function test_unassign_mentor_as_parent_is_safe_when_not_assigned(): void {
        $this->manager->ensure_parent_role_exists();
        // No prior assign — should not throw.
        $this->manager->unassign_mentor_as_parent($this->mentor->id, $this->mentee->id);
        $this->assertTrue(true); // No exception = pass.
    }
}
