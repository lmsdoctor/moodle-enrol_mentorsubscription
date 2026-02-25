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
 * Mentor Dashboard — entry point.
 *
 * Renders the authenticated mentor's subscription summary and mentee list.
 * Loads the AMD module that powers the toggle-switch and "Add mentee" modal.
 *
 * URL: /enrol/mentorsubscription/dashboard.php
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use enrol_mentorsubscription\output\mentor_dashboard as dashboard_renderable;
use enrol_mentorsubscription\subscription\subscription_manager;
use enrol_mentorsubscription\mentorship\mentorship_manager;

require_login();

$context = context_system::instance();
require_capability('enrol/mentorsubscription:viewdashboard', $context);

// -------------------------------------------------------------------------
// Page setup
// -------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/enrol/mentorsubscription/dashboard.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('dashboard_title', 'enrol_mentorsubscription'));
$PAGE->set_heading(get_string('dashboard_title', 'enrol_mentorsubscription'));

// AMD module drives the toggle switch and "Add mentee" modal.
$PAGE->requires->js_call_amd('enrol_mentorsubscription/mentor_dashboard', 'init');

// -------------------------------------------------------------------------
// Data layer
// -------------------------------------------------------------------------
$subManager    = new subscription_manager();
$mentorManager = new mentorship_manager();

$subscription = $subManager->get_current_subscription((int) $USER->id);

// Access control: no active/paused subscription → redirect to subscribe page.
if (is_null($subscription)) {
    redirect(
        new moodle_url('/enrol/mentorsubscription/subscribe.php'),
        get_string('dashboard_no_subscription', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Determine warning banner type for the template.
$warningType = null;
if ($subscription->status === 'paused') {
    $warningType = 'paused';
} elseif ((int) ($subscription->cancel_at_period_end ?? 0) === 1) {
    $warningType = 'cancel_at_period_end';
}

$mentees = $mentorManager->get_mentees((int) $USER->id);

// -------------------------------------------------------------------------
// Render
// -------------------------------------------------------------------------
echo $OUTPUT->header();

$renderable = new dashboard_renderable($subscription, $mentees, $warningType);
echo $OUTPUT->render($renderable);

echo $OUTPUT->footer();
