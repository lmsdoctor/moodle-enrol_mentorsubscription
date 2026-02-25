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
 * Renderable: payment_history_panel
 *
 * Prepares billing history data for templates/payment_history.mustache.
 * Displays all billing cycles for a specific mentor, ordered newest first.
 *
 * Full implementation: M-4.10
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\output;

defined('MOODLE_INTERNAL') || die();

use renderer_base;

/**
 * Renderable data class for the per-mentor payment history panel.
 */
class payment_history_panel implements \renderable, \templatable {

    /** @var \stdClass Mentor user record (must contain id, firstname, lastname). */
    private \stdClass $mentor;

    /** @var array Array of subscription records (immutable billing ledger). */
    private array $history;

    /**
     * @param \stdClass $mentor  Mentor user record.
     * @param array     $history Subscription records ordered by timecreated DESC.
     */
    public function __construct(\stdClass $mentor, array $history) {
        $this->mentor  = $mentor;
        $this->history = $history;
    }

    /**
     * Exports data for the Mustache template.
     *
     * @param renderer_base $output
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $rows = [];
        foreach ($this->history as $record) {
            $rows[] = [
                'id'                    => $record->id,
                'status'                => $record->status,
                'status_label'          => get_string('status_' . str_replace('_', '', $record->status),
                                               'enrol_mentorsubscription'),
                'billing_cycle'         => $record->billing_cycle,
                'billed_price'          => number_format((float) $record->billed_price, 2),
                'billed_max_mentees'    => $record->billed_max_mentees,
                'period_start'          => userdate((int) $record->period_start),
                'period_end'            => userdate((int) $record->period_end),
                'stripe_invoice_id'     => $record->stripe_invoice_id ?? '—',
                'stripe_subscription_id' => $record->stripe_subscription_id ?? '—',
                'timecreated'           => userdate((int) $record->timecreated),
                'is_active'             => $record->status === 'active',
                'is_past_due'           => $record->status === 'past_due',
                'is_expired'            => in_array($record->status, ['expired', 'cancelled', 'superseded']),
            ];
        }

        return [
            'fullname'   => fullname($this->mentor),
            'userid'     => $this->mentor->id,
            'history'    => $rows,
            'has_history' => !empty($rows),
            'back_url'   => (new \moodle_url('/enrol/mentorsubscription/admin.php'))->out(false),
        ];
    }
}
