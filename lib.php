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
     * Roles are NOT protected — users with role-assign capability can adjust
     * roles on enrolments created by this plugin without restriction.
     *
     * @return bool
     */
    public function roles_protected(): bool {
        return false;
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
     * Stripe webhook processor. Disable the standard UI unenrol button to
     * prevent bypassing our enrol_mentorsub_mentees sync logic.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_unenrol(stdClass $instance): bool {
        return false;
    }

    /**
     * Allow admins to enable/disable this plugin's instance from the standard
     * course enrolment methods UI (e.g. to temporarily suspend access).
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_manage(stdClass $instance): bool {
        return true;
    }

    /**
     * Never show the "Enrol me" self-enrolment link.
     * Users subscribe exclusively through the Stripe checkout flow.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function show_enrolme_link(stdClass $instance): bool {
        return false;
    }

    /**
     * Allow site administrators to add this plugin instance to any course.
     *
     * Requires enrol/mentorsubscription:config capability (course context).
     *
     * @param int $courseid Moodle course ID.
     * @return bool True when the current user can add an instance.
     */
    public function can_add_instance($courseid): bool {
        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('enrol/mentorsubscription:config', $context);
    }

    /**
     * Enable the Standard Editing UI so this plugin appears in the
     * "Add enrolment method" dropdown on each course.
     *
     * Required by Moodle enrolment plugin API.
     * @see https://moodledev.io/docs/5.0/apis/plugintypes/enrol#standard-editing-ui
     *
     * @return bool
     */
    public function use_standard_editing_ui(): bool {
        return true;
    }

    /**
     * Whether the hide/show toggle is available for this plugin's instances.
     *
     * Returning true shows the 👁 icon in the course enrolment methods table,
     * letting admins enable/disable the instance without deleting it.
     *
     * @param stdClass $instance Enrolment instance record.
     * @return bool
     */
    public function can_hide_show_instance($instance): bool {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/mentorsubscription:config', $context);
    }

    /**
     * Build the add/edit instance form.
     *
     * This plugin has no per-instance configuration — all settings live at
     * the plugin level. The form only exposes a status toggle so admins can
     * enable or disable this instance without deleting it.
     *
     * @param object          $instance Enrolment instance or empty object for new.
     * @param MoodleQuickForm $mform    Form object.
     * @param context         $context  Course context.
     * @return void
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context): void {
        $options = [
            ENROL_INSTANCE_ENABLED  => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no'),
        ];
        $mform->addElement('select', 'status',
            get_string('status', 'enrol_mentorsubscription'), $options);
        $mform->setDefault('status', ENROL_INSTANCE_ENABLED);
        $mform->addHelpButton('status', 'status', 'enrol_mentorsubscription');
    }

    /**
     * Validate the add/edit instance form data.
     *
     * No custom validation needed — status is a fixed select.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance data loaded from the DB.
     * @param context $context The context of the instance we are editing
     * @return array  Validation errors keyed by field name (empty = valid).
     */
    public function edit_instance_validation($data, $files, $instance, $context): array {
        return [];
    }

    /**
     * Returns a human-readable name for a given enrolment instance.
     *
     * Shown in the course enrolment methods table.
     *
     * @param stdClass $instance Enrolment instance record.
     * @return string
     */
    public function get_instance_name($instance): string {
        return get_string('pluginname', 'enrol_mentorsubscription');
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
