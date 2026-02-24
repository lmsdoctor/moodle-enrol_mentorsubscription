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
 * Event observer map for enrol_mentorsubscription.
 *
 * Maps internal plugin events to their handler methods in observer.php.
 * Three events cover the full lifecycle of the mentor-mentee relationship:
 *
 *   mentee_enrolled      — fired after a mentee is successfully added.
 *   mentee_unenrolled    — fired after a mentee is removed or subscription expires.
 *   mentee_status_changed — fired when mentor toggles a mentee active/inactive.
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // -------------------------------------------------------------------------
    // Observer: mentee_enrolled
    // Triggered by: mentorship_manager::add_mentee() on success.
    // Action: sends welcome notifications to mentor and mentee.
    // -------------------------------------------------------------------------
    [
        'eventname'   => '\enrol_mentorsubscription\event\mentee_enrolled',
        'callback'    => '\enrol_mentorsubscription\observer::mentee_enrolled',
        'priority'    => 200,
        'internal'    => false,
    ],

    // -------------------------------------------------------------------------
    // Observer: mentee_unenrolled
    // Triggered by: mentorship_manager or subscription_manager on expiry/removal.
    // Action: sends access-removed notification to mentee.
    // -------------------------------------------------------------------------
    [
        'eventname'   => '\enrol_mentorsubscription\event\mentee_unenrolled',
        'callback'    => '\enrol_mentorsubscription\observer::mentee_unenrolled',
        'priority'    => 200,
        'internal'    => false,
    ],

    // -------------------------------------------------------------------------
    // Observer: mentee_status_changed
    // Triggered by: mentorship_manager::toggle_mentee_status().
    // Action: sends notification to mentee when deactivated; logs change.
    // -------------------------------------------------------------------------
    [
        'eventname'   => '\enrol_mentorsubscription\event\mentee_status_changed',
        'callback'    => '\enrol_mentorsubscription\observer::mentee_status_changed',
        'priority'    => 200,
        'internal'    => false,
    ],

];
