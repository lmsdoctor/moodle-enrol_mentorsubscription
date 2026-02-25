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
 * Custom Behat step definitions for enrol_mentorsubscription.
 *
 * @package    enrol_mentorsubscription
 * @category   test
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;

/**
 * Step definitions for enrol_mentorsubscription plugin Behat tests.
 */
class behat_enrol_mentorsubscription extends behat_base {

    // -------------------------------------------------------------------------
    // Data seeding steps
    // -------------------------------------------------------------------------

    /**
     * Creates enrol_mentorsubscription sub_type records from a table.
     *
     * @Given /^the following enrol_mentorsubscription sub_types exist:$/
     */
    public function the_following_enrol_mentorsubscription_sub_types_exist(TableNode $table): void {
        global $DB;
        foreach ($table->getHashes() as $row) {
            $row = (object) $row;
            $row->timecreated  = time();
            $row->timemodified = time();
            $DB->insert_record('enrol_mentorsub_sub_types', $row);
        }
    }

    /**
     * Seeds enrol_mentorsubscription configuration (managed_courses, grace days, etc.).
     *
     * @Given /^the following enrol_mentorsubscription configuration exists:$/
     */
    public function the_following_enrol_mentorsubscription_configuration_exists(TableNode $table): void {
        global $DB;
        foreach ($table->getRowsHash() as $key => $value) {
            if ($key === 'managed_courses') {
                // Resolve course shortname → id and insert into enrol_mentorsub_courses.
                foreach (explode(',', $value) as $shortname) {
                    $shortname = trim($shortname);
                    $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
                    if (!$DB->record_exists('enrol_mentorsub_courses', ['courseid' => $course->id])) {
                        $DB->insert_record('enrol_mentorsub_courses', (object) [
                            'courseid'  => $course->id,
                            'sortorder' => 0,
                        ]);
                    }
                } 
            } else {
                set_config($key, $value, 'enrol_mentorsubscription');
            }
        }
    }

    /**
     * Creates an active subscription for a user to a named sub_type.
     *
     * @Given /^(?P<username>[^ ]+) has an active subscription to "(?P<plan>[^"]+)"$/
     */
    public function user_has_active_subscription(string $username, string $plan): void {
        global $DB;
        $user    = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $subtype = $DB->get_record('enrol_mentorsub_sub_types', ['name' => $plan], '*', MUST_EXIST);

        $now = time();
        $DB->insert_record('enrol_mentorsub_subscriptions', (object) [
            'userid'           => $user->id,
            'subtypeid'        => $subtype->id,
            'stripe_sub_id'    => 'sub_test_' . $user->id,
            'stripe_cus_id'    => 'cus_test_' . $user->id,
            'stripe_price_id'  => $subtype->stripe_price_id,
            'status'           => 'active',
            'price_charged'    => $subtype->price,
            'billed_max_mentees' => $subtype->default_max_mentees,
            'billing_cycle'    => $subtype->billing_cycle,
            'period_start'     => $now,
            'period_end'       => $now + 2592000,
            'timecreated'      => $now,
            'timemodified'     => $now,
        ]);
    }

    /**
     * Creates an active mentee relationship between mentor and mentee.
     *
     * @Given /^(?P<mentee>[^ ]+) is an active mentee of (?P<mentor>[^ ]+)$/
     */
    public function user_is_active_mentee_of(string $menteeUsername, string $mentorUsername): void {
        global $DB;
        $mentor = $DB->get_record('user', ['username' => $mentorUsername], '*', MUST_EXIST);
        $mentee = $DB->get_record('user', ['username' => $menteeUsername], '*', MUST_EXIST);

        $sub = $DB->get_record('enrol_mentorsub_subscriptions',
                               ['userid' => $mentor->id, 'status' => 'active'],
                               '*', MUST_EXIST);
        $now = time();
        $DB->insert_record('enrol_mentorsub_mentees', (object) [
            'mentorid'       => $mentor->id,
            'menteeid'       => $mentee->id,
            'subscriptionid' => $sub->id,
            'is_active'      => 1,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ]);
    }

    /**
     * Seeds multiple mentees for a mentor from a table.
     *
     * @Given /^the following mentees are assigned to (?P<mentor>[^ ]+):$/
     */
    public function the_following_mentees_assigned_to_mentor(string $mentorUsername, TableNode $table): void {
        foreach ($table->getColumn(1) as $username) {
            if ($username === 'user') {
                continue; // skip header row
            }
            $this->user_is_active_mentee_of($username, $mentorUsername);
        }
    }

    /**
     * Sets admin override on a mentor.
     *
     * @Given /^admin has set override for (?P<mentor>[^ ]+) to (?P<limit>\d+)$/
     */
    public function admin_has_set_override_for(string $mentorUsername, int $limit): void {
        global $DB;
        $mentor = $DB->get_record('user', ['username' => $mentorUsername], '*', MUST_EXIST);
        $sub    = $DB->get_record('enrol_mentorsub_subscriptions',
                                  ['userid' => $mentor->id, 'status' => 'active'],
                                  '*', MUST_EXIST);
        $DB->set_field('enrol_mentorsub_subscriptions', 'admin_max_mentees_override', $limit,
                       ['id' => $sub->id]);
    }

    // -------------------------------------------------------------------------
    // Status manipulation steps
    // -------------------------------------------------------------------------

    /**
     * @Given /^mentor1 subscription is expired$/
     */
    public function mentor1_subscription_is_expired(): void {
        global $DB;
        $mentor = $DB->get_record('user', ['username' => 'mentor1'], '*', MUST_EXIST);
        $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'expired',
                       ['userid' => $mentor->id]);
    }

    /**
     * @Given /^mentor1 subscription is past_due and was updated (?P<days>\d+) day(?:s)? ago$/
     */
    public function mentor1_subscription_is_past_due_days_ago(int $days): void {
        global $DB;
        $mentor  = $DB->get_record('user', ['username' => 'mentor1'], '*', MUST_EXIST);
        $ago     = time() - ($days * DAYSECS);
        $DB->execute(
            "UPDATE {enrol_mentorsub_subscriptions} SET status = 'past_due', timemodified = ? WHERE userid = ?",
            [$ago, $mentor->id]
        );
    }

    // -------------------------------------------------------------------------
    // Assertion / verification steps
    // -------------------------------------------------------------------------

    /**
     * @Then /^mentor1 subscription status should be "(?P<status>[^"]+)"$/
     */
    public function mentor1_subscription_status_should_be(string $status): void {
        global $DB;
        $mentor = $DB->get_record('user', ['username' => 'mentor1'], '*', MUST_EXIST);
        $sub    = $DB->get_record('enrol_mentorsub_subscriptions', ['userid' => $mentor->id]);
        \PHPUnit\Framework\Assert::assertNotNull($sub, 'Subscription record not found');
        \PHPUnit\Framework\Assert::assertEquals($status, $sub->status,
            "Expected subscription status '{$status}', got '{$sub->status}'");
    }

    /**
     * @Then /^mentee1 should (?P<neg>(?:not )?)?be enrolled in "(?P<course>[^"]+)"$/
     */
    public function mentee1_should_be_enrolled_in(string $neg, string $courseName): void {
        global $DB;
        $mentee = $DB->get_record('user', ['username' => 'mentee1'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['fullname' => $courseName], '*', MUST_EXIST);
        $ctx    = \context_course::instance($course->id);
        $enrolled = is_enrolled($ctx, $mentee->id);
        if (trim($neg) === 'not') {
            \PHPUnit\Framework\Assert::assertFalse($enrolled,
                "Mentee should NOT be enrolled in '{$courseName}' but is.");
        } else {
            \PHPUnit\Framework\Assert::assertTrue($enrolled,
                "Mentee should be enrolled in '{$courseName}' but is not.");
        }
    }

    /**
     * @When /^the scheduled task "(?P<taskname>[^"]+)" runs$/
     */
    public function the_scheduled_task_runs(string $taskname): void {
        $taskClass = "\\enrol_mentorsubscription\\task\\{$taskname}";
        if (!class_exists($taskClass)) {
            throw new \coding_exception("Scheduled task class {$taskClass} not found.");
        }
        $task = new $taskClass();
        $task->execute();
    }
}
