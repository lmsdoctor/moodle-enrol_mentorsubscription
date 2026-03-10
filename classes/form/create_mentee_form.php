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

use core_user;

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating a new Moodle user to be assigned as the mentor's mentee.
 */
class create_mentee_form extends \moodleform {

    /**
     * Define form elements.
     */
    public function definition(): void {
        global $USER, $CFG, $SESSION;

        $mform = $this->_form;

        // IOMAD: capture company context from session (set by iomad signup plugin).
        $company = !empty($SESSION->company) ? $SESSION->company : null;

        // ---------- Personal data ----------------------------------------
        $mform->addElement('text', 'firstname', get_string('firstname'));
        $mform->setType('firstname', core_user::get_property_type('firstname'));
        $mform->addRule('firstname', get_string('missingfirstname'), 'required', null, 'client');

        $mform->addElement('text', 'lastname', get_string('lastname'));
        $mform->setType('lastname', core_user::get_property_type('lastname'));
        $mform->addRule('lastname', get_string('missinglastname'), 'required', null, 'client');

        $mform->addElement('text', 'email', get_string('email'));
        $mform->setType('email', PARAM_RAW_TRIMMED);
        $mform->addRule('email', get_string('missingemail'), 'required', null, 'client');
        $mform->addRule('email', get_string('invalidemail'), 'email', null, 'client');

        // ---------- Password mode toggle ---------------------------------
        $mform->addElement('checkbox', 'set_password',
            get_string('mentee_create_set_password', 'enrol_mentorsubscription'));
        $mform->setDefault('set_password', 1);

        // Info alert: shown when checkbox IS checked.
        $mform->addElement('static', 'alert_set_password', '',
            '<div class="alert alert-info py-2 small my-2">' .
            get_string('mentee_create_alert_set_password', 'enrol_mentorsubscription') .
            '</div>');

        // Password policy hint + password field (hidden when checkbox unchecked).
        if (!empty($CFG->passwordpolicy)) {
            $mform->addElement('static', 'passwordpolicyinfo', '', print_password_policy());
        }
        $mform->addElement('password', 'password', get_string('password'), [
            'maxlength' => MAX_PASSWORD_CHARACTERS,
            'size' => 12,
            'autocomplete' => 'new-password'
        ]);
        $mform->setType('password', core_user::get_property_type('password'));
        $mform->addRule('password', get_string('maximumchars', '', MAX_PASSWORD_CHARACTERS),
            'maxlength', MAX_PASSWORD_CHARACTERS, 'client');

        // Warning alert: shown when checkbox is NOT checked.
        $mform->addElement('static', 'alert_auto_password', '',
            '<div class="alert alert-warning py-2 small my-2">' .
            get_string('mentee_create_alert_auto_password', 'enrol_mentorsubscription') .
            '</div>');

        // ---------- City / Country (optional, pre-filled from mentor) ----
        $mform->addElement('text', 'city', get_string('city'), 'maxlength="120" size="20"');
        $mform->setType('city', core_user::get_property_type('city'));
        $mform->setDefault('city', $USER->city ?? '');

        $countrylist = get_string_manager()->get_list_of_countries();
        $countrylist = array_merge(['' => get_string('selectacountry')], $countrylist);
        $mform->addElement('select', 'country', get_string('country'), $countrylist);
        $mform->setDefault('country', $USER->country ?? ($CFG->country ?? ''));

        // ---------- IOMAD: hidden company fields -------------------------
        if (!empty($company)) {
            $mform->addElement('hidden', 'companyid', $company->id);
            $mform->addElement('hidden', 'departmentid', $company->deptid);
            $mform->setType('companyid', PARAM_INT);
            $mform->setType('departmentid', PARAM_INT);
        }

        // ---------- Submit / Cancel --------------------------------------
        $this->add_action_buttons(
            true,
            get_string('mentee_create_submit', 'enrol_mentorsubscription')
        );

        // ---------- Conditional visibility -------------------------------
        $mform->hideIf('alert_set_password',  'set_password', 'notchecked');
        $mform->hideIf('passwordpolicyinfo',  'set_password', 'notchecked');
        $mform->hideIf('password',            'set_password', 'notchecked');
        $mform->hideIf('alert_auto_password', 'set_password', 'checked');
    }

    /**
     * Apply trim filters after data is loaded.
     */
    public function definition_after_data(): void {
        $mform = $this->_form;
        foreach (['firstname', 'lastname'] as $field) {
            $mform->applyFilter($field, 'trim');
        }
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

        // When manual password mode is selected, the field is required and must meet policy.
        if (!empty($data['set_password'])) {
            $password = $data['password'] ?? '';
            if ($password === '') {
                $errors['password'] = get_string('missingpassword');
            } else {
                $errmsg = '';
                if (!check_password_policy($password, $errmsg)) {
                    $errors['password'] = $errmsg;
                }
            }
        }

        return $errors;
    }
}
