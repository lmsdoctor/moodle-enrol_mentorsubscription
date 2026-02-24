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
 * Language strings for enrol_mentorsubscription (English).
 *
 * Naming convention: all strings use snake_case keys.
 * References: called via get_string('key', 'enrol_mentorsubscription').
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// -------------------------------------------------------------------------
// Plugin identity
// -------------------------------------------------------------------------
$string['pluginname']               = 'Mentor Subscription';
$string['pluginname_desc']          = 'Manages mentor-mentee subscriptions with Stripe payment integration.';

// -------------------------------------------------------------------------
// Settings headings
// -------------------------------------------------------------------------
$string['settings_stripeheading']              = 'Stripe Configuration';
$string['settings_stripeheading_desc']         = 'API keys and webhook secret for Stripe integration.';
$string['settings_subscriptionheading']        = 'Subscription Defaults';
$string['settings_subscriptionheading_desc']   = 'Default plan limits and notification thresholds.';
$string['settings_enrolheading']               = 'Enrolment Configuration';
$string['settings_enrolheading_desc']          = 'Role and course settings for mentee enrolment.';
$string['settings_notificationheading']        = 'Notifications';
$string['settings_notificationheading_desc']   = 'Control when and how notification messages are sent to mentors.';

// -------------------------------------------------------------------------
// Settings fields
// -------------------------------------------------------------------------
$string['settings_stripe_secret_key']          = 'Stripe Secret Key';
$string['settings_stripe_secret_key_desc']     = 'Your Stripe secret key (sk_live_... or sk_test_...). Never share this value.';
$string['settings_stripe_webhook_secret']      = 'Stripe Webhook Secret';
$string['settings_stripe_webhook_secret_desc'] = 'Signing secret for verifying Stripe webhook payloads (whsec_...).';
$string['settings_stripe_publishable_key']     = 'Stripe Publishable Key';
$string['settings_stripe_publishable_key_desc'] = 'Public key used in the frontend to initiate Stripe Checkout (pk_live_... or pk_test_...).';
$string['settings_default_max_mentees']        = 'Default Mentee Limit';
$string['settings_default_max_mentees_desc']   = 'Maximum number of mentees per subscription when no override is in place.';
$string['settings_expiry_warning_days']        = 'Expiry Warning Days';
$string['settings_expiry_warning_days_desc']   = 'Number of days before subscription expiry to send a renewal reminder notification.';
$string['settings_pastdue_grace_days']         = 'Past-Due Grace Period (days)';
$string['settings_pastdue_grace_days_desc']    = 'Days a past_due subscription retains access before being marked as expired.';
$string['settings_studentroleid']              = 'Student Role';
$string['settings_studentroleid_desc']         = 'Role assigned to mentees when enrolled in subscription courses.';
$string['settings_included_course_ids']        = 'Included Course IDs';
$string['settings_included_course_ids_desc']   = 'Comma-separated list of course IDs included in all subscriptions. Managed via the admin panel at runtime.';
$string['settings_send_expiry_warnings']       = 'Send Expiry Warnings';
$string['settings_send_expiry_warnings_desc']  = 'Enable or disable the automatic expiry warning notification emails to mentors.';

// -------------------------------------------------------------------------
// Capabilities
// -------------------------------------------------------------------------
$string['enrol_mentorsubscription:managesubscription'] = 'Manage own subscription';
$string['enrol_mentorsubscription:managementees']      = 'Manage mentees';
$string['enrol_mentorsubscription:viewdashboard']      = 'View mentor dashboard';
$string['enrol_mentorsubscription:manageall']          = 'Administer all mentor subscriptions';

// -------------------------------------------------------------------------
// Subscription statuses
// -------------------------------------------------------------------------
$string['status_pending']    = 'Pending';
$string['status_active']     = 'Active';
$string['status_past_due']   = 'Past due';
$string['status_superseded'] = 'Superseded';
$string['status_cancelled']  = 'Cancelled';
$string['status_expired']    = 'Expired';

// -------------------------------------------------------------------------
// Billing cycles
// -------------------------------------------------------------------------
$string['billing_monthly'] = 'Monthly';
$string['billing_annual']  = 'Annual';

// -------------------------------------------------------------------------
// Dashboard & UI
// -------------------------------------------------------------------------
$string['dashboard_title']           = 'Mentor Dashboard';
$string['dashboard_subscription']    = 'Your Subscription';
$string['dashboard_mentees']         = 'Your Mentees';
$string['dashboard_plan']            = 'Plan';
$string['dashboard_cycle']           = 'Billing cycle';
$string['dashboard_expires']         = 'Expires on';
$string['dashboard_price']           = 'Price';
$string['dashboard_active_mentees']  = 'Active mentees';
$string['dashboard_limit']           = 'Limit';
$string['dashboard_add_mentee']      = 'Add mentee';
$string['dashboard_upgrade']         = 'Upgrade plan';
$string['dashboard_limit_reached']   = 'You have reached the mentee limit for your current plan.';

// -------------------------------------------------------------------------
// Mentee cards
// -------------------------------------------------------------------------
$string['mentee_active']      = 'Active';
$string['mentee_inactive']    = 'Inactive';
$string['mentee_activate']    = 'Activate';
$string['mentee_deactivate']  = 'Deactivate';

// -------------------------------------------------------------------------
// Admin panel
// -------------------------------------------------------------------------
$string['adminpanel_title']           = 'Mentor Subscription Administration';
$string['adminpanel_subtypes']        = 'Subscription Types';
$string['adminpanel_mentors']         = 'Active Mentors';
$string['adminpanel_overrides']       = 'Mentor Overrides';
$string['adminpanel_paymenthistory']  = 'Payment History';

// -------------------------------------------------------------------------
// Errors & exceptions
// -------------------------------------------------------------------------
$string['error_no_active_subscription'] = 'You do not have an active subscription. Please subscribe to add mentees.';
$string['error_limit_reached']          = 'You have reached the mentee limit for your current plan. Please upgrade to add more mentees.';
$string['error_mentee_not_found']       = 'The specified user was not found in the system.';
$string['error_mentee_already_assigned'] = 'This user already has a mentor assigned in the system.';
$string['error_invalid_webhook']        = 'Invalid webhook signature.';
$string['errornostripekey']             = 'Stripe secret key is not configured. Go to Site Administration → Plugins → Enrolments → Mentor Subscription to configure it.';
$string['mentee_added_success']         = 'Mentee successfully added to your list.';

// -------------------------------------------------------------------------
// Notifications
// -------------------------------------------------------------------------
$string['notification_expiry_subject']   = 'Your Mentor Subscription expires in {$a->days} days';
$string['notification_expiry_body']      = 'Dear {$a->fullname}, your mentor subscription expires on {$a->expirydate}. Please renew to maintain access for your mentees.';
$string['notification_mentee_enrolled_subject'] = 'New mentee registered: {$a->menteename}';
$string['notification_mentee_enrolled_body']    = 'Hello {$a->mentorname}, {$a->menteename} has been successfully added to your mentee list.';
$string['notification_mentee_deactivated_subject'] = 'Your course access has been temporarily suspended';
$string['notification_mentee_deactivated_body']    = 'Dear {$a->menteename}, your mentor has temporarily suspended your access to the subscription courses.';

// -------------------------------------------------------------------------
// Tasks
// -------------------------------------------------------------------------
$string['task_check_expiring_subscriptions'] = 'Check expiring subscriptions';
$string['task_sync_stripe_subscriptions']    = 'Sync Stripe subscription statuses';

// -------------------------------------------------------------------------
// Privacy
// -------------------------------------------------------------------------
$string['privacy:metadata:enrol_mentorsub_subscriptions']         = 'Subscription billing records for each mentor.';
$string['privacy:metadata:enrol_mentorsub_mentees']               = 'Mentor-mentee relationship records.';
$string['privacy:metadata:enrol_mentorsub_sub_overrides']         = 'Custom pricing overrides assigned to specific mentors.';
$string['privacy:metadata:enrol_mentorsub_notifications']         = 'Log of notifications sent to mentors.';
$string['privacy:metadata:stripe']                                = 'Payment data is processed and stored by Stripe. See Stripe Privacy Policy.';

// -------------------------------------------------------------------------
// Events
// -------------------------------------------------------------------------
$string['event_mentee_enrolled']        = 'Mentee enrolled into mentor subscription';
$string['event_mentee_unenrolled']      = 'Mentee removed from mentor subscription';
$string['event_mentee_status_changed']  = 'Mentee subscription status changed';

// -------------------------------------------------------------------------
// Override form fields
// -------------------------------------------------------------------------
$string['override_price']              = 'Price override';
$string['override_max_mentees']        = 'Max mentees override';
$string['override_stripe_price_id']    = 'Stripe Price ID override';
$string['override_valid_from']         = 'Valid from';
$string['override_valid_until']        = 'Valid until (optional)';
$string['override_admin_notes']        = 'Admin notes';

// -------------------------------------------------------------------------
// Mentee search / autocomplete
// -------------------------------------------------------------------------
$string['mentee_search']               = 'Search users';

// -------------------------------------------------------------------------
// Parent role
// -------------------------------------------------------------------------
$string['parentrole']                  = 'Mentor (Parent)';
$string['parentrole_desc']             = 'Grants mentors access to their mentees\' grades and profile data via the Moodle Parent Role mechanism.';
$string['cannotcreateparentrole']      = 'Could not create the parent role. Please check Moodle role configuration.';
