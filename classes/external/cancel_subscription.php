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
 * External function: cancel_subscription
 *
 * Admin-initiated cancellation of a mentor's Stripe subscription.
 * Supports two modes:
 *   - at_period_end (default): subscription stays active until cycle ends.
 *   - immediately: access is revoked and Stripe subscription terminated now.
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use enrol_mentorsubscription\subscription\subscription_manager;

/**
 * Cancel a mentor's subscription (admin only).
 */
class cancel_subscription extends external_api {

    /**
     * Input parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'subscriptionid' => new external_value(PARAM_INT,  'Local subscription record ID'),
            'immediately'    => new external_value(PARAM_BOOL, 'True = cancel now; false = at period end', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Cancel a subscription in Stripe and update the local record.
     *
     * @param  int  $subscriptionid  Local subscription record ID.
     * @param  bool $immediately     If true, cancel now rather than at period end.
     * @return array {bool success, string status, string message}
     */
    public static function execute(int $subscriptionid, bool $immediately = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'subscriptionid' => $subscriptionid,
            'immediately'    => $immediately,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:manageall', $context);

        try {
            (new subscription_manager())->request_cancellation(
                (int) $params['subscriptionid'],
                (bool) $params['immediately']
            );
            $key = $params['immediately']
                ? 'subscription_cancelled_immediately'
                : 'subscription_cancelled_period_end';
            return [
                'success' => true,
                'status'  => $params['immediately'] ? 'cancelled' : 'cancel_at_period_end',
                'message' => get_string($key, 'enrol_mentorsubscription'),
            ];
        } catch (\moodle_exception $e) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the cancellation was requested'),
            'status'  => new external_value(PARAM_TEXT, 'New subscription status'),
            'message' => new external_value(PARAM_TEXT, 'Confirmation or error message'),
        ]);
    }
}
