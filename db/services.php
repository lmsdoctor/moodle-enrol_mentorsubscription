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
 * External services and AJAX function definitions for enrol_mentorsubscription.
 *
 * All AJAX endpoints used by the mentor dashboard and admin panel are declared
 * here. They are registered as external functions and called via the standard
 * Moodle AJAX API (core/ajax module in AMD).
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// -------------------------------------------------------------------------
// External functions declaration.
// Each function maps to a dedicated class in classes/external/.
// Pattern: classname = FQCN, methodname = execute (Moodle 4.x convention).
// -------------------------------------------------------------------------
$functions = [

    // Toggle a mentee's active/inactive status (called from toggle button in UI).
    'enrol_mentorsubscription_toggle_mentee_status' => [
        'classname'     => 'enrol_mentorsubscription\external\toggle_mentee_status',
        'methodname'    => 'execute',
        'description'   => 'Activate or deactivate a mentee. Returns updated state.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'enrol/mentorsubscription:managementees',
        'loginrequired' => true,
    ],

    // Add a new mentee to the mentor's list.
    'enrol_mentorsubscription_add_mentee' => [
        'classname'     => 'enrol_mentorsubscription\external\add_mentee',
        'methodname'    => 'execute',
        'description'   => 'Register a new mentee under the authenticated mentor.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'enrol/mentorsubscription:managementees',
        'loginrequired' => true,
    ],

    // Get the mentor's current subscription summary for the dashboard widget.
    'enrol_mentorsubscription_get_subscription_summary' => [
        'classname'     => 'enrol_mentorsubscription\external\get_subscription_summary',
        'methodname'    => 'execute',
        'description'   => 'Returns active subscription data for the mentor dashboard.',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'enrol/mentorsubscription:viewdashboard',
        'loginrequired' => true,
    ],

    // Admin: save or update a per-mentor price/limit override.
    'enrol_mentorsubscription_save_override' => [
        'classname'     => 'enrol_mentorsubscription\external\save_override',
        'methodname'    => 'execute',
        'description'   => 'Create or update a mentor-specific subscription override (admin only).',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'enrol/mentorsubscription:manageall',
        'loginrequired' => true,
    ],

    // Admin: cancel a mentor subscription in Stripe (at period end or immediately).
    'enrol_mentorsubscription_cancel_subscription' => [
        'classname'     => 'enrol_mentorsubscription\external\cancel_subscription',
        'methodname'    => 'execute',
        'description'   => 'Cancel a mentor subscription via Stripe (admin only).',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'enrol/mentorsubscription:manageall',
        'loginrequired' => true,
    ],

    // Admin: pause, resume, or change plan for a mentor subscription.
    'enrol_mentorsubscription_manage_subscription' => [
        'classname'     => 'enrol_mentorsubscription\external\manage_subscription',
        'methodname'    => 'execute',
        'description'   => 'Pause, resume, or change plan for a mentor subscription (admin only).',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'enrol/mentorsubscription:manageall',
        'loginrequired' => true,
    ],

];

// -------------------------------------------------------------------------
// Service grouping (optional — exposes functions as a named web service set).
// -------------------------------------------------------------------------
$services = [
    'Mentor Subscription Services' => [
        'functions'       => [
            'enrol_mentorsubscription_toggle_mentee_status',
            'enrol_mentorsubscription_add_mentee',
            'enrol_mentorsubscription_get_subscription_summary',
            'enrol_mentorsubscription_save_override',
            'enrol_mentorsubscription_cancel_subscription',
            'enrol_mentorsubscription_manage_subscription',
        ],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'enrol_mentorsub_services',
    ],
];
