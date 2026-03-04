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
 * External function: search_users
 *
 * Returns a list of Moodle users matching the given query (by full name or
 * email address), used to populate the "Add mentee" autocomplete widget in
 * the mentor dashboard modal.
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Search users by name or email for the mentee autocomplete.
 */
class search_users extends external_api {

    /**
     * Parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search string (name or email)', VALUE_REQUIRED),
        ]);
    }

    /**
     * Execute the search.
     *
     * Returns up to 20 active, confirmed users whose full name OR email
     * matches the given query string (case-insensitive LIKE).
     * The current user is always excluded from the results.
     *
     * @param  string $query  At least 2 characters.
     * @return array  Array of {value: int, label: string} objects.
     */
    public static function execute(string $query): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);

        $context = \context_system::instance();
        self::validate_context($context);

        $q = trim($params['query']);
        if (strlen($q) < 2) {
            return [];
        }

        $searchterm  = '%' . $DB->sql_like_escape($q) . '%';
        $fullnamesql = $DB->sql_concat('u.firstname', "' '", 'u.lastname');

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                 WHERE u.deleted   = 0
                   AND u.confirmed = 1
                   AND u.id       != :currentuserid
                   AND (
                       " . $DB->sql_like($fullnamesql, ':search1', false) . "
                       OR " . $DB->sql_like('u.email', ':search2', false) . "
                   )
              ORDER BY u.lastname, u.firstname";

        $records = $DB->get_records_sql($sql, [
            'currentuserid' => (int) $USER->id,
            'search1'       => $searchterm,
            'search2'       => $searchterm,
        ], 0, 20);

        $results = [];
        foreach ($records as $user) {
            $results[] = [
                'value' => (int) $user->id,
                'label' => fullname($user) . ' (' . $user->email . ')',
            ];
        }

        return $results;
    }

    /**
     * Return value definition.
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'value' => new external_value(PARAM_INT,  'User ID'),
                'label' => new external_value(PARAM_TEXT, 'Full name and email'),
            ])
        );
    }
}
