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
 * Global admin settings for enrol_mentorsubscription.
 *
 * Accessible at: Site administration → Plugins → Enrolments → Mentor Subscription
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // -------------------------------------------------------------------------
    // Section: Stripe Configuration
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'enrol_mentorsubscription/stripeheading',
        get_string('settings_stripeheading', 'enrol_mentorsubscription'),
        get_string('settings_stripeheading_desc', 'enrol_mentorsubscription')
    ));

    // Stripe Secret Key.
    $settings->add(new admin_setting_configpasswordunmask(
        'enrol_mentorsubscription/stripe_secret_key',
        get_string('settings_stripe_secret_key', 'enrol_mentorsubscription'),
        get_string('settings_stripe_secret_key_desc', 'enrol_mentorsubscription'),
        ''
    ));

    // Stripe Webhook Secret (for HMAC signature verification).
    $settings->add(new admin_setting_configpasswordunmask(
        'enrol_mentorsubscription/stripe_webhook_secret',
        get_string('settings_stripe_webhook_secret', 'enrol_mentorsubscription'),
        get_string('settings_stripe_webhook_secret_desc', 'enrol_mentorsubscription'),
        ''
    ));

    // Stripe Publishable Key (used in frontend JS for Checkout redirect).
    $settings->add(new admin_setting_configtext(
        'enrol_mentorsubscription/stripe_publishable_key',
        get_string('settings_stripe_publishable_key', 'enrol_mentorsubscription'),
        get_string('settings_stripe_publishable_key_desc', 'enrol_mentorsubscription'),
        ''
    ));

    // -------------------------------------------------------------------------
    // Section: Subscription Defaults
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'enrol_mentorsubscription/subscriptionheading',
        get_string('settings_subscriptionheading', 'enrol_mentorsubscription'),
        get_string('settings_subscriptionheading_desc', 'enrol_mentorsubscription')
    ));

    // Default maximum number of mentees per subscription (global fallback).
    $settings->add(new admin_setting_configtext(
        'enrol_mentorsubscription/default_max_mentees',
        get_string('settings_default_max_mentees', 'enrol_mentorsubscription'),
        get_string('settings_default_max_mentees_desc', 'enrol_mentorsubscription'),
        '10',
        PARAM_INT
    ));

    // Days before expiry to send renewal warning notification.
    $settings->add(new admin_setting_configtext(
        'enrol_mentorsubscription/expiry_warning_days',
        get_string('settings_expiry_warning_days', 'enrol_mentorsubscription'),
        get_string('settings_expiry_warning_days_desc', 'enrol_mentorsubscription'),
        '14,7,3',
        PARAM_TEXT
    ));

    // Grace period in days for past_due subscriptions before marking expired.
    $settings->add(new admin_setting_configtext(
        'enrol_mentorsubscription/pastdue_grace_days',
        get_string('settings_pastdue_grace_days', 'enrol_mentorsubscription'),
        get_string('settings_pastdue_grace_days_desc', 'enrol_mentorsubscription'),
        '3',
        PARAM_INT
    ));

    // -------------------------------------------------------------------------
    // Section: Enrolment Configuration
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'enrol_mentorsubscription/enrolheading',
        get_string('settings_enrolheading', 'enrol_mentorsubscription'),
        get_string('settings_enrolheading_desc', 'enrol_mentorsubscription')
    ));

    // Student role ID used when enrolling mentees into courses.
    if (!during_initial_install()) {
        $roles = get_default_enrol_roles(context_system::instance());
        $settings->add(new admin_setting_configselect(
            'enrol_mentorsubscription/studentroleid',
            get_string('settings_studentroleid', 'enrol_mentorsubscription'),
            get_string('settings_studentroleid_desc', 'enrol_mentorsubscription'),
            0,
            $roles
        ));
    }

    // Comma-separated list of course IDs included in the subscription.
    // (Runtime management via admin panel UI in M-4; this is the initial fallback.)
    $settings->add(new admin_setting_configtext(
        'enrol_mentorsubscription/included_course_ids',
        get_string('settings_included_course_ids', 'enrol_mentorsubscription'),
        get_string('settings_included_course_ids_desc', 'enrol_mentorsubscription'),
        '',
        PARAM_TEXT
    ));

    // -------------------------------------------------------------------------
    // Section: Notifications
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'enrol_mentorsubscription/notificationheading',
        get_string('settings_notificationheading', 'enrol_mentorsubscription'),
        get_string('settings_notificationheading_desc', 'enrol_mentorsubscription')
    ));

    // Enable / disable expiry warning notifications.
    $settings->add(new admin_setting_configcheckbox(
        'enrol_mentorsubscription/send_expiry_warnings',
        get_string('settings_send_expiry_warnings', 'enrol_mentorsubscription'),
        get_string('settings_send_expiry_warnings_desc', 'enrol_mentorsubscription'),
        1
    ));
}
