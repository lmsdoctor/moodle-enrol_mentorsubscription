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
 * Database upgrade steps for enrol_mentorsubscription.
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin DB schema.
 *
 * @param int $oldversion Previous installed version.
 * @return bool Always true.
 */
function xmldb_enrol_mentorsubscription_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // -------------------------------------------------------------------------
    // v2026030400 — adds enrol_mentorsub_orders to track Stripe Checkout Sessions
    // -------------------------------------------------------------------------
    if ($oldversion < 2026030400) {

        $table = new xmldb_table('enrol_mentorsub_orders');

        // Fields.
        $table->add_field('id',               XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',           XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);
        $table->add_field('subtypeid',        XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);
        $table->add_field('overrideid',       XMLDB_TYPE_INTEGER, '10',    null, null,          null,           null);
        $table->add_field('stripe_session_id', XMLDB_TYPE_CHAR,   '255',   null, null,          null,           null);
        $table->add_field('stripe_price_id',  XMLDB_TYPE_CHAR,    '255',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('amount',           XMLDB_TYPE_NUMBER,  '10, 2', null, XMLDB_NOTNULL, null,           null);
        $table->add_field('status',           XMLDB_TYPE_CHAR,    '20',    null, XMLDB_NOTNULL, null,           'pending');
        $table->add_field('subscriptionid',   XMLDB_TYPE_INTEGER, '10',    null, null,          null,           null);
        $table->add_field('timecreated',      XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);
        $table->add_field('timemodified',     XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);

        // Keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Indexes.
        $table->add_index('idx_userid_status',     XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
        $table->add_index('idx_stripe_session_id', XMLDB_INDEX_NOTUNIQUE, ['stripe_session_id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026030400, 'enrol', 'mentorsubscription');
    }

    // -------------------------------------------------------------------------
    // v2026030901 — adds optional plan_profile_field_option to sub_types
    // -------------------------------------------------------------------------
    if ($oldversion < 2026030901) {

        $table = new xmldb_table('enrol_mentorsub_sub_types');
        $field = new xmldb_field(
            'plan_profile_field_option',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            false,   // NULLABLE — field is optional.
            null,
            null,
            'billing_cycle'  // Insert after billing_cycle.
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026030901, 'enrol', 'mentorsubscription');
    }

    // -------------------------------------------------------------------------
    // v2026031002 — add plan_profile_field_option snapshot to subscriptions
    //               and orders tables
    // -------------------------------------------------------------------------
    if ($oldversion < 2026031002) {

        $optionField = new xmldb_field(
            'plan_profile_field_option',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            false,
            null,
            null,
            'status'
        );

        // Add to enrol_mentorsub_subscriptions.
        $subsTable = new xmldb_table('enrol_mentorsub_subscriptions');
        if (!$dbman->field_exists($subsTable, $optionField)) {
            $dbman->add_field($subsTable, $optionField);
        }

        // Add to enrol_mentorsub_orders.
        $ordersTable = new xmldb_table('enrol_mentorsub_orders');
        if (!$dbman->field_exists($ordersTable, $optionField)) {
            $dbman->add_field($ordersTable, $optionField);
        }

        upgrade_plugin_savepoint(true, 2026031002, 'enrol', 'mentorsubscription');
    }

    return true;
}
