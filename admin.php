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
 * Admin Subscription Panel — entry point.
 *
 * Lists all subscription types and active mentor subscriptions.
 * Provides a form to add / edit per-mentor overrides.
 *
 * URL: /enrol/mentorsubscription/admin.php
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use enrol_mentorsubscription\output\admin_subscription_panel as panel_renderable;
use enrol_mentorsubscription\output\payment_history_panel;
use enrol_mentorsubscription\form\admin_subscription_form;
use enrol_mentorsubscription\form\sub_type_form;
use enrol_mentorsubscription\subscription\subscription_manager;

require_login();

$context = context_system::instance();
require_capability('enrol/mentorsubscription:manageall', $context);

// -------------------------------------------------------------------------
// Page setup
// -------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/enrol/mentorsubscription/admin.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('adminpanel_title', 'enrol_mentorsubscription'));
$PAGE->set_heading(get_string('adminpanel_title', 'enrol_mentorsubscription'));

// -------------------------------------------------------------------------
// Override form — process submission before output begins.
// -------------------------------------------------------------------------
$formAction = optional_param('formaction', '', PARAM_ALPHAEXT);
$overrideId = optional_param('overrideid', 0, PARAM_INT);
$targetUser = optional_param('userid',    0, PARAM_INT);
$subtypeId  = optional_param('subtypeid', 0, PARAM_INT);

$overrideForm  = null;
$subtypeForm   = null;
$historyPanel  = null;

// -------------------------------------------------------------------------
// Sub-type toggle (no form — immediate action + redirect).
// -------------------------------------------------------------------------
if ($formAction === 'togglesubtype' && $subtypeId) {
    require_sesskey();
    $rec = $DB->get_record('enrol_mentorsub_sub_types', ['id' => $subtypeId], '*', MUST_EXIST);
    $DB->set_field('enrol_mentorsub_sub_types', 'is_active',    (int)!$rec->is_active, ['id' => $subtypeId]);
    $DB->set_field('enrol_mentorsub_sub_types', 'timemodified', time(),                ['id' => $subtypeId]);
    redirect(
        new moodle_url('/enrol/mentorsubscription/admin.php'),
        get_string('subtype_toggle_saved', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// -------------------------------------------------------------------------
// Subscription management — cancel, pause, resume (immediate DB+Stripe actions).
// -------------------------------------------------------------------------
if ($formAction === 'cancelsubscription' && $targetUser) {
    require_sesskey();
    $immediately = (bool) optional_param('immediately', 0, PARAM_INT);
    $subMgr = new subscription_manager();
    $sub    = $subMgr->get_active_subscription($targetUser);
    if ($sub) {
        $subMgr->request_cancellation((int) $sub->id, $immediately);
        $msgKey = $immediately ? 'subscription_cancelled_immediately' : 'subscription_cancelled_period_end';
        redirect(
            new moodle_url('/enrol/mentorsubscription/admin.php'),
            get_string($msgKey, 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(
        new moodle_url('/enrol/mentorsubscription/admin.php'),
        get_string('error_no_stripe_subscription', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if ($formAction === 'pausesubscription' && $targetUser) {
    require_sesskey();
    $subMgr = new subscription_manager();
    $sub    = $subMgr->get_active_subscription($targetUser);
    if ($sub) {
        $subMgr->pause_subscription((int) $sub->id);
        redirect(
            new moodle_url('/enrol/mentorsubscription/admin.php'),
            get_string('subscription_paused', 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(new moodle_url('/enrol/mentorsubscription/admin.php'));
}

if ($formAction === 'resumesubscription' && $targetUser) {
    require_sesskey();
    $subMgr = new subscription_manager();
    $sub    = $subMgr->get_active_subscription($targetUser);
    if ($sub) {
        $subMgr->resume_subscription((int) $sub->id);
        redirect(
            new moodle_url('/enrol/mentorsubscription/admin.php'),
            get_string('subscription_resumed', 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(new moodle_url('/enrol/mentorsubscription/admin.php'));
}

// -------------------------------------------------------------------------
// Sub-type create / edit form.
// -------------------------------------------------------------------------
if ($formAction === 'editsubtype') {
    $subtypeForm = new sub_type_form(
        new moodle_url('/enrol/mentorsubscription/admin.php', ['formaction' => 'editsubtype'])
    );

    if ($subtypeForm->is_cancelled()) {
        redirect(new moodle_url('/enrol/mentorsubscription/admin.php'));
    }

    if ($data = $subtypeForm->get_data()) {
        $now = time();
        if (!empty($data->id)) {
            // Update existing record.
            $data->timemodified = $now;
            $DB->update_record('enrol_mentorsub_sub_types', $data);
        } else {
            // Insert new record.
            $data->timecreated  = $now;
            $data->timemodified = $now;
            $DB->insert_record('enrol_mentorsub_sub_types', $data);
        }
        redirect(
            new moodle_url('/enrol/mentorsubscription/admin.php'),
            get_string('subtype_saved', 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Pre-fill form for edits.
    if ($subtypeId) {
        $existing = $DB->get_record('enrol_mentorsub_sub_types', ['id' => $subtypeId]);
        if ($existing) {
            $subtypeForm->set_data($existing);
        }
    }
}

// -------------------------------------------------------------------------
// Payment history view for a specific mentor.
// -------------------------------------------------------------------------
if ($formAction === 'viewhistory' && $targetUser) {
    $mentor = $DB->get_record('user', ['id' => $targetUser], 'id,firstname,lastname,email', MUST_EXIST);
    $history = (new subscription_manager())->get_history($targetUser);
    $historyPanel = new payment_history_panel($mentor, $history);
}

// -------------------------------------------------------------------------
// Override form.
// -------------------------------------------------------------------------
if ($formAction === 'editoverride') {
    // Build the form bound to the existing override (or empty for a new one).
    $formData  = null;
    if ($overrideId) {
        $formData = $DB->get_record('enrol_mentorsub_sub_overrides', ['id' => $overrideId]);
    } elseif ($targetUser && $subtypeId) {
        $formData           = new stdClass();
        $formData->userid   = $targetUser;
        $formData->subtypeid = $subtypeId;
    }

    $overrideForm = new admin_subscription_form(
        new moodle_url('/enrol/mentorsubscription/admin.php', ['formaction' => 'editoverride']),
        []
    );

    if ($overrideForm->is_cancelled()) {
        redirect(new moodle_url('/enrol/mentorsubscription/admin.php'));
    }

    if ($submitted = $overrideForm->get_data()) {
        // Delegate to the external function for validation + persistence.
        $result = \enrol_mentorsubscription\external\save_override::execute(
            (int)    $submitted->userid,
            (int)    $submitted->subtypeid,
            isset($submitted->price_override) && $submitted->price_override !== ''
                ? (float) $submitted->price_override : null,
            isset($submitted->max_mentees_override) && $submitted->max_mentees_override !== ''
                ? (int) $submitted->max_mentees_override : null,
            (string) ($submitted->stripe_price_id_override ?? ''),
            (int)    $submitted->valid_from,
            (int)    ($submitted->valid_until ?? 0),
            (string) ($submitted->admin_notes ?? '')
        );

        if ($result['success']) {
            redirect(
                new moodle_url('/enrol/mentorsubscription/admin.php'),
                get_string('override_saved', 'enrol_mentorsubscription'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }

    if ($formData) {
        $overrideForm->set_data($formData);
    }
}

// -------------------------------------------------------------------------
// Data layer
// -------------------------------------------------------------------------
$subtypes = $DB->get_records('enrol_mentorsub_sub_types', null, 'name ASC');

// Fetch active mentor subscriptions joined with user firstname/lastname.
$sql = "SELECT s.*, u.firstname, u.lastname, u.email
          FROM {enrol_mentorsub_subscriptions} s
          JOIN {user} u ON u.id = s.userid
         WHERE s.status IN ('active','past_due')
      ORDER BY u.lastname ASC, s.period_end ASC";
$activeMentors = array_values($DB->get_records_sql($sql));

// -------------------------------------------------------------------------
// Render
// -------------------------------------------------------------------------
echo $OUTPUT->header();

if ($subtypeForm) {
    $isEdit = (bool) $subtypeId;
    echo $OUTPUT->heading(
        get_string($isEdit ? 'subtype_edit_heading' : 'subtype_add_heading', 'enrol_mentorsubscription'),
        3
    );
    $subtypeForm->display();

} elseif ($historyPanel) {
    echo $OUTPUT->render($historyPanel);

} elseif ($overrideForm) {
    echo $OUTPUT->heading(get_string('adminpanel_overrides', 'enrol_mentorsubscription'), 3);
    $overrideForm->display();

} else {
    $renderable = new panel_renderable(array_values($subtypes), $activeMentors);
    echo $OUTPUT->render($renderable);
}

echo $OUTPUT->footer();
