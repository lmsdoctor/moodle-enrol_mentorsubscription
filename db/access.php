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
 * Capabilities definition for enrol_mentorsubscription.
 *
 * Three capabilities covering the three actor roles in the system:
 *   1. enrol/mentorsubscription:managesubscription  — Mentor: buy/manage own subscription
 *   2. enrol/mentorsubscription:managementees       — Mentor: add/toggle mentees
 *   3. enrol/mentorsubscription:viewdashboard       — Mentor: view own dashboard
 *   4. enrol/mentorsubscription:manageall           — Admin: full access to all data
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // -------------------------------------------------------------------------
    // Capability: managesubscription
    // Allows a user (mentor) to initiate and manage their own subscription.
    // Assigned to: authenticated users with the "mentor" system role.
    // -------------------------------------------------------------------------
    'enrol/mentorsubscription:managesubscription' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user'    => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // -------------------------------------------------------------------------
    // Capability: managementees
    // Allows a mentor to add, activate and deactivate their mentees.
    // -------------------------------------------------------------------------
    'enrol/mentorsubscription:managementees' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user'    => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // -------------------------------------------------------------------------
    // Capability: viewdashboard
    // Allows a mentor to view their own mentor dashboard.
    // -------------------------------------------------------------------------
    'enrol/mentorsubscription:viewdashboard' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user'    => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // -------------------------------------------------------------------------
    // Capability: manageall
    // Grants full administrative access: view all mentors, manage subscriptions,
    // overrides, courses, and payment history.
    // Assigned to: site administrators and managers only.
    // -------------------------------------------------------------------------
    'enrol/mentorsubscription:manageall' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:config',
    ],

];
