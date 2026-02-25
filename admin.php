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
use enrol_mentorsubscription\form\admin_subscription_form;

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

$overrideForm = null;

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
        $result = \enrol_mentorsubscription\external::save_override(
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

if ($overrideForm) {
    echo $OUTPUT->heading(get_string('adminpanel_overrides', 'enrol_mentorsubscription'), 3);
    $overrideForm->display();
} else {
    $renderable = new panel_renderable(array_values($subtypes), $activeMentors);
    echo $OUTPUT->render($renderable);
}

echo $OUTPUT->footer();
