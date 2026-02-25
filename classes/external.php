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
 * External functions for enrol_mentorsubscription AJAX API.
 *
 * All functions declared in db/services.php are implemented here.
 * Each method follows the Moodle external API pattern:
 *   1. Define *_parameters() / *_returns()
 *   2. Validate params with validate_parameters()
 *   3. require_login() + require_capability()
 *   4. Execute business logic via manager classes
 *   5. Return typed array conforming to *_returns()
 *
 * Full implementation: M-4.12
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use moodle_exception;
use external_value;
use external_function_parameters;
use external_single_structure;

/**
 * AJAX external functions for the mentor dashboard and admin panel.
 */
class external extends \external_api {

    // =========================================================================
    // toggle_mentee_status
    // =========================================================================

    /**
     * @return external_function_parameters
     */
    public static function toggle_mentee_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'menteeid' => new external_value(PARAM_INT, 'Mentee user ID'),
            'is_active' => new external_value(PARAM_INT, '1 to activate, 0 to deactivate'),
        ]);
    }

    /**
     * Toggle a mentee's active/inactive status.
     *
     * Full implementation: M-4.3 (uses mentorship_manager::toggle_mentee_status)
     *
     * @param int $menteeid  Mentee user ID.
     * @param int $isActive  1 = activate, 0 = deactivate.
     * @return array {bool success, string reason, int active_count, int max_mentees}
     */
    public static function toggle_mentee_status(int $menteeid, int $isActive): array {
        global $USER;

        $params = self::validate_parameters(self::toggle_mentee_status_parameters(), [
            'menteeid'  => $menteeid,
            'is_active' => $isActive,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:managementees', $context);

        $manager = new \enrol_mentorsubscription\mentorship\mentorship_manager();
        $result  = $manager->toggle_mentee_status(
            (int) $USER->id,
            (int) $params['menteeid'],
            (int) $params['is_active']
        );

        $sub          = (new \enrol_mentorsubscription\subscription\subscription_manager())
                            ->get_active_subscription((int) $USER->id);
        $maxMentees   = $sub ? (int) $sub->billed_max_mentees : 0;
        $activeCount  = $manager->count_active_mentees((int) $USER->id);

        return [
            'success'      => (bool) $result['success'],
            'reason'       => (string) ($result['reason'] ?? ''),
            'active_count' => $activeCount,
            'max_mentees'  => $maxMentees,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function toggle_mentee_status_returns(): external_single_structure {
        return new external_single_structure([
            'success'      => new external_value(PARAM_BOOL, 'Whether the toggle succeeded'),
            'reason'       => new external_value(PARAM_TEXT, 'Reason for failure if success=false'),
            'active_count' => new external_value(PARAM_INT,  'Current active mentee count'),
            'max_mentees'  => new external_value(PARAM_INT,  'Mentee limit for current subscription'),
        ]);
    }

    // =========================================================================
    // add_mentee
    // =========================================================================

    /**
     * @return external_function_parameters
     */
    public static function add_mentee_parameters(): external_function_parameters {
        return new external_function_parameters([
            'menteeid' => new external_value(PARAM_INT, 'User ID of the mentee to add'),
        ]);
    }

    /**
     * Add a new mentee to the authenticated mentor's list.
     *
     * Full implementation: M-3.1 via mentorship_manager::add_mentee()
     *
     * @param int $menteeid Mentee user ID.
     * @return array {bool success, string message, int menteeid}
     */
    public static function add_mentee(int $menteeid): array {
        global $USER;

        $params = self::validate_parameters(self::add_mentee_parameters(), ['menteeid' => $menteeid]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:managementees', $context);

        try {
            $record = (new \enrol_mentorsubscription\mentorship\mentorship_manager())
                          ->add_mentee((int) $USER->id, (int) $params['menteeid']);
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
     * @return external_single_structure
     */
    public static function add_mentee_returns(): external_single_structure {
        return new external_single_structure([
            'success'  => new external_value(PARAM_BOOL, 'Whether the mentee was added'),
            'message'  => new external_value(PARAM_TEXT, 'Success or error message'),
            'menteeid' => new external_value(PARAM_INT,  'Mentee user ID'),
        ]);
    }

    // =========================================================================
    // get_subscription_summary
    // =========================================================================

    /**
     * @return external_function_parameters
     */
    public static function get_subscription_summary_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the active subscription summary for the authenticated mentor.
     *
     * Full implementation: M-4.1 via subscription_manager::get_active_subscription()
     *
     * @return array Subscription summary data.
     */
    public static function get_subscription_summary(): array {
        global $USER;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('enrol/mentorsubscription:viewdashboard', $context);

        $manager = new \enrol_mentorsubscription\subscription\subscription_manager();
        $sub     = $manager->get_active_subscription((int) $USER->id);

        if (!$sub) {
            return ['has_subscription' => false];
        }

        $activeCount = (new \enrol_mentorsubscription\mentorship\mentorship_manager())
                           ->count_active_mentees((int) $USER->id);

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
     * @return external_single_structure
     */
    public static function get_subscription_summary_returns(): external_single_structure {
        return new external_single_structure([
            'has_subscription' => new external_value(PARAM_BOOL, 'Whether an active subscription exists'),
            'status'           => new external_value(PARAM_TEXT, 'Subscription status', VALUE_OPTIONAL),
            'billing_cycle'    => new external_value(PARAM_TEXT, 'monthly or annual', VALUE_OPTIONAL),
            'period_end'       => new external_value(PARAM_INT,  'Unix timestamp of period end', VALUE_OPTIONAL),
            'billed_price'     => new external_value(PARAM_TEXT, 'Price charged this cycle', VALUE_OPTIONAL),
            'active_count'     => new external_value(PARAM_INT,  'Current active mentee count', VALUE_OPTIONAL),
            'max_mentees'      => new external_value(PARAM_INT,  'Mentee limit', VALUE_OPTIONAL),
        ]);
    }

    // =========================================================================
    // save_override (admin only)
    // =========================================================================

    /**
     * @return external_function_parameters
     */
    public static function save_override_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid'                  => new external_value(PARAM_INT,   'Mentor user ID'),
            'subtypeid'               => new external_value(PARAM_INT,   'Subscription type ID'),
            'price_override'          => new external_value(PARAM_FLOAT, 'Custom price (null for no override)', VALUE_OPTIONAL, null),
            'max_mentees_override'    => new external_value(PARAM_INT,   'Custom mentee limit (null for no override)', VALUE_OPTIONAL, null),
            'stripe_price_id_override' => new external_value(PARAM_TEXT, 'Custom Stripe Price ID', VALUE_OPTIONAL, ''),
            'valid_from'              => new external_value(PARAM_INT,   'Unix timestamp: valid from'),
            'valid_until'             => new external_value(PARAM_INT,   'Unix timestamp: valid until (0 = indefinite)', VALUE_OPTIONAL, 0),
            'admin_notes'             => new external_value(PARAM_TEXT,  'Internal notes', VALUE_OPTIONAL, ''),
        ]);
    }

    /**
     * Create or update a per-mentor subscription override.
     *
     * Full implementation: M-2.3
     *
     * @param  int    $userid
     * @param  int    $subtypeid
     * @param  float|null $priceOverride
     * @param  int|null   $maxMenteesOverride
     * @param  string     $stripePriceIdOverride
     * @param  int        $validFrom
     * @param  int        $validUntil
     * @param  string     $adminNotes
     * @return array {bool success, int overrideid}
     */
    public static function save_override(
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

        $params = self::validate_parameters(self::save_override_parameters(), [
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

        $now = time();

        // Check for an existing override for this user+subtype that is still valid.
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
            $overrideid = (int) $DB->insert_record('enrol_mentorsub_sub_overrides', $record);
        }

        return ['success' => true, 'overrideid' => $overrideid];
    }

    /**
     * @return external_single_structure
     */
    public static function save_override_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, 'Whether the override was saved'),
            'overrideid' => new external_value(PARAM_INT,  'ID of the created/updated override record'),
        ]);
    }
}
