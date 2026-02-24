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
 * Moodleform: admin_subscription_form
 *
 * Used in the admin panel to create or edit a per-mentor price/limit override.
 *
 * Full implementation: M-4.9
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Admin form for creating/editing a mentor subscription override.
 */
class admin_subscription_form extends \moodleform {

    /**
     * Define form elements.
     */
    public function definition(): void {
        $mform = $this->_form;

        // Hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('hidden', 'subtypeid');
        $mform->setType('subtypeid', PARAM_INT);

        // Price override (nullable).
        $mform->addElement('text', 'price_override',
            get_string('override_price', 'enrol_mentorsubscription'), ['size' => 10]);
        $mform->setType('price_override', PARAM_FLOAT);
        $mform->addHelpButton('price_override', 'override_price', 'enrol_mentorsubscription');

        // Max mentees override (nullable).
        $mform->addElement('text', 'max_mentees_override',
            get_string('override_max_mentees', 'enrol_mentorsubscription'), ['size' => 5]);
        $mform->setType('max_mentees_override', PARAM_INT);

        // Stripe Price ID override (nullable).
        $mform->addElement('text', 'stripe_price_id_override',
            get_string('override_stripe_price_id', 'enrol_mentorsubscription'), ['size' => 50]);
        $mform->setType('stripe_price_id_override', PARAM_TEXT);

        // Valid from / until (date pickers).
        $mform->addElement('date_time_selector', 'valid_from',
            get_string('override_valid_from', 'enrol_mentorsubscription'));

        $mform->addElement('date_time_selector', 'valid_until',
            get_string('override_valid_until', 'enrol_mentorsubscription'), ['optional' => true]);

        // Admin notes.
        $mform->addElement('textarea', 'admin_notes',
            get_string('override_admin_notes', 'enrol_mentorsubscription'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('admin_notes', PARAM_TEXT);

        $this->add_action_buttons();
    }
}
