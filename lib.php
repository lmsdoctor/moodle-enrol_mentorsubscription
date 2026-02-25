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
 * Mentor Subscription enrolment plugin main library.
 *
 * Implements the enrol_plugin API so Moodle recognises this plugin as
 * an enrolment method and makes it available in course enrolment settings.
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Mentor Subscription enrolment plugin class.
 *
 * All enrolment plugins must extend enrol_plugin and be named
 * enrol_{pluginname}_plugin.
 */
class enrol_mentorsubscription_plugin extends enrol_plugin {

    // -------------------------------------------------------------------------
    // enrol_plugin API — required overrides
    // -------------------------------------------------------------------------

    /**
     * Returns the plugin name for display in the UI.
     *
     * @return string Human-readable plugin name.
     */
    public function get_name(): string {
        return 'mentorsubscription';
    }

    /**
     * This plugin does not allow manual enrolment from the standard course
     * enrolment manager. Access is controlled exclusively through the mentor
     * dashboard and the Stripe subscription flow.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_enrol(stdClass $instance): bool {
        return false;
    }

    /**
     * Unenrolment is handled programmatically by mentorship_manager and the
     * Stripe webhook processor. Disable the standard UI unenrol button.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_unenrol(stdClass $instance): bool {
        return false;
    }

    /**
     * Managers should not be able to edit instances from the standard enrolment
     * manager; configuration lives in plugin settings and the admin panel.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_manage(stdClass $instance): bool {
        return false;
    }

    /**
     * Prevent showing up in the standard "Add enrolment method" dropdown on a
     * per-course basis. This plugin is applied globally at system level.
     *
     * @return bool
     */
    public function can_add_instance($courseid): bool {
        return false;
    }

    // -------------------------------------------------------------------------
    // Plugin-specific helpers
    // -------------------------------------------------------------------------

    /**
     * Returns or creates the single enrolment instance for a given course.
     *
     * The plugin manages one shared instance per course (created automatically
     * when a mentee is first enrolled into that course).
     *
     * @param int $courseid Moodle course ID.
     * @return stdClass Enrolment instance record.
     */
    public function get_or_create_instance(int $courseid): stdClass {
        global $DB;

        $instance = $DB->get_record('enrol', [
            'courseid'  => $courseid,
            'enrol'     => $this->get_name(),
            'status'    => ENROL_INSTANCE_ENABLED,
        ]);

        if (!$instance) {
            $instanceid = $this->add_instance(get_course($courseid));
            $instance   = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }

        return $instance;
    }

    /**
     * Enrols a mentee into a course using this plugin's instance.
     *
     * Thin wrapper around enrol_user() for use by enrolment_sync.
     *
     * @param int $menteeid  Moodle user ID of the mentee.
     * @param int $courseid  Moodle course ID.
     * @param int $roleid    Role to assign (defaults to the configured student role).
     * @return void
     */
    public function enrol_mentee(int $menteeid, int $courseid, int $roleid = 0): void {
        if ($roleid === 0) {
            $roleid = (int) get_config('enrol_mentorsubscription', 'studentroleid');
        }

        $instance = $this->get_or_create_instance($courseid);
        $this->enrol_user($instance, $menteeid, $roleid);
    }

    /**
     * Unenrols a mentee from a course managed by this plugin.
     *
     * Only touches enrolments created by this plugin; does not affect
     * enrolments from other methods (manual, cohort sync, etc.).
     *
     * @param int $menteeid  Moodle user ID of the mentee.
     * @param int $courseid  Moodle course ID.
     * @return void
     */
    public function unenrol_mentee(int $menteeid, int $courseid): void {
        global $DB;

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol'    => $this->get_name(),
            'status'   => ENROL_INSTANCE_ENABLED,
        ]);

        if ($instance) {
            $this->unenrol_user($instance, $menteeid);
        }
    }

    /**
     * Enrols a mentor into a course using this plugin's instance.
     *
     * Uses the configured mentorroleid (e.g. Non-editing teacher).
     *
     * @param int $mentorid  Moodle user ID of the mentor.
     * @param int $courseid  Moodle course ID.
     * @param int $roleid    Role to assign (defaults to the configured mentor role).
     * @return void
     */
    public function enrol_mentor(int $mentorid, int $courseid, int $roleid = 0): void {
        if ($roleid === 0) {
            $roleid = (int) get_config('enrol_mentorsubscription', 'mentorroleid');
        }

        $instance = $this->get_or_create_instance($courseid);
        $this->enrol_user($instance, $mentorid, $roleid);
    }

    /**
     * Unenrols a mentor from a course managed by this plugin.
     *
     * Only touches enrolments created by this plugin.
     *
     * @param int $mentorid  Moodle user ID of the mentor.
     * @param int $courseid  Moodle course ID.
     * @return void
     */
    public function unenrol_mentor(int $mentorid, int $courseid): void {
        global $DB;

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol'    => $this->get_name(),
            'status'   => ENROL_INSTANCE_ENABLED,
        ]);

        if ($instance) {
            $this->unenrol_user($instance, $mentorid);
        }
    }
}
