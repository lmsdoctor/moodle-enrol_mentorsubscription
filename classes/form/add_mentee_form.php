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
 * Moodleform: add_mentee_form
 *
 * Presented in the mentor dashboard to search and confirm a new mentee.
 * Uses autocomplete element to search Moodle users by name/email.
 *
 * Full implementation: M-4.5
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for adding a new mentee to a mentor's list.
 */
class add_mentee_form extends \moodleform {

    /**
     * Define form elements.
     */
    public function definition(): void {
        $mform = $this->_form;

        // Autocomplete element backed by the standard Moodle user selector.
        // Searches all non-deleted, non-guest users by name or email.
        $options = [
            'ajax'        => 'core_user/form_user_selector',
            'multiple'    => false,
            'noselectionstring' => get_string('mentee_search', 'enrol_mentorsubscription'),
            'valuehtmlcallback' => static function($userid) {
                $user = \core_user::get_user($userid);
                return $user ? fullname($user) : '';
            },
        ];

        $mform->addElement(
            'autocomplete',
            'menteeid',
            get_string('mentee_search', 'enrol_mentorsubscription'),
            [],
            $options
        );
        $mform->setType('menteeid', PARAM_INT);
        $mform->addRule('menteeid', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('dashboard_add_mentee', 'enrol_mentorsubscription'));
    }

    /**
     * Server-side validation.
     *
     * @param array $data  Submitted form data.
     * @param array $files Uploaded files (unused).
     * @return array Validation errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($data['menteeid'])) {
            $errors['menteeid'] = get_string('error_mentee_not_found', 'enrol_mentorsubscription');
        }

        return $errors;
    }
}
