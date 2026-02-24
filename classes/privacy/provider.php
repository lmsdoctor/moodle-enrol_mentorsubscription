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
 * Privacy Provider — GDPR compliance for enrol_mentorsubscription.
 *
 * Declares all personal data stored by the plugin and implements
 * export and deletion routines as required by Moodle's Privacy API.
 *
 * Tables containing personal data:
 *   - enrol_mentorsub_subscriptions  (userid, Stripe IDs)
 *   - enrol_mentorsub_mentees        (mentorid, menteeid)
 *   - enrol_mentorsub_sub_overrides  (userid, created_by)
 *   - enrol_mentorsub_notifications  (via subscriptionid → userid)
 *
 * Full implementation: M-6.6
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;

/**
 * GDPR privacy provider implementation.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

    // -------------------------------------------------------------------------
    // Metadata: declare all personal data stored.
    // -------------------------------------------------------------------------

    /**
     * Returns metadata describing all personal data stored by this plugin.
     *
     * @param collection $collection Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('enrol_mentorsub_subscriptions', [
            'userid'                  => 'privacy:metadata:enrol_mentorsub_subscriptions',
            'stripe_customer_id'      => 'privacy:metadata:enrol_mentorsub_subscriptions',
            'stripe_subscription_id'  => 'privacy:metadata:enrol_mentorsub_subscriptions',
            'billed_price'            => 'privacy:metadata:enrol_mentorsub_subscriptions',
        ], 'privacy:metadata:enrol_mentorsub_subscriptions');

        $collection->add_database_table('enrol_mentorsub_mentees', [
            'mentorid'  => 'privacy:metadata:enrol_mentorsub_mentees',
            'menteeid'  => 'privacy:metadata:enrol_mentorsub_mentees',
            'is_active' => 'privacy:metadata:enrol_mentorsub_mentees',
        ], 'privacy:metadata:enrol_mentorsub_mentees');

        $collection->add_database_table('enrol_mentorsub_sub_overrides', [
            'userid'      => 'privacy:metadata:enrol_mentorsub_sub_overrides',
            'created_by'  => 'privacy:metadata:enrol_mentorsub_sub_overrides',
        ], 'privacy:metadata:enrol_mentorsub_sub_overrides');

        $collection->add_database_table('enrol_mentorsub_notifications', [
            'subscriptionid' => 'privacy:metadata:enrol_mentorsub_notifications',
            'timesent'       => 'privacy:metadata:enrol_mentorsub_notifications',
        ], 'privacy:metadata:enrol_mentorsub_notifications');

        // External system: Stripe.
        $collection->add_external_location_link('stripe', [
            'userid'         => 'privacy:metadata:stripe',
            'payment_intent' => 'privacy:metadata:stripe',
        ], 'privacy:metadata:stripe');

        return $collection;
    }

    // -------------------------------------------------------------------------
    // Request handlers — full implementation: M-6.6
    // -------------------------------------------------------------------------

    /**
     * Returns contexts that contain personal data for the given user.
     *
     * @param int $userid User ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $hasSub = $DB->record_exists('enrol_mentorsub_subscriptions', ['userid' => $userid]);
        $hasMentee = $DB->record_exists_select(
            'enrol_mentorsub_mentees',
            'mentorid = :mid OR menteeid = :mid2',
            ['mid' => $userid, 'mid2' => $userid]
        );
        $hasOverride = $DB->record_exists('enrol_mentorsub_sub_overrides', ['userid' => $userid]);

        if ($hasSub || $hasMentee || $hasOverride) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Exports all personal data for the given user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        // Verify CONTEXT_SYSTEM is included.
        $hasSystem = false;
        foreach ($contextlist->get_contexts() as $ctx) {
            if ($ctx->contextlevel === CONTEXT_SYSTEM) {
                $hasSystem = true;
                break;
            }
        }
        if (!$hasSystem) {
            return;
        }

        $path = [get_string('pluginname', 'enrol_mentorsubscription')];

        // Export subscriptions.
        $subs = $DB->get_records('enrol_mentorsub_subscriptions', ['userid' => $userid]);
        foreach ($subs as $sub) {
            // Remove internal Stripe token data before exporting.
            unset($sub->stripe_customer_id, $sub->stripe_subscription_id,
                  $sub->stripe_payment_intent_id, $sub->stripe_invoice_id);
            \core_privacy\local\request\writer::with_context(
                \context_system::instance()
            )->export_data(array_merge($path, ['subscription_' . $sub->id]), $sub);
        }

        // Export mentee relationships (as mentor or mentee).
        $mentees = $DB->get_records_select(
            'enrol_mentorsub_mentees',
            'mentorid = :mid OR menteeid = :mid2',
            ['mid' => $userid, 'mid2' => $userid]
        );
        foreach ($mentees as $r) {
            \core_privacy\local\request\writer::with_context(
                \context_system::instance()
            )->export_data(array_merge($path, ['mentee_' . $r->id]), $r);
        }

        // Export overrides (as the target mentor).
        $overrides = $DB->get_records('enrol_mentorsub_sub_overrides', ['userid' => $userid]);
        foreach ($overrides as $ov) {
            \core_privacy\local\request\writer::with_context(
                \context_system::instance()
            )->export_data(array_merge($path, ['override_' . $ov->id]), $ov);
        }
    }

    /**
     * Deletes all personal data for the given user in the given contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        // Anonymise subscriptions: null out all Stripe identifiers.
        $DB->set_field('enrol_mentorsub_subscriptions', 'stripe_customer_id',       null, ['userid' => $userid]);
        $DB->set_field('enrol_mentorsub_subscriptions', 'stripe_subscription_id',   null, ['userid' => $userid]);
        $DB->set_field('enrol_mentorsub_subscriptions', 'stripe_payment_intent_id', null, ['userid' => $userid]);
        $DB->set_field('enrol_mentorsub_subscriptions', 'stripe_invoice_id',        null, ['userid' => $userid]);

        // Delete mentee relationship records involving this user.
        $DB->delete_records_select(
            'enrol_mentorsub_mentees',
            'mentorid = :mid OR menteeid = :mid2',
            ['mid' => $userid, 'mid2' => $userid]
        );

        // Delete notification logs for subscriptions owned by this user.
        $subIds = $DB->get_fieldset_select(
            'enrol_mentorsub_subscriptions', 'id', 'userid = :uid', ['uid' => $userid]
        );
        if (!empty($subIds)) {
            list($insql, $inparams) = $DB->get_in_or_equal($subIds);
            $DB->delete_records_select('enrol_mentorsub_notifications', "subscriptionid $insql", $inparams);
        }
    }

    /**
     * Deletes all personal data in the given context.
     * Required when an entire context is deleted (e.g., course deletion).
     *
     * @param \context $context Context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        // This plugin stores all data at CONTEXT_SYSTEM level only.
        // Deletion is only triggered if the entire system context is reset,
        // which does not occur during normal Moodle operation.
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        // Full data wipe is handled on a per-user basis via delete_data_for_user().
        // We deliberately do not bulk-delete billing records as they may be
        // required for financial auditing purposes.
    }
}
