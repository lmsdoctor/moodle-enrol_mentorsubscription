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
 * External function: toggle_mentee_status
 *
 * Activate or deactivate a mentee in the authenticated mentor's list.
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
 * Toggle a mentee's active/inactive status.
 *
 * Called from the mentor dashboard radio/toggle button via core/ajax.
 */
class toggle_mentee_status extends external_api {

    /**
     * Input parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'menteeid'  => new external_value(PARAM_INT, 'Mentee user ID'),
            'is_active' => new external_value(PARAM_INT, '1 to activate, 0 to deactivate'),
        ]);
    }

    /**
     * Activate or deactivate a mentee.
     *
     * @param  int $menteeid  Mentee user ID.
     * @param  int $isActive  1 = activate, 0 = deactivate.
     * @return array {bool success, string reason, int active_count, int max_mentees}
     */
    public static function execute(int $menteeid, int $isActive): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'menteeid'  => $menteeid,
            'is_active' => $isActive,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:managementees', $context);

        $mentorshipManager = new mentorship_manager();
        $result            = $mentorshipManager->toggle_mentee_status(
            (int) $USER->id,
            (int) $params['menteeid'],
            (int) $params['is_active']
        );

        $sub         = (new subscription_manager())->get_active_subscription((int) $USER->id);
        $maxMentees  = $sub ? (int) $sub->billed_max_mentees : 0;
        $activeCount = $mentorshipManager->count_active_mentees((int) $USER->id);

        return [
            'success'      => (bool) $result['success'],
            'reason'       => (string) ($result['reason'] ?? ''),
            'active_count' => $activeCount,
            'max_mentees'  => $maxMentees,
        ];
    }

    /**
     * Return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'      => new external_value(PARAM_BOOL, 'Whether the toggle succeeded'),
            'reason'       => new external_value(PARAM_TEXT, 'Reason for failure if success=false'),
            'active_count' => new external_value(PARAM_INT,  'Current active mentee count'),
            'max_mentees'  => new external_value(PARAM_INT,  'Mentee limit for current subscription'),
        ]);
    }
}
