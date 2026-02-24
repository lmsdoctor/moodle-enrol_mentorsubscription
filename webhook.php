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
 * Stripe Webhook endpoint for enrol_mentorsubscription.
 *
 * This file is intentionally PUBLIC — it does not require a Moodle session
 * because Stripe cannot authenticate with session cookies.
 *
 * Security is enforced exclusively through HMAC-SHA256 signature verification
 * using the Stripe-Signature header and the configured webhook secret.
 *
 * Supported events:
 *   - checkout.session.completed   → creates active subscription record
 *   - invoice.paid                 → process_renewal() — new billing cycle
 *   - invoice.payment_failed       → marks status = past_due
 *   - customer.subscription.deleted → marks status = expired, unenrols all mentees
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// We must bypass Moodle's normal login/session checks for this endpoint.
// phpcs:ignore moodle.Files.RequireLogin.Missing
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use enrol_mentorsubscription\subscription\stripe_handler;

// ---------------------------------------------------------------------------
// 1. Read raw payload before any framework processing touches it.
// ---------------------------------------------------------------------------
$payload   = file_get_contents('php://input');
$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// ---------------------------------------------------------------------------
// 2. Verify HMAC-SHA256 signature — reject anything that does not match.
// ---------------------------------------------------------------------------
$webhookSecret = get_config('enrol_mentorsubscription', 'stripe_webhook_secret');

if (empty($webhookSecret)) {
    // Misconfiguration — log and refuse.
    debugging('enrol_mentorsubscription: stripe_webhook_secret is not configured.', DEBUG_DEVELOPER);
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret not configured']);
    exit;
}

try {
    $handler = new stripe_handler();
    $event   = $handler->construct_event($payload, $sigHeader, $webhookSecret);
} catch (\UnexpectedValueException $e) {
    // Invalid payload.
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload: ' . $e->getMessage()]);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Signature mismatch — possible replay attack or wrong secret.
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// ---------------------------------------------------------------------------
// 3. Dispatch to stripe_handler — all business logic lives there.
// ---------------------------------------------------------------------------
try {
    $handler->handle_event($event);
    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (\Throwable $e) {
    // Log internal errors but return 200 to prevent Stripe from retrying
    // events that have already been partially processed.
    debugging('enrol_mentorsubscription webhook error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http_response_code(200);
    echo json_encode(['received' => true, 'warning' => 'Internal processing error logged']);
}

exit;
