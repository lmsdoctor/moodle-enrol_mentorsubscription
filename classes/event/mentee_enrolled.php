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
 * Event: mentee_enrolled
 *
 * Dispatched by mentorship_manager::add_mentee() after a mentee
 * is successfully registered, role-assigned and enrolled in courses.
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
 * Event fired when a mentee is enrolled under a mentor.
 */
class mentee_enrolled extends \core\event\base {

    /**
     * Initialise required event data properties.
     */
    protected function init(): void {
        $this->data['crud']        = 'c'; // Create.
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'enrol_mentorsub_mentees';
    }

    /**
     * Returns localised description string for logstore display.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_mentee_enrolled', 'enrol_mentorsubscription');
    }

    /**
     * Returns a human-readable description of the event.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' (mentor) enrolled the user " .
               "with id '{$this->relateduserid}' (mentee) via enrol_mentorsubscription.";
    }
}
