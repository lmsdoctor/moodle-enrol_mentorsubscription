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
 * Stripe Handler — Checkout Session creation and webhook event processing.
 *
 * Encapsulates all Stripe SDK interactions:
 *   - create_checkout_session(): initiates payment flow.
 *   - construct_event():         verifies HMAC signature (used by webhook.php).
 *   - handle_event():            dispatches each Stripe event type to the
 *                                appropriate subscription_manager method.
 *
 * Full implementation: M-2.4 to M-2.10
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\subscription;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles all Stripe API interactions for the plugin.
 */
class stripe_handler {

    /**
     * Verify the Stripe webhook signature and return parsed event object.
     *
     * Called by webhook.php before any processing begins.
     *
     * @param string $payload       Raw request body.
     * @param string $sigHeader     Value of the Stripe-Signature header.
     * @param string $webhookSecret Webhook signing secret from plugin settings.
     * @return \Stripe\Event Verified event object.
     * @throws \UnexpectedValueException On invalid payload.
     * @throws \Stripe\Exception\SignatureVerificationException On signature mismatch.
     */
    public function construct_event(string $payload, string $sigHeader, string $webhookSecret): \Stripe\Event {
        // TODO M-2.10: Initialise Stripe SDK from thirdparty/ autoloader.
        // return \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        throw new \coding_exception('construct_event() not yet implemented — Stripe SDK required (M-2.10).');
    }

    /**
     * Dispatch a verified Stripe event to the correct handler.
     *
     * Full implementation: M-2.5 to M-2.8
     *
     * @param \Stripe\Event $event Verified Stripe event.
     * @return void
     */
    public function handle_event(\Stripe\Event $event): void {
        // TODO M-2.5 to M-2.8: Dispatch by $event->type.
        // Supported: checkout.session.completed, invoice.paid,
        //            invoice.payment_failed, customer.subscription.deleted.
        throw new \coding_exception('handle_event() not yet implemented — scheduled for M-2.5 to M-2.8.');
    }

    /**
     * Create a Stripe Checkout Session and return the redirect URL.
     *
     * Called from the subscription page when a mentor selects a plan.
     *
     * Full implementation: M-2.4
     *
     * @param int    $userid       Mentor user ID.
     * @param string $priceId      Stripe Price ID (resolved by pricing_manager).
     * @param string $successUrl   URL to redirect after successful payment.
     * @param string $cancelUrl    URL to redirect if mentor cancels checkout.
     * @return string Stripe Checkout Session URL.
     */
    public function create_checkout_session(
        int $userid,
        string $priceId,
        string $successUrl,
        string $cancelUrl
    ): string {
        // TODO M-2.4: Implement Stripe Checkout Session creation.
        throw new \coding_exception('create_checkout_session() not yet implemented — scheduled for M-2.4.');
    }
}
