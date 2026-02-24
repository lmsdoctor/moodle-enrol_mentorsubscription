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
 * Notification Manager — sends messages via Moodle Messaging API.
 *
 * All outgoing notifications pass through this class. It:
 *   - Respects user notification preferences.
 *   - Writes to enrol_mentorsub_notifications for deduplication.
 *   - Uses message_send() — never direct email or HTML.
 *
 * Full implementation: M-5.3 to M-5.5
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

/**
 * Centralised notification dispatcher for the plugin.
 */
class notification_manager {

    /**
     * Send an expiry warning notification to a mentor.
     *
     * Deduplication: checks UNIQUE(subscriptionid, type, days_before)
     * in enrol_mentorsub_notifications before sending.
     *
     * Full implementation: M-5.1 and M-5.3
     *
     * @param int $subscriptionid Active subscription ID.
     * @param int $daysBefore     Days remaining before expiry.
     * @return bool True if sent; false if already sent or skipped.
     */
    public function notify_expiry_warning(int $subscriptionid, int $daysBefore): bool {
        // TODO M-5.3: Build message object and call message_send().
        // Check UNIQUE constraint first; insert record on success.
        throw new \coding_exception('notify_expiry_warning() not yet implemented — scheduled for M-5.3.');
    }

    /**
     * Send welcome notifications to mentor and mentee after registration.
     *
     * Full implementation: M-5.4
     *
     * @param int $mentorid Mentor user ID.
     * @param int $menteeid Mentee user ID.
     * @return void
     */
    public function notify_mentee_enrolled(int $mentorid, int $menteeid): void {
        // TODO M-5.4: message_send() to both mentor and mentee.
        throw new \coding_exception('notify_mentee_enrolled() not yet implemented — scheduled for M-5.4.');
    }

    /**
     * Notify a mentee that their access has been temporarily deactivated.
     *
     * Full implementation: M-5.5
     *
     * @param int $menteeid Mentee user ID.
     * @param int $mentorid Mentor user ID (for context in message).
     * @return void
     */
    public function notify_mentee_deactivated(int $menteeid, int $mentorid): void {
        // TODO M-5.5: message_send() to mentee.
        throw new \coding_exception('notify_mentee_deactivated() not yet implemented — scheduled for M-5.5.');
    }

    /**
     * Build a standard Moodle message object for this plugin.
     *
     * @param \stdClass $fromuser  Sender user record.
     * @param \stdClass $touser    Recipient user record.
     * @param string    $subject   Message subject.
     * @param string    $body      Plain-text body.
     * @param string    $bodyHtml  HTML body.
     * @return \core\message\message
     */
    protected function build_message(
        \stdClass $fromuser,
        \stdClass $touser,
        string $subject,
        string $body,
        string $bodyHtml = ''
    ): \core\message\message {
        $message                     = new \core\message\message();
        $message->component          = 'enrol_mentorsubscription';
        $message->name               = 'notification';
        $message->userfrom           = $fromuser;
        $message->userto             = $touser;
        $message->subject            = $subject;
        $message->fullmessage        = $body;
        $message->fullmessageformat  = FORMAT_PLAIN;
        $message->fullmessagehtml    = $bodyHtml ?: '<p>' . nl2br(s($body)) . '</p>';
        $message->smallmessage       = $subject;
        $message->notification       = 1;
        return $message;
    }
}
