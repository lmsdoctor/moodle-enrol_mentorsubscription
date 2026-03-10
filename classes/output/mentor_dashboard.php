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
 * Renderable: mentor_dashboard
 *
 * Prepares all data required by templates/mentor_dashboard.mustache.
 * Implements renderable + templatable so $OUTPUT->render() works natively.
 *
 * Full implementation: M-4.1 and M-4.2
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\output;

defined('MOODLE_INTERNAL') || die();

use renderer_base;

/**
 * Renderable data class for the mentor dashboard template.
 */
class mentor_dashboard implements \renderable, \templatable {

    /** @var \stdClass|null Active subscription record. */
    private ?\stdClass $subscription;

    /** @var array List of mentee records with user data (empty for non-mentors). */
    private array $mentees;

    /** @var string|null Warning type: null | 'paused' | 'cancel_at_period_end' */
    private ?string $warningType;

    /** @var bool True when the subscriber has the mentor role in any course. */
    private bool $isMentor;

    /** @var array Full subscription/billing history, newest first. */
    private array $history;

    /**
     * @param \stdClass|null $subscription  Active or paused subscription, or null.
     * @param array          $mentees       Array of mentee+user records (empty for non-mentors).
     * @param string|null    $warningType   'paused' | 'cancel_at_period_end' | null.
     * @param bool           $isMentor      Whether the user holds the mentor role.
     * @param array          $history       Full billing history, newest first.
     */
    public function __construct(
        ?\stdClass $subscription,
        array $mentees,
        ?string $warningType = null,
        bool $isMentor = false,
        array $history = []
    ) {
        $this->subscription = $subscription;
        $this->mentees      = $mentees;
        $this->warningType  = $warningType;
        $this->isMentor     = $isMentor;
        $this->history      = $history;
    }

    /**
     * Exports data for the Mustache template.
     *
     * Full implementation: M-4.1 and M-4.2
     *
     * @param renderer_base $output
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $ctx = [
            'has_subscription'          => !is_null($this->subscription),
            'subscription'              => null,
            'is_mentor'                 => $this->isMentor,
            'mentees'                   => [],
            'limit_reached'             => false,
            'active_count'              => 0,
            'max_mentees'               => 0,
            'warning_paused'            => ($this->warningType === 'paused'),
            'warning_cancel_period_end' => ($this->warningType === 'cancel_at_period_end'),
            'warning_period_end_date'   => ($this->subscription
                                            ? $this->fmtdate((int) $this->subscription->period_end)
                                            : ''),
            'history'                   => [],
            'has_history'               => false,
        ];

        if ($this->subscription) {
            $ctx['subscription'] = [
                'status'        => $this->subscription->status,
                'billing_cycle' => $this->subscription->billing_cycle,
                'billed_price'  => number_format((float) $this->subscription->billed_price, 2),
                'period_start'  => $this->fmtdate((int) $this->subscription->period_start),
                'period_end'    => $this->fmtdate((int) $this->subscription->period_end),
                'max_mentees'   => $this->subscription->billed_max_mentees,
            ];
            $ctx['max_mentees'] = (int) $this->subscription->billed_max_mentees;
        }

        // Mentee data — only for mentor users.
        $activeCount = 0;
        if ($this->isMentor) {
            foreach ($this->mentees as $mentee) {
                $ctx['mentees'][] = [
                    'menteeid'   => $mentee->menteeid,
                    'fullname'   => fullname($mentee),
                    'email'      => $mentee->email,
                    'is_active'  => (bool) $mentee->is_active,
                ];
                if ($mentee->is_active) {
                    $activeCount++;
                }
            }
        }

        $ctx['active_count']  = $activeCount;
        $ctx['limit_reached'] = $this->isMentor && $this->subscription &&
                                $activeCount >= (int) $this->subscription->billed_max_mentees;

        // Progress bar percentage (Mustache can't do math).
        $max = $ctx['max_mentees'];
        $ctx['progress_pct'] = ($this->isMentor && $max > 0)
            ? (int) round(($activeCount / $max) * 100)
            : 0;

        // Navigation URL for the dedicated mentee management page.
        $ctx['manage_mentee_url'] = (new \moodle_url(
                '/enrol/mentorsubscription/dashboard/mentee.php'))->out(false);

        // Check whether the 'parent' role is configured in this Moodle instance.
        global $DB;
        $ctx['parent_role_ok'] = $this->isMentor && (bool) $DB->record_exists(
                'role',
                ['shortname' => \enrol_mentorsubscription\mentorship\role_manager::PARENT_ROLE_SHORTNAME]
        );

        // Billing history — shown to all subscribers.
        foreach ($this->history as $record) {
            $ctx['history'][] = [
                'status'           => $record->status,
                'status_label'     => get_string(
                                         'status_' . str_replace('_', '', $record->status),
                                         'enrol_mentorsubscription'
                                      ),
                'type'              => $record->plan_profile_field_option ?? '-',
                'billing_cycle'     => $record->billing_cycle,
                'billed_price'      => number_format((float) $record->billed_price, 2),
                'period_start'      => $this->fmtdate((int) $record->period_start),
                'period_end'        => $this->fmtdate((int) $record->period_end),
                'stripe_invoice_id' => $record->stripe_invoice_id ?? '—',
                'timecreated'       => $this->fmtdate((int) $record->timecreated),
                'is_active'         => $record->status === 'active',
                'is_expired'        => in_array($record->status, ['expired', 'cancelled', 'superseded']),
            ];
        }
        $ctx['has_history'] = !empty($ctx['history']);

        return $ctx;
    }

    /**
     * Format a Unix timestamp as MM/DD/YYYY, respecting the user's timezone.
     * Returns '—' for zero/missing timestamps.
     *
     * @param int $ts Unix timestamp.
     * @return string
     */
    private function fmtdate(int $ts): string {
        if ($ts <= 0) {
            return '—';
        }
        return userdate($ts, '%m/%d/%Y');
    }
}
