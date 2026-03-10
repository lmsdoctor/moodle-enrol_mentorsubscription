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
 * Renderable: admin_subscription_panel
 *
 * Prepares all data required by templates/admin_panel.mustache.
 * Exposes: active mentor list, subscription types, and overrides summary.
 *
 * Full implementation: M-4.6 to M-4.10
 *
 * @package    enrol_mentorsubscription
 * @copyright 2023, LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;

/**
 * Renderable data class for the admin subscription management panel.
 */
class admin_subscription_panel implements renderable, templatable {

    /** @var array Active subscription types. */
    private array $subtypes;

    /** @var array Active mentor subscriptions with user data. */
    private array $activeMentors;

    /**
     * @param array $subtypes      Active subscription type records.
     * @param array $activeMentors Active mentor subscription records.
     */
    public function __construct(array $subtypes, array $activeMentors) {
        $this->subtypes      = $subtypes;
        $this->activeMentors = $activeMentors;
    }

    /**
     * Exports data for the Mustache template.
     *
     * Full implementation: M-4.6 to M-4.10
     *
     * @param renderer_base $output
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $subtypesCtx = [];
        foreach ($this->subtypes as $type) {
            $adminBase = (new \moodle_url('/enrol/mentorsubscription/admin'))->out(false);
            $subtypesCtx[] = [
                'id'               => $type->id,
                'name'             => format_string($type->name),
                'billing_cycle'    => $type->billing_cycle,
                'price'            => number_format((float) $type->price, 2),
                'max_mentees'      => $type->default_max_mentees,
                'stripe_price_id'  => $type->stripe_price_id,
                'is_active'        => (bool) $type->is_active,
                'edit_url'         => $adminBase . '?formaction=editsubtype&subtypeid=' . $type->id,
                'toggle_url'       => $adminBase . '?formaction=togglesubtype&subtypeid=' . $type->id
                                      . '&sesskey=' . sesskey(),
            ];
        }

        $mentorsCtx = [];
        foreach ($this->activeMentors as $sub) {
            $adminBase = (new \moodle_url('/enrol/mentorsubscription/admin'))->out(false);
            $profileBase = (new \moodle_url('/user/profile.php', ['id' => $sub->userid]))->out(false);
            $mentorsCtx[] = [
                'userid'         => $sub->userid,
                'subtypeid'      => $sub->subtypeid,
                'fullname'       => fullname($sub),
                'status'         => $sub->status,
                'status_label'     => get_string(
                                         'status_' . str_replace('_', '', $sub->status),
                                         'enrol_mentorsubscription'
                                      ),
                'billing_cycle'  => $sub->billing_cycle,
                'period_end'     => $this->fmtdate((int) $sub->period_end),
                'billed_price'   => number_format((float) $sub->billed_price, 2),
                'max_mentees'    => $sub->billed_max_mentees,
                'admin_url'      => $adminBase,
                'history_url'    => $adminBase . '?formaction=viewhistory&userid=' . $sub->userid,
                'profile_url'    => $profileBase,
                'is_active'        => $sub->status === 'active',
                'is_expired'       => in_array($sub->status, ['expired', 'cancelled', 'superseded']),
            ];
        }

        return [
            'subtypes'         => $subtypesCtx,
            'active_mentors'   => $mentorsCtx,
            'add_subtype_url'  => (new \moodle_url('/enrol/mentorsubscription/admin',
                                    ['formaction' => 'editsubtype']))->out(false),
        ];
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
