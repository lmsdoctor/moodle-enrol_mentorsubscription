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
 * Moodleform: sub_type_form
 *
 * Used in the admin panel to create or edit a subscription type (sub_types table).
 * Handles name, billing cycle, price, mentee limit, Stripe price ID, description,
 * sort order and active status.
 *
 * Full implementation: M-4.7
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Admin form for creating or editing a subscription type.
 */
class sub_type_form extends \moodleform {

    /**
     * Define form elements.
     */
    public function definition(): void {
        $mform = $this->_form;

        // Hidden ID for edit mode (0 = new record).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', 0);

        // ── Name ─────────────────────────────────────────────────────────────
        $mform->addElement('text', 'name',
            get_string('subtype_name', 'enrol_mentorsubscription'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // ── Billing cycle ────────────────────────────────────────────────────
        $cycles = [
            'monthly' => get_string('billing_monthly', 'enrol_mentorsubscription'),
            'annual'  => get_string('billing_annual',  'enrol_mentorsubscription'),
        ];
        $mform->addElement('select', 'billing_cycle',
            get_string('subtype_billing_cycle', 'enrol_mentorsubscription'), $cycles);
        $mform->addRule('billing_cycle', null, 'required', null, 'client');

        // ── Price ────────────────────────────────────────────────────────────
        $mform->addElement('text', 'price',
            get_string('subtype_price', 'enrol_mentorsubscription'), ['size' => 10]);
        $mform->setType('price', PARAM_FLOAT);
        $mform->addRule('price', null, 'required', null, 'client');

        // ── Default max mentees ──────────────────────────────────────────────
        $mform->addElement('text', 'default_max_mentees',
            get_string('subtype_max_mentees', 'enrol_mentorsubscription'), ['size' => 5]);
        $mform->setType('default_max_mentees', PARAM_INT);
        $mform->addRule('default_max_mentees', null, 'required', null, 'client');
        $mform->setDefault('default_max_mentees', 10);

        // ── Stripe Price ID ──────────────────────────────────────────────────
        $mform->addElement('text', 'stripe_price_id',
            get_string('subtype_stripe_price_id', 'enrol_mentorsubscription'), ['size' => 60]);
        $mform->setType('stripe_price_id', PARAM_TEXT);
        $mform->addRule('stripe_price_id', null, 'required', null, 'client');
        $mform->addHelpButton('stripe_price_id', 'subtype_stripe_price_id', 'enrol_mentorsubscription');

        // ── Description ──────────────────────────────────────────────────────
        $mform->addElement('textarea', 'description',
            get_string('subtype_description', 'enrol_mentorsubscription'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        // ── Sort order ───────────────────────────────────────────────────────
        $mform->addElement('text', 'sort_order',
            get_string('subtype_sort_order', 'enrol_mentorsubscription'), ['size' => 4]);
        $mform->setType('sort_order', PARAM_INT);
        $mform->setDefault('sort_order', 0);

        // ── Active flag ──────────────────────────────────────────────────────
        $mform->addElement('advcheckbox', 'is_active',
            get_string('subtype_is_active', 'enrol_mentorsubscription'));
        $mform->setDefault('is_active', 1);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Custom validation: price and max_mentees must be positive.
     *
     * @param array $data  Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!empty($data['price']) && (float)$data['price'] < 0) {
            $errors['price'] = get_string('subtype_error_price', 'enrol_mentorsubscription');
        }
        if (!empty($data['default_max_mentees']) && (int)$data['default_max_mentees'] < 1) {
            $errors['default_max_mentees'] = get_string('subtype_error_max_mentees', 'enrol_mentorsubscription');
        }

        return $errors;
    }
}
