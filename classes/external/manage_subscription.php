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
 * External function: manage_subscription
 *
 * Admin-initiated pause, resume, and plan-change for a mentor subscription.
 * Bridges admin panel UI actions to Stripe and the local DB.
 *
 * Supported actions:
 *   - pause:       stop Stripe payment collection; retain mentee access.
 *   - resume:      re-enable payment collection on a paused subscription.
 *   - change_plan: upgrade / downgrade to a different subscription type.
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
 * Pause, resume, or change plan for a mentor subscription (admin only).
 */
class manage_subscription extends external_api {

    /**
     * Input parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'subscriptionid' => new external_value(PARAM_INT,  'Local subscription record ID'),
            'action'         => new external_value(PARAM_ALPHA, 'pause | resume | change_plan'),
            'new_subtypeid'  => new external_value(PARAM_INT,  'New sub_type ID (required for change_plan)', VALUE_DEFAULT, 0),
            'new_price_id'   => new external_value(PARAM_TEXT, 'New Stripe Price ID (required for change_plan)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the subscription management action.
     *
     * @param  int    $subscriptionid
     * @param  string $action          pause | resume | change_plan
     * @param  int    $newSubtypeid    Required when action = change_plan.
     * @param  string $newPriceId      Required when action = change_plan.
     * @return array {bool success, string status, string message}
     */
    public static function execute(
        int $subscriptionid,
        string $action,
        int $newSubtypeid = 0,
        string $newPriceId = ''
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'subscriptionid' => $subscriptionid,
            'action'         => $action,
            'new_subtypeid'  => $newSubtypeid,
            'new_price_id'   => $newPriceId,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:manageall', $context);

        $manager = new subscription_manager();

        try {
            switch ($params['action']) {

                case 'pause':
                    $manager->pause_subscription((int) $params['subscriptionid']);
                    return [
                        'success' => true,
                        'status'  => 'paused',
                        'message' => get_string('subscription_paused', 'enrol_mentorsubscription'),
                    ];

                case 'resume':
                    $manager->resume_subscription((int) $params['subscriptionid']);
                    return [
                        'success' => true,
                        'status'  => 'active',
                        'message' => get_string('subscription_resumed', 'enrol_mentorsubscription'),
                    ];

                case 'change_plan':
                    if (!$params['new_subtypeid'] || empty($params['new_price_id'])) {
                        throw new \moodle_exception('error_change_plan_missing_params',
                                                    'enrol_mentorsubscription');
                    }
                    $newSubtype = $DB->get_record('enrol_mentorsub_sub_types',
                                                  ['id' => $params['new_subtypeid']], '*', MUST_EXIST);
                    $manager->change_plan(
                        (int) $params['subscriptionid'],
                        (int) $params['new_subtypeid'],
                        $params['new_price_id'],
                        (int) $newSubtype->default_max_mentees,
                        $newSubtype->billing_cycle
                    );
                    return [
                        'success' => true,
                        'status'  => 'active',
                        'message' => get_string('subscription_plan_changed', 'enrol_mentorsubscription'),
                    ];

                default:
                    throw new \moodle_exception('error_invalid_action', 'enrol_mentorsubscription');
            }
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
            'success' => new external_value(PARAM_BOOL, 'Whether the action succeeded'),
            'status'  => new external_value(PARAM_TEXT, 'Resulting subscription status'),
            'message' => new external_value(PARAM_TEXT, 'Confirmation or error message'),
        ]);
    }
}
