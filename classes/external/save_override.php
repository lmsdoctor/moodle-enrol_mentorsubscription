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
 * External function: save_override
 *
 * Create or update a per-mentor subscription override (admin only).
 * Allows admins to grant custom pricing, mentee limits, and Stripe Price IDs
 * to individual mentors without modifying the base subscription type (M-2.3).
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

/**
 * Admin-only write endpoint. Upserts a record in enrol_mentorsub_sub_overrides.
 *
 * If an active override already exists for the given user+subtype combination,
 * it is updated in-place. Otherwise a new record is created.
 */
class save_override extends external_api {

    /**
     * Input parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid'                   => new external_value(PARAM_INT,   'Mentor user ID'),
            'subtypeid'                => new external_value(PARAM_INT,   'Subscription type ID'),
            'price_override'           => new external_value(PARAM_FLOAT, 'Custom price (null = no override)', VALUE_OPTIONAL, null),
            'max_mentees_override'     => new external_value(PARAM_INT,   'Custom mentee limit (null = no override)', VALUE_OPTIONAL, null),
            'stripe_price_id_override' => new external_value(PARAM_TEXT,  'Custom Stripe Price ID', VALUE_OPTIONAL, ''),
            'valid_from'               => new external_value(PARAM_INT,   'Unix timestamp: valid from'),
            'valid_until'              => new external_value(PARAM_INT,   'Unix timestamp: valid until (0 = indefinite)', VALUE_OPTIONAL, 0),
            'admin_notes'              => new external_value(PARAM_TEXT,  'Internal admin notes', VALUE_OPTIONAL, ''),
        ]);
    }

    /**
     * Create or update a per-mentor override record.
     *
     * @param  int        $userid
     * @param  int        $subtypeid
     * @param  float|null $priceOverride
     * @param  int|null   $maxMenteesOverride
     * @param  string     $stripePriceIdOverride
     * @param  int        $validFrom
     * @param  int        $validUntil
     * @param  string     $adminNotes
     * @return array {bool success, int overrideid}
     */
    public static function execute(
        int $userid,
        int $subtypeid,
        ?float $priceOverride,
        ?int $maxMenteesOverride,
        string $stripePriceIdOverride,
        int $validFrom,
        int $validUntil,
        string $adminNotes
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'                   => $userid,
            'subtypeid'                => $subtypeid,
            'price_override'           => $priceOverride,
            'max_mentees_override'     => $maxMenteesOverride,
            'stripe_price_id_override' => $stripePriceIdOverride,
            'valid_from'               => $validFrom,
            'valid_until'              => $validUntil,
            'admin_notes'              => $adminNotes,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:manageall', $context);

        $now      = time();
        $existing = $DB->get_record_select(
            'enrol_mentorsub_sub_overrides',
            'userid = :uid AND subtypeid = :sid AND (valid_until = 0 OR valid_until > :now)',
            ['uid' => $params['userid'], 'sid' => $params['subtypeid'], 'now' => $now]
        );

        $record = (object) [
            'userid'                   => $params['userid'],
            'subtypeid'                => $params['subtypeid'],
            'price_override'           => $params['price_override'],
            'max_mentees_override'     => $params['max_mentees_override'],
            'stripe_price_id_override' => $params['stripe_price_id_override'] ?: null,
            'valid_from'               => $params['valid_from'],
            'valid_until'              => $params['valid_until'],
            'admin_notes'              => $params['admin_notes'],
            'timemodified'             => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('enrol_mentorsub_sub_overrides', $record);
            $overrideid = (int) $existing->id;
        } else {
            $record->timecreated = $now;
            $overrideid          = (int) $DB->insert_record('enrol_mentorsub_sub_overrides', $record);
        }

        return ['success' => true, 'overrideid' => $overrideid];
    }

    /**
     * Return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, 'Whether the override was saved'),
            'overrideid' => new external_value(PARAM_INT,  'ID of the created/updated override record'),
        ]);
    }
}
