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
 * Event: mentee_status_changed
 *
 * Dispatched by mentorship_manager::toggle_mentee_status() when a mentor
 * activates or deactivates a mentee via the radio button toggle in the UI.
 *
 * The event carries `other['is_active']` (0 or 1) so observers can
 * determine the direction of the status change.
 *
 * Full implementation: M-3.8
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a mentee's active/inactive status is toggled.
 */
class mentee_status_changed extends \core\event\base {

    /**
     * Initialise required event data properties.
     */
    protected function init(): void {
        $this->data['crud']        = 'u'; // Update.
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'enrol_mentorsub_mentees';
    }

    /**
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_mentee_status_changed', 'enrol_mentorsubscription');
    }

    /**
     * @return string
     */
    public function get_description(): string {
        $status = !empty($this->other['is_active']) ? 'activated' : 'deactivated';
        return "The user with id '{$this->userid}' (mentor) {$status} the mentee " .
               "with id '{$this->relateduserid}' via enrol_mentorsubscription.";
    }

    /**
     * Validate event data — is_active must be 0 or 1.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['is_active'])) {
            throw new \coding_exception('The mentee_status_changed event requires other[is_active].');
        }
    }
}
