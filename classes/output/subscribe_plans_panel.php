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
 * Renderable: subscribe_plans_panel
 *
 * Prepares the list of active subscription plans that a mentor can choose
 * from before being redirected to Stripe Checkout.
 *
 * Used by:
 *  - enrol_mentorsubscription_plugin::enrol_page_hook() — shown on the course
 *    page when an unenrolled user with the subscribe capability lands on it.
 *  - subscribe.php (plan selection step, no subtypeid param) — same renderable
 *    can be reused there to keep template rendering DRY.
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription\output;

defined('MOODLE_INTERNAL') || die();

use moodle_url;
use renderer_base;
use stdClass;

/**
 * Renderable data class for the subscription plan selection panel.
 *
 * Implements renderable + named_templatable (Moodle 4.1+) so $OUTPUT->render()
 * resolves the Mustache template via get_template_name() without relying on
 * class-name auto-discovery heuristics.
 */
class subscribe_plans_panel implements \renderable, \templatable {

    /** @var stdClass The enrolment instance this panel belongs to. */
    private stdClass $instance;

    /**
     * Optional course ID — when set via enrol_page_hook the "Subscribe" button
     * URL will carry a courseid param so subscribe.php can redirect back after
     * a successful checkout.
     *
     * @var int|null
     */
    private ?int $courseid;

    /**
     * @param stdClass $instance  Enrolment instance record from {enrol}.
     * @param int|null $courseid  Course ID, or null when called from subscribe.php directly.
     */
    public function __construct(stdClass $instance, ?int $courseid = null) {
        $this->instance = $instance;
        $this->courseid = $courseid ?? (isset($instance->courseid) ? (int)$instance->courseid : null);
    }

    /**
     * Exports data for templates/subscribe_plans.mustache.
     *
     * Fetches all active enrol_mentorsub_sub_types rows, orders them by price
     * ascending, and converts each into a flat context array containing the
     * pre-formatted price string and the Stripe Checkout redirect URL.
     *
     * @param renderer_base $output Moodle renderer (not used directly but
     *                              required by the templatable interface).
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;

        $subtypes = $DB->get_records(
            'enrol_mentorsub_sub_types',
            ['is_active' => 1],
            'price ASC'
        );

        $mode = get_config('enrol_mentorsubscription', 'stripe_mode') ?: 'live';

        if (empty($subtypes)) {
            return [
                'has_plans'    => false,
                'plans'        => [],
                'is_test_mode' => ($mode === 'sandbox'),
            ];
        }

        $plans = [];
        foreach ($subtypes as $type) {
            // Build the Stripe Checkout redirect URL.
            // subscribe.php will create the Stripe session when subtypeid != 0.
            // Pass returnurl so errors/cancellations bring the user back to the
            // course page they came from instead of the generic plans listing.
            $urlparams = ['subtypeid' => $type->id];
            if ($this->courseid) {
                $returnurl = (new moodle_url('/course/view.php', ['id' => $this->courseid]))
                    ->out_as_local_url(false);
                $urlparams['returnurl'] = $returnurl;
            }
            $subscribeurl = new moodle_url('/enrol/mentorsubscription/subscribe.php', $urlparams);

            $plans[] = [
                'id'            => (int) $type->id,
                'name'          => format_string($type->name),
                'price_display' => '$' . number_format((float) $type->price, 2),
                'billing_cycle' => s($type->billing_cycle),
                'max_mentees'   => (int) $type->default_max_mentees,
                'subscribe_url' => $subscribeurl->out(false),
                'is_popular'    => !empty($type->is_popular),
            ];
        }

        $mode = get_config('enrol_mentorsubscription', 'stripe_mode') ?: 'live';

        return [
            'has_plans'    => true,
            'plans'        => $plans,
            'is_test_mode' => ($mode === 'sandbox'),
        ];
    }
}
