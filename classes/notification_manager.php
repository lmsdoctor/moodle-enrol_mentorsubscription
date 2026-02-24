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
        global $DB;

        // Deduplication: only send each (subscription, type, days) combination once.
        $alreadySent = $DB->record_exists('enrol_mentorsub_notifications', [
            'subscriptionid' => $subscriptionid,
            'type'           => 'expiry_warning',
            'days_before'    => $daysBefore,
        ]);
        if ($alreadySent) {
            return false;
        }

        $sub = $DB->get_record('enrol_mentorsub_subscriptions',
                               ['id' => $subscriptionid], '*', IGNORE_MISSING);
        if (!$sub) {
            return false;
        }

        $mentor   = $DB->get_record('user', ['id' => $sub->userid], '*', IGNORE_MISSING);
        if (!$mentor || $mentor->deleted) {
            return false;
        }

        $sender  = \core_user::get_noreply_user();
        $subject = get_string('notify_expiry_warning_subject', 'enrol_mentorsubscription',
                              ['days' => $daysBefore]);
        $body    = get_string('notify_expiry_warning_body', 'enrol_mentorsubscription', [
            'days'    => $daysBefore,
            'date'    => userdate($sub->period_end),
            'siteurl' => (string) \core\url::make_moodle_url('/'),
        ]);

        $msg = $this->build_message($sender, $mentor, $subject, $body);
        $msgid = message_send($msg);

        if ($msgid) {
            $DB->insert_record('enrol_mentorsub_notifications', [
                'subscriptionid' => $subscriptionid,
                'type'           => 'expiry_warning',
                'days_before'    => $daysBefore,
                'timesent'       => time(),
            ]);
            return true;
        }
        return false;
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
        global $DB;

        $sender  = \core_user::get_noreply_user();
        $mentor  = $DB->get_record('user', ['id' => $mentorid], '*', IGNORE_MISSING);
        $mentee  = $DB->get_record('user', ['id' => $menteeid], '*', IGNORE_MISSING);

        if (!$mentor || $mentor->deleted || !$mentee || $mentee->deleted) {
            return;
        }

        $fullname = fullname($mentee);

        // Notify mentor that the mentee has been added.
        $mentorSubject = get_string('notify_mentee_enrolled_mentor_subject',
                                   'enrol_mentorsubscription', ['name' => $fullname]);
        $mentorBody = get_string('notify_mentee_enrolled_mentor_body',
                                'enrol_mentorsubscription', ['name' => $fullname]);
        message_send($this->build_message($sender, $mentor, $mentorSubject, $mentorBody));

        // Notify mentee that they have been enrolled.
        $menteeSubject = get_string('notify_mentee_enrolled_mentee_subject',
                                   'enrol_mentorsubscription',
                                   ['mentor' => fullname($mentor)]);
        $menteeBody = get_string('notify_mentee_enrolled_mentee_body',
                                'enrol_mentorsubscription',
                                ['mentor' => fullname($mentor)]);
        message_send($this->build_message($sender, $mentee, $menteeSubject, $menteeBody));
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
        global $DB;

        $sender = \core_user::get_noreply_user();
        $mentee = $DB->get_record('user', ['id' => $menteeid], '*', IGNORE_MISSING);
        $mentor = $DB->get_record('user', ['id' => $mentorid], '*', IGNORE_MISSING);

        if (!$mentee || $mentee->deleted) {
            return;
        }

        $subject = get_string('notify_mentee_deactivated_subject',
                             'enrol_mentorsubscription');
        $body    = get_string('notify_mentee_deactivated_body',
                             'enrol_mentorsubscription',
                             ['mentor' => $mentor ? fullname($mentor) : '']);

        message_send($this->build_message($sender, $mentee, $subject, $body));
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
