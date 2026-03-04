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
 * Mentee management page — assign an existing user or create a new one.
 *
 * URL: /enrol/mentorsubscription/dashboard/mentee.php?mode=assign|create
 *
 * Two modes, switchable via URL tabs:
 *   - assign  (default) Search existing Moodle users who have no mentor yet
 *                       and assign the selected one to the current mentor.
 *   - create            Register a brand-new Moodle user and immediately
 *                       assign them as a mentee. A temp password is e-mailed.
 *
 * Prerequisites (checked in order):
 *   1. User is logged in and has enrol/mentorsubscription:managementees.
 *   2. The 'parent' role exists in the Moodle system.
 *   3. The mentor has an active subscription.
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use enrol_mentorsubscription\form\create_mentee_form;
use enrol_mentorsubscription\mentorship\mentorship_manager;
use enrol_mentorsubscription\mentorship\role_manager;
use enrol_mentorsubscription\subscription\subscription_manager;

require_login();

$context = context_system::instance();
require_capability('enrol/mentorsubscription:managementees', $context);

// ---------------------------------------------------------------------------
// Parameters
// ---------------------------------------------------------------------------

$mode = optional_param('mode', 'assign', PARAM_ALPHA);
if (!in_array($mode, ['assign', 'create'], true)) {
    $mode = 'assign';
}

$pageUrl = new moodle_url('/enrol/mentorsubscription/dashboard/mentee.php', ['mode' => $mode]);

$PAGE->set_url($pageUrl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mentee_page_title', 'enrol_mentorsubscription'));
$PAGE->set_heading(get_string('mentee_page_title', 'enrol_mentorsubscription'));
$PAGE->navbar->add(get_string('dashboard_title', 'enrol_mentorsubscription'), new moodle_url('/enrol/mentorsubscription/dashboard'));
$PAGE->navbar->add(
    $mode === 'create'
        ? get_string('mentee_mode_create', 'enrol_mentorsubscription')
        : get_string('mentee_mode_assign', 'enrol_mentorsubscription'),
    $pageUrl
);

// ---------------------------------------------------------------------------
// Prerequisite 1 — Parent role must be configured.
// ---------------------------------------------------------------------------

$parentRoleOk = $DB->record_exists('role', ['shortname' => role_manager::PARENT_ROLE_SHORTNAME]);

if (!$parentRoleOk) {
    // Render the page with only the warning; no form is shown.
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('warning_parent_role_missing', 'enrol_mentorsubscription'),
        'error'
    );
    echo html_writer::tag(
        'p',
        html_writer::link(
            new moodle_url('/enrol/mentorsubscription/dashboard'),
            '← ' . get_string('back'),
            ['class' => 'btn btn-secondary btn-sm']
        )
    );
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Prerequisite 2 — Active subscription required.
// ---------------------------------------------------------------------------

$subscription = (new subscription_manager())->get_current_subscription((int) $USER->id);

if (is_null($subscription)) {
    redirect(
        new moodle_url('/enrol/mentorsubscription/subscribe.php'),
        get_string('dashboard_no_subscription', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// ---------------------------------------------------------------------------
// Form processing
// ---------------------------------------------------------------------------

$error      = '';
$createForm = null;

// --- Assign mode: handle POST. -------------------------------------------
if ($mode === 'assign' && data_submitted() && confirm_sesskey()) {
    $menteeid = optional_param('menteeid', 0, PARAM_INT);

    if ($menteeid > 0) {
        try {
            (new mentorship_manager())->add_mentee((int) $USER->id, $menteeid);
            redirect(
                new moodle_url('/enrol/mentorsubscription/dashboard'),
                get_string('mentee_assign_success', 'enrol_mentorsubscription'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = get_string('mentee_assign_no_selection', 'enrol_mentorsubscription');
    }
}

// --- Create mode: Moodle form. -------------------------------------------
if ($mode === 'create') {
    $createForm = new create_mentee_form($pageUrl->out(false));

    if ($createForm->is_cancelled()) {
        redirect(new moodle_url('/enrol/mentorsubscription/dashboard'));
    }

    if ($data = $createForm->get_data()) {
        try {
            (new mentorship_manager())->create_and_assign_mentee((int) $USER->id, $data);
            redirect(
                new moodle_url('/enrol/mentorsubscription/dashboard'),
                get_string('mentee_create_success', 'enrol_mentorsubscription'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
            // Re-display the form with the error — do not redirect.
        }
    }
}

// ---------------------------------------------------------------------------
// AMD — autocomplete search (only needed for assign mode, safe to load always)
// ---------------------------------------------------------------------------

$PAGE->requires->js_call_amd('enrol_mentorsubscription/mentor_dashboard', 'initAssign');

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

echo $OUTPUT->header();

// Non-form error (e.g. limit reached on assign submit).
if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}

// Mode tabs.
$assignUrl = new moodle_url('/enrol/mentorsubscription/dashboard/mentee.php', ['mode' => 'assign']);
$createUrl = new moodle_url('/enrol/mentorsubscription/dashboard/mentee.php', ['mode' => 'create']);

echo '<ul class="nav nav-tabs mb-4">'
    . '<li class="nav-item">'
    . html_writer::link(
        $assignUrl,
        get_string('mentee_mode_assign', 'enrol_mentorsubscription'),
        ['class' => 'nav-link' . ($mode === 'assign' ? ' active' : '')]
    )
    . '</li>'
    . '<li class="nav-item">'
    . html_writer::link(
        $createUrl,
        get_string('mentee_mode_create', 'enrol_mentorsubscription'),
        ['class' => 'nav-link' . ($mode === 'create' ? ' active' : '')]
    )
    . '</li>'
    . '</ul>';

echo '<div class="card"><div class="card-body">';

if ($mode === 'create') {
    // -----------------------------------------------------------------------
    // Create tab — render the Moodle form.
    // -----------------------------------------------------------------------
    $createForm->display();

} else {
    // -----------------------------------------------------------------------
    // Assign tab — search-autocomplete form (JS-powered, standard POST).
    // -----------------------------------------------------------------------
    $placeholder = get_string('dashboard_mentee_search_placeholder', 'enrol_mentorsubscription');
    $submitLabel = get_string('mentee_assign_submit', 'enrol_mentorsubscription');

    echo '<form method="post" action="" data-region="mentee-assign-form">'
        . '<input type="hidden" name="sesskey" value="' . sesskey() . '" />'
        . '<input type="hidden" name="action"  value="assign" />'
        . '<div class="form-group mb-3" style="position:relative;">'
        . '<input type="text"'
        .        ' id="mentorsub-mentee-search"'
        .        ' class="form-control form-control-lg"'
        .        ' placeholder="' . s($placeholder) . '"'
        .        ' autocomplete="off" />'
        . '<input type="hidden" id="mentorsub-new-menteeid" name="menteeid" />'
        . '<ul id="mentorsub-search-results"'
        .     ' class="list-group mt-1"'
        .     ' style="position:absolute;width:100%;z-index:9999;max-height:220px;overflow-y:auto;">'
        . '</ul>'
        . '</div>'
        . '<button type="submit" class="btn btn-primary">'
        . s($submitLabel)
        . '</button>'
        . '</form>';
}

echo '</div></div>'; // .card-body / .card

echo $OUTPUT->footer();
