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
 * External function: add_mentee
 *
 * Register a new mentee under the authenticated mentor.
 * Enforces subscription existence and mentee limit (M-3.1, M-3.2).
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
use moodle_exception;

/**
 * Add a new mentee to the mentor's list.
 *
 * Throws a moodle_exception (caught and returned as success=false) when:
 * - The mentor has no active subscription.
 * - The mentee limit is reached.
 * - The mentee user does not exist.
 * - The mentee is already assigned.
 */
class add_mentee extends external_api {

    /**
     * Input parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'menteeid' => new external_value(PARAM_INT, 'User ID of the mentee to add'),
        ]);
    }

    /**
     * Add a mentee to the authenticated mentor's list.
     *
     * @param  int $menteeid  Mentee user ID.
     * @return array {bool success, string message, int menteeid}
     */
    public static function execute(int $menteeid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['menteeid' => $menteeid]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:managementees', $context);

        try {
            $record = (new mentorship_manager())->add_mentee((int) $USER->id, (int) $params['menteeid']);
            return [
                'success'  => true,
                'message'  => get_string('mentee_added_success', 'enrol_mentorsubscription'),
                'menteeid' => (int) $record->menteeid,
            ];
        } catch (moodle_exception $e) {
            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'menteeid' => (int) $params['menteeid'],
            ];
        }
    }

    /**
     * Return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'  => new external_value(PARAM_BOOL, 'Whether the mentee was added'),
            'message'  => new external_value(PARAM_TEXT, 'Success or error message'),
            'menteeid' => new external_value(PARAM_INT,  'Mentee user ID'),
        ]);
    }
}
