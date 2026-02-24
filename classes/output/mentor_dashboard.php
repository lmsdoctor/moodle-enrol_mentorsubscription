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

    /** @var array List of mentee records with user data. */
    private array $mentees;

    /**
     * @param \stdClass|null $subscription Active subscription or null.
     * @param array          $mentees      Array of mentee+user records.
     */
    public function __construct(?\stdClass $subscription, array $mentees) {
        $this->subscription = $subscription;
        $this->mentees      = $mentees;
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
            'has_subscription'  => !is_null($this->subscription),
            'subscription'      => null,
            'mentees'           => [],
            'limit_reached'     => false,
            'active_count'      => 0,
            'max_mentees'       => 0,
        ];

        if ($this->subscription) {
            $ctx['subscription'] = [
                'status'        => $this->subscription->status,
                'billing_cycle' => $this->subscription->billing_cycle,
                'billed_price'  => number_format((float) $this->subscription->billed_price, 2),
                'period_end'    => userdate($this->subscription->period_end),
                'max_mentees'   => $this->subscription->billed_max_mentees,
            ];
            $ctx['max_mentees'] = (int) $this->subscription->billed_max_mentees;
        }

        $activeCount = 0;
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

        $ctx['active_count']  = $activeCount;
        $ctx['limit_reached'] = $this->subscription &&
                                $activeCount >= (int) $this->subscription->billed_max_mentees;

        return $ctx;
    }
}
