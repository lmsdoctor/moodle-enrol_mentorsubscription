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
 * Moodleform: create_mentee_form
 *
 * Used in dashboard/mentee.php (mode=create) to register a brand-new Moodle
 * user and immediately assign them as a mentee of the authenticated mentor.
 *
 * A temporary password is auto-generated and e-mailed to the new user via
 * Moodle's standard setnew_password_and_mail() flow.
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating a new Moodle user to be assigned as the mentor's mentee.
 */
class create_mentee_form extends \moodleform {

    /**
     * Define form elements.
     */
    public function definition(): void {
        $mform = $this->_form;

        // ---------- Personal data ----------------------------------------
        $mform->addElement('text', 'firstname',
            get_string('firstname'), ['class' => 'form-control']);
        $mform->setType('firstname', PARAM_NOTAGS);
        $mform->addRule('firstname',
            get_string('missingfirstname'), 'required', null, 'client');

        $mform->addElement('text', 'lastname',
            get_string('lastname'), ['class' => 'form-control']);
        $mform->setType('lastname', PARAM_NOTAGS);
        $mform->addRule('lastname',
            get_string('missinglastname'), 'required', null, 'client');

        $mform->addElement('text', 'email',
            get_string('email'), ['class' => 'form-control']);
        $mform->setType('email', PARAM_RAW_TRIMMED);
        $mform->addRule('email',
            get_string('missingemail'), 'required', null, 'client');
        $mform->addRule('email',
            get_string('invalidemail'), 'email', null, 'client');

        // ---------- Password note ----------------------------------------
        $mform->addElement('static', 'password_note', '',
            '<div class="alert alert-info py-2 small mb-0">' .
            get_string('mentee_create_password_note', 'enrol_mentorsubscription') .
            '</div>');

        // ---------- Submit / Cancel --------------------------------------
        $this->add_action_buttons(
            true,
            get_string('mentee_create_submit', 'enrol_mentorsubscription')
        );
    }

    /**
     * Server-side validation: ensure the email is not already in use.
     *
     * @param array $data  Submitted form data.
     * @param array $files Uploaded files (unused).
     * @return array Associative array of field => error message.
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        $email = trim($data['email'] ?? '');

        if ($email !== '' && !validate_email($email)) {
            $errors['email'] = get_string('invalidemail');
        } elseif ($email !== '' && $DB->record_exists_select(
                'user', 'LOWER(email) = LOWER(:email) AND deleted = 0', ['email' => $email])) {
            $errors['email'] = get_string('mentee_create_email_exists', 'enrol_mentorsubscription');
        }

        return $errors;
    }
}
