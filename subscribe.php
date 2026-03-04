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
require_capability('enrol/mentorsubscription:managesubscription', $context);

// -------------------------------------------------------------------------
// Parameters
// -------------------------------------------------------------------------
$subtypeid      = (int) optional_param('subtypeid',  0,  PARAM_INT);
$checkoutStatus = optional_param('checkout',         '', PARAM_ALPHA);   // 'success' | 'cancel'
$orderid        = (int) optional_param('orderid',    0,  PARAM_INT);
$sessionId      = optional_param('session_id',       '', PARAM_ALPHANUMEXT);  // Stripe session ID
$returnurl      = optional_param('returnurl',         '', PARAM_LOCALURL);    // Where to go on cancel/error

// Fallback: if no returnurl supplied, go back to the plan listing itself.
$safeReturnUrl  = !empty($returnurl)
    ? new moodle_url($returnurl)
    : new moodle_url('/enrol/mentorsubscription/subscribe.php');

// -------------------------------------------------------------------------
// Post-checkout: payment confirmed by Stripe
// -------------------------------------------------------------------------
if ($checkoutStatus === 'success') {
    // Attempt immediate fulfillment: if Stripe confirms payment_status=paid
    // right now, create the subscription without waiting for the webhook.
    // The webhook remains the safety net (user closed browser, network error).
    // The idempotency guard in fulfill_checkout_session() prevents duplicates
    // if both paths race.
    if (!empty($sessionId)) {
        try {
            (new stripe_handler())->fulfill_checkout_session($sessionId);
        } catch (\Throwable $e) {
            // Non-fatal: log and let the webhook handle it.
            debugging('enrol_mentorsubscription subscribe.php: immediate fulfillment failed — ' .
                      $e->getMessage() . '. Webhook will retry.', DEBUG_DEVELOPER);
        }
    } else if ((bool) $orderid) {
        // sessionId not available: at minimum persist any order state update.
        $orderRow = $DB->get_record('enrol_mentorsub_orders',
                                    ['id' => $orderid, 'userid' => (int) $USER->id]);
        if ($orderRow && $orderRow->status === 'pending') {
            $DB->update_record('enrol_mentorsub_orders', (object) [
                'id'           => $orderRow->id,
                'status'       => 'processing',
                'timemodified' => time(),
            ]);
        }
    }

    redirect(
        new moodle_url('/enrol/mentorsubscription/dashboard.php'),
        get_string('subscribe_payment_success', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// -------------------------------------------------------------------------
// Post-checkout: user cancelled on Stripe's page
// -------------------------------------------------------------------------
if ($checkoutStatus === 'cancel') {
    if (((bool) $orderid)) {
        $orderRow = $DB->get_record('enrol_mentorsub_orders',
                                    ['id' => $orderid, 'userid' => (int) $USER->id]);
        if ($orderRow && in_array($orderRow->status, ['pending', 'processing'], true)) {
            $DB->update_record('enrol_mentorsub_orders', (object) [
                'id'           => $orderRow->id,
                'status'       => 'cancelled',
                'timemodified' => time(),
            ]);
        }
    }

    redirect(
        $safeReturnUrl,
        get_string('subscribe_payment_cancelled', 'enrol_mentorsubscription'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// -------------------------------------------------------------------------
// Redirect to Stripe Checkout when a plan is selected
// -------------------------------------------------------------------------
if ((bool) $subtypeid) {

    // --- Guard: user already has an active subscription -------------------
    $activeSub = $DB->get_record('enrol_mentorsub_subscriptions',
                                 ['userid' => (int) $USER->id, 'status' => 'active']);
    if ($activeSub) {
        redirect(
            new moodle_url('/enrol/mentorsubscription/dashboard.php'),
            get_string('subscribe_already_subscribed', 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    // --- Validate subtype -------------------------------------------------
    $subtype = $DB->get_record('enrol_mentorsub_sub_types',
                               ['id' => $subtypeid, 'is_active' => 1], '*', IGNORE_MISSING);

    if (!$subtype) {
        redirect(
            $safeReturnUrl,
            get_string('subscribe_invalid_plan', 'enrol_mentorsubscription'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // --- Resolve effective pricing (admin overrides applied) --------------
    $pricing = (new pricing_manager())->resolve((int) $USER->id, $subtypeid);

    // --- Cancel any stale pending orders for this user to avoid orphans --
    $DB->set_field_select(
        'enrol_mentorsub_orders',
        'status', 'cancelled',
        'userid = :uid AND status IN (\'pending\', \'processing\')',
        ['uid' => (int) $USER->id]
    );
    $DB->set_field_select(
        'enrol_mentorsub_orders',
        'timemodified', time(),
        'userid = :uid AND status = \'cancelled\'',
        ['uid' => (int) $USER->id]
    );

    // --- Create the order record (status = pending) -----------------------
    $now      = time();
    $newOrder = (object) [
        'userid'           => (int) $USER->id,
        'subtypeid'        => $subtypeid,
        'overrideid'       => $pricing->overrideid,
        'stripe_session_id' => null,
        'stripe_price_id'  => $pricing->stripe_price_id,
        'amount'           => $pricing->billed_price,
        'status'           => 'pending',
        'subscriptionid'   => null,
        'timecreated'      => $now,
        'timemodified'     => $now,
    ];
    $newOrderId = $DB->insert_record('enrol_mentorsub_orders', $newOrder);

    // --- Build return URLs -------------------------------------------------
    // IMPORTANT: success_url must be built from $CFG->wwwroot, NOT moodle_url.
    // moodle_url->out() percent-encodes { and } into %7B/%7D, which prevents
    // Stripe from substituting the {CHECKOUT_SESSION_ID} template variable.
    $successUrl = rtrim($CFG->wwwroot, '/') .
        '/enrol/mentorsubscription/subscribe.php' .
        '?checkout=success&orderid=' . $newOrderId .
        '&session_id={CHECKOUT_SESSION_ID}';

    $cancelUrlParams = ['checkout' => 'cancel', 'orderid' => $newOrderId];
    if (!empty($returnurl)) {
        $cancelUrlParams['returnurl'] = $returnurl;
    }
    $cancelUrl = (new moodle_url(
        '/enrol/mentorsubscription/subscribe.php',
        $cancelUrlParams
    ))->out(false);

    // --- Create Stripe Checkout Session -----------------------------------
    try {
        $checkoutUrl = (new stripe_handler())->create_checkout_session(
            (int) $USER->id,
            $subtypeid,
            $pricing->stripe_price_id,
            $successUrl,
            $cancelUrl,
            $newOrderId
        );
        redirect($checkoutUrl);
    } catch (\moodle_exception $e) {
        // Clean up the order before aborting.
        $DB->set_field('enrol_mentorsub_orders', 'status', 'cancelled',
                       ['id' => $newOrderId]);
        redirect(
            $safeReturnUrl,
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

// Build the base params for plan select links — preserve returnurl if present.
$planLinkBaseParams = [];
if (!empty($returnurl)) {
    $planLinkBaseParams['returnurl'] = $returnurl;
}

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
                                   ['subtypeid' => $type->id] + $planLinkBaseParams);

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
                       $type->default_max_mentees),
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
