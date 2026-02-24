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
 * Scheduled task definitions for enrol_mentorsubscription.
 *
 * Two tasks are registered:
 *   1. check_expiring_subscriptions — daily at 08:00, sends renewal warnings.
 *   2. sync_stripe_subscriptions    — hourly, fallback sync against Stripe API.
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [

    // -------------------------------------------------------------------------
    // Task 1: check_expiring_subscriptions
    // Runs daily at 08:00. Detects subscriptions within N days of expiry
    // (configured in settings) and sends notification via Messaging API.
    // Deduplication is enforced by UNIQUE(subscriptionid, type, days_before)
    // in enrol_mentorsub_notifications — safe to run multiple times.
    // -------------------------------------------------------------------------
    [
        'classname' => '\enrol_mentorsubscription\task\check_expiring_subscriptions',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '8',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // -------------------------------------------------------------------------
    // Task 2: sync_stripe_subscriptions
    // Runs every hour. Acts as a fallback in case Stripe webhooks were missed
    // (network issues, server downtime, etc.). Queries Stripe API for each
    // active/past_due subscription and reconciles status in local DB.
    // -------------------------------------------------------------------------
    [
        'classname' => '\enrol_mentorsubscription\task\sync_stripe_subscriptions',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

];
