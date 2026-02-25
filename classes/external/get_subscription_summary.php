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
 * External function: get_subscription_summary
 *
 * Returns the authenticated mentor's active subscription summary for the
 * dashboard widget (M-4.1).
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
use enrol_mentorsubscription\mentorship\mentorship_manager;
use enrol_mentorsubscription\subscription\subscription_manager;

/**
 * Read-only endpoint. Returns subscription state for the mentor dashboard.
 *
 * Returns has_subscription=false when no active subscription exists;
 * all other fields are VALUE_OPTIONAL and omitted in that case.
 */
class get_subscription_summary extends external_api {

    /**
     * No input parameters required.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Return active subscription summary for the authenticated mentor.
     *
     * @return array Subscription summary — see execute_returns().
     */
    public static function execute(): array {
        global $USER;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:viewdashboard', $context);

        $sub = (new subscription_manager())->get_active_subscription((int) $USER->id);

        if (!$sub) {
            return ['has_subscription' => false];
        }

        $activeCount = (new mentorship_manager())->count_active_mentees((int) $USER->id);

        return [
            'has_subscription' => true,
            'status'           => $sub->status,
            'billing_cycle'    => $sub->billing_cycle,
            'period_end'       => (int) $sub->period_end,
            'billed_price'     => (string) $sub->billed_price,
            'active_count'     => $activeCount,
            'max_mentees'      => (int) $sub->billed_max_mentees,
        ];
    }

    /**
     * Return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_subscription' => new external_value(PARAM_BOOL, 'Whether an active subscription exists'),
            'status'           => new external_value(PARAM_TEXT, 'Subscription status', VALUE_OPTIONAL),
            'billing_cycle'    => new external_value(PARAM_TEXT, 'monthly or annual',   VALUE_OPTIONAL),
            'period_end'       => new external_value(PARAM_INT,  'Unix timestamp of period end', VALUE_OPTIONAL),
            'billed_price'     => new external_value(PARAM_TEXT, 'Price charged this cycle', VALUE_OPTIONAL),
            'active_count'     => new external_value(PARAM_INT,  'Current active mentee count', VALUE_OPTIONAL),
            'max_mentees'      => new external_value(PARAM_INT,  'Mentee limit', VALUE_OPTIONAL),
        ]);
    }
}
