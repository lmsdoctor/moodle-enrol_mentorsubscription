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
 * PHPUnit tests for enrolment_sync.
 *
 * Covers: enrol_mentee (creates enrolment in config courses),
 *         unenrol_mentee (removes enrolment only from plugin-managed courses),
 *         no side effect on courses not belonging to the plugin.
 *
 * M-6.7
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

use enrol_mentorsubscription\mentorship\enrolment_sync;
use advanced_testcase;

/**
 * Tests for enrolment_sync.
 *
 * @covers \enrol_mentorsubscription\mentorship\enrolment_sync
 */
class enrolment_sync_test extends advanced_testcase {

    /** @var enrolment_sync SUT */
    private enrolment_sync $sync;

    /** @var \stdClass Test mentee. */
    private \stdClass $mentee;

    /** @var \stdClass Subscription course managed by the plugin. */
    private \stdClass $course;

    /** @var \stdClass Unrelated course NOT managed by the plugin. */
    private \stdClass $otherCourse;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->sync      = new enrolment_sync();
        $this->mentee    = $this->getDataGenerator()->create_user();
        $this->course     = $this->getDataGenerator()->create_course();
        $this->otherCourse = $this->getDataGenerator()->create_course();

        // Register the plugin course in enrol_mentorsub_courses.
        $DB->insert_record('enrol_mentorsub_courses', (object) [
            'courseid'  => $this->course->id,
            'sortorder' => 0,
        ]);

        // Ensure the enrol_mentorsubscription instance exists in the plugin course.
        // (enrolment_sync::enrol_mentee creates it if needed — we trust that here.)
    }

    // -------------------------------------------------------------------------
    // enrol_mentee
    // -------------------------------------------------------------------------

    /**
     * After enrol_mentee, the user is enrolled in the plugin-managed course.
     *
     * M-3.5
     */
    public function test_enrol_mentee_enrolls_in_plugin_course(): void {
        $this->sync->enrol_mentee($this->mentee->id);

        $enrolled = is_enrolled(
            \context_course::instance($this->course->id),
            $this->mentee->id
        );
        $this->assertTrue($enrolled);
    }

    /**
     * enrol_mentee does NOT enrol user in courses not in enrol_mentorsub_courses.
     */
    public function test_enrol_mentee_does_not_touch_unmanaged_courses(): void {
        $this->sync->enrol_mentee($this->mentee->id);

        $enrolled = is_enrolled(
            \context_course::instance($this->otherCourse->id),
            $this->mentee->id
        );
        $this->assertFalse($enrolled);
    }

    /**
     * Calling enrol_mentee twice is safe (no duplicate enrolment error).
     */
    public function test_enrol_mentee_is_idempotent(): void {
        $this->sync->enrol_mentee($this->mentee->id);
        $this->sync->enrol_mentee($this->mentee->id); // second call

        $enrolled = is_enrolled(
            \context_course::instance($this->course->id),
            $this->mentee->id
        );
        $this->assertTrue($enrolled); // still enrolled, no exception
    }

    // -------------------------------------------------------------------------
    // unenrol_mentee
    // -------------------------------------------------------------------------

    /**
     * After unenrol_mentee, the user is no longer enrolled in the plugin course.
     *
     * M-3.6
     */
    public function test_unenrol_mentee_removes_enrolment_from_plugin_course(): void {
        $this->sync->enrol_mentee($this->mentee->id);
        $this->sync->unenrol_mentee($this->mentee->id);

        $enrolled = is_enrolled(
            \context_course::instance($this->course->id),
            $this->mentee->id
        );
        $this->assertFalse($enrolled);
    }

    /**
     * unenrol_mentee does NOT remove enrolments from courses not managed by the plugin.
     *
     * M-3.6
     */
    public function test_unenrol_mentee_does_not_touch_unmanaged_courses(): void {
        // Manually enrol in unmanaged course via manual enrolment.
        $this->getDataGenerator()->enrol_user($this->mentee->id, $this->otherCourse->id);

        // Enrol + unenrol via plugin (only affects plugin course).
        $this->sync->enrol_mentee($this->mentee->id);
        $this->sync->unenrol_mentee($this->mentee->id);

        // Unmanaged enrolment should be intact.
        $enrolled = is_enrolled(
            \context_course::instance($this->otherCourse->id),
            $this->mentee->id
        );
        $this->assertTrue($enrolled);
    }

    /**
     * Calling unenrol_mentee when not enrolled does NOT throw.
     */
    public function test_unenrol_mentee_is_safe_when_not_enrolled(): void {
        $this->sync->unenrol_mentee($this->mentee->id);
        $this->assertTrue(true); // No exception = pass.
    }
}
