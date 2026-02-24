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
 * Pricing Manager — override chain resolution for mentor subscriptions.
 *
 * Resolves the effective price, mentee limit and Stripe Price ID for a
 * given mentor by applying the override chain:
 *   1. Check for an active per-mentor override (valid_from ≤ now ≤ valid_until).
 *   2. Apply override fields where NOT NULL; fall back to sub_type defaults.
 *   3. Return a resolved pricing object used for snapshot in subscriptions table.
 *
 * Full implementation: M-2.2
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\subscription;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves effective subscription pricing applying the override chain.
 */
class pricing_manager {

    /**
     * Resolves the effective pricing for a mentor on a given subscription type.
     *
     * Returns an object with the fields that must be snapshotted into the
     * subscriptions record at checkout time.
     *
     * @param int $userid    Mentor user ID.
     * @param int $subtypeid Subscription type ID.
     * @return \stdClass {
     *     float  billed_price,
     *     int    billed_max_mentees,
     *     string stripe_price_id,
     *     int|null overrideid
     * }
     * @throws \dml_exception If the sub_type record is not found.
     */
    public function resolve(int $userid, int $subtypeid): \stdClass {
        global $DB;

        // Load the base subscription type — source of truth for defaults.
        $subtype = $DB->get_record('enrol_mentorsub_sub_types', ['id' => $subtypeid], '*', MUST_EXIST);

        // --- Check for an active override for this mentor + type. --------
        $now      = time();
        $override = $DB->get_record_select(
            'enrol_mentorsub_sub_overrides',
            'userid = :uid AND subtypeid = :stid AND valid_from <= :now AND (valid_until IS NULL OR valid_until >= :now2)',
            ['uid' => $userid, 'stid' => $subtypeid, 'now' => $now, 'now2' => $now]
        );

        // --- Apply override chain. ----------------------------------------
        $resolved               = new \stdClass();
        $resolved->overrideid   = null;
        $resolved->billed_price        = (float) $subtype->price;
        $resolved->billed_max_mentees  = (int)   $subtype->default_max_mentees;
        $resolved->stripe_price_id     = $subtype->stripe_price_id;

        if ($override) {
            $resolved->overrideid = (int) $override->id;

            if ($override->price_override !== null) {
                $resolved->billed_price = (float) $override->price_override;
            }
            if ($override->max_mentees_override !== null) {
                $resolved->billed_max_mentees = (int) $override->max_mentees_override;
            }
            if (!empty($override->stripe_price_id_override)) {
                $resolved->stripe_price_id = $override->stripe_price_id_override;
            }
        }

        return $resolved;
    }
}
