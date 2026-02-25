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
 * Subscription plan selection and Stripe Checkout redirect.
 *
 * Two modes:
 *  1. No subtypeid param → show all active subscription types for selection.
 *  2. subtypeid param present → resolve pricing, create Stripe Checkout Session
 *     and redirect the browser to Stripe's hosted payment page.
 *
 * After successful payment Stripe redirects back to dashboard.php?checkout=success.
 * On cancel Stripe redirects back to subscribe.php?checkout=cancel.
 *
 * URL: /enrol/mentorsubscription/subscribe.php[?subtypeid=N]
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use enrol_mentorsubscription\subscription\pricing_manager;
use enrol_mentorsubscription\subscription\stripe_handler;

require_login();

$context = context_system::instance();
// Any authenticated user that may become a mentor can access this page.
require_capability('enrol/mentorsubscription:viewdashboard', $context);

// -------------------------------------------------------------------------
// Parameters
// -------------------------------------------------------------------------
$subtypeid    = optional_param('subtypeid', 0, PARAM_INT);
$checkoutStatus = optional_param('checkout', '', PARAM_ALPHA);   // 'success' | 'cancel'

// -------------------------------------------------------------------------
// Post-checkout feedback
// -------------------------------------------------------------------------
if ($checkoutStatus === 'success') {
    redirect(
        new moodle_url('/enrol/mentorsubscription/dashboard.php'),
        get_string('subscribe_payment_success', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($checkoutStatus === 'cancel') {
    redirect(
        new moodle_url('/enrol/mentorsubscription/subscribe.php'),
        get_string('subscribe_payment_cancelled', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// -------------------------------------------------------------------------
// Redirect to Stripe Checkout when a plan is selected
// -------------------------------------------------------------------------
if ($subtypeid > 0) {
    // Ensure the subtype exists and is active.
    $subtype = $DB->get_record('enrol_mentorsub_sub_types',
                               ['id' => $subtypeid, 'is_active' => 1], '*', IGNORE_MISSING);

    if (!$subtype) {
        redirect(
            new moodle_url('/enrol/mentorsubscription/subscribe.php'),
            get_string('subscribe_invalid_plan', 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Resolve effective pricing (applies admin overrides if any).
    $pricing = (new pricing_manager())->resolve((int) $USER->id, $subtypeid);

    $successUrl = (new moodle_url(
        '/enrol/mentorsubscription/subscribe.php',
        ['checkout' => 'success']
    ))->out(false);

    $cancelUrl = (new moodle_url(
        '/enrol/mentorsubscription/subscribe.php',
        ['checkout' => 'cancel', 'subtypeid' => $subtypeid]
    ))->out(false);

    try {
        $checkoutUrl = (new stripe_handler())->create_checkout_session(
            (int) $USER->id,
            $subtypeid,
            $pricing->stripe_price_id,
            $successUrl,
            $cancelUrl
        );
        redirect($checkoutUrl);
    } catch (\moodle_exception $e) {
        redirect(
            new moodle_url('/enrol/mentorsubscription/subscribe.php'),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// -------------------------------------------------------------------------
// Plan selection page
// -------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/enrol/mentorsubscription/subscribe.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('subscribe_title', 'enrol_mentorsubscription'));
$PAGE->set_heading(get_string('subscribe_title', 'enrol_mentorsubscription'));

// Fetch all active subscription types.
$subtypes = $DB->get_records('enrol_mentorsub_sub_types', ['is_active' => 1], 'price ASC');

echo $OUTPUT->header();

if (empty($subtypes)) {
    echo $OUTPUT->notification(
        get_string('subscribe_no_plans', 'enrol_mentorsubscription'),
        'warning'
    );
} else {
    echo html_writer::start_div('row row-cols-1 row-cols-md-3 g-4 mb-4');

    foreach ($subtypes as $type) {
        $selectUrl = new moodle_url('/enrol/mentorsubscription/subscribe.php',
                                   ['subtypeid' => $type->id]);

        echo html_writer::start_div('col');
        echo html_writer::start_div('card h-100 shadow-sm');
        echo html_writer::start_div('card-body d-flex flex-column');

        echo html_writer::tag('h5', format_string($type->name), ['class' => 'card-title']);
        echo html_writer::tag('p',
            html_writer::tag('span', '$' . number_format((float)$type->price, 2),
                             ['class' => 'display-6 fw-bold']) .
            ' / ' . s($type->billing_cycle),
            ['class' => 'card-text']
        );
        echo html_writer::tag('p',
            get_string('subscribe_mentee_limit', 'enrol_mentorsubscription',
                       ['limit' => $type->default_max_mentees]),
            ['class' => 'card-text text-muted']
        );

        // Push the button to the bottom of the card.
        echo html_writer::start_div('mt-auto');
        echo html_writer::link(
            $selectUrl,
            get_string('subscribe_select_plan', 'enrol_mentorsubscription'),
            ['class' => 'btn btn-primary w-100']
        );
        echo html_writer::end_div();  // mt-auto

        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card
        echo html_writer::end_div(); // col
    }

    echo html_writer::end_div(); // row
}

echo $OUTPUT->footer();
