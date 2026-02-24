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
 * Event observer for enrol_mentorsubscription.
 *
 * Handles post-event side effects such as sending notifications
 * after mentor-mentee relationship changes.
 *
 * Full implementation: M-3.9
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer class — callbacks mapped in db/events.php.
 */
class observer {

    /**
     * Fired after a mentee is successfully added to a mentor's list.
     *
     * Sends welcome notifications to both mentor and mentee.
     * Full implementation: M-5.4
     *
     * @param \enrol_mentorsubscription\event\mentee_enrolled $event
     * @return void
     */
    public static function mentee_enrolled(\enrol_mentorsubscription\event\mentee_enrolled $event): void {
        // TODO M-5.4: Send notification_manager::notify_mentee_enrolled().
        debugging('observer::mentee_enrolled fired for menteeid=' . $event->relateduserid, DEBUG_DEVELOPER);
    }

    /**
     * Fired after a mentee is removed or the subscription expires.
     *
     * Sends access-removed notification to the mentee.
     * Full implementation: M-5.5
     *
     * @param \enrol_mentorsubscription\event\mentee_unenrolled $event
     * @return void
     */
    public static function mentee_unenrolled(\enrol_mentorsubscription\event\mentee_unenrolled $event): void {
        // TODO M-5.5: Send notification_manager::notify_mentee_deactivated().
        debugging('observer::mentee_unenrolled fired for menteeid=' . $event->relateduserid, DEBUG_DEVELOPER);
    }

    /**
     * Fired when a mentor toggles a mentee's active/inactive state.
     *
     * Sends deactivation notification if is_active changed to 0.
     * Full implementation: M-5.5
     *
     * @param \enrol_mentorsubscription\event\mentee_status_changed $event
     * @return void
     */
    public static function mentee_status_changed(\enrol_mentorsubscription\event\mentee_status_changed $event): void {
        // TODO M-5.5: Conditional notification based on $event->other['is_active'].
        debugging('observer::mentee_status_changed fired for menteeid=' . $event->relateduserid, DEBUG_DEVELOPER);
    }
}
