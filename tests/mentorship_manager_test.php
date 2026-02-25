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
 * PHPUnit tests for mentorship_manager.
 *
 * Covers: add_mentee (success, no subscription, limit reached, duplicate),
 *         toggle_mentee_status (activate, deactivate, limit on activate),
 *         count_active_mentees, get_mentees.
 *
 * M-6.7
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

use enrol_mentorsubscription\mentorship\mentorship_manager;
use enrol_mentorsubscription\subscription\subscription_manager;
use advanced_testcase;

/**
 * Tests for mentorship_manager.
 *
 * @covers \enrol_mentorsubscription\mentorship\mentorship_manager
 */
class mentorship_manager_test extends advanced_testcase {

    /** @var \stdClass Test mentor. */
    private \stdClass $mentor;

    /** @var mentorship_manager SUT */
    private mentorship_manager $manager;

    /** @var int Active subscription ID seeded for the mentor. */
    private int $subscriptionId;

    /** @var int Sub-type ID. */
    private int $subtypeId;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->mentor  = $this->getDataGenerator()->create_user();
        $this->manager = new mentorship_manager();

        // Seed sub_type.
        $this->subtypeId = (int) $DB->insert_record('enrol_mentorsub_sub_types', (object) [
            'name'                => 'Test Plan',
            'billing_cycle'       => 'monthly',
            'price'               => '29.99',
            'default_max_mentees' => 3,
            'stripe_price_id'     => 'price_test',
            'is_active'           => 1,
            'sort_order'          => 0,
            'timecreated'         => time(),
            'timemodified'        => time(),
        ]);

        // Seed an active subscription (limit = 3 mentees).
        $now = time();
        $subManager = new subscription_manager();
        $this->subscriptionId = $subManager->create_active_subscription(
            $this->mentor->id,
            $this->subtypeId,
            29.99, 3, 'monthly',
            'sub_test', 'cus_test', 'price_test',
            $now, $now + 2592000
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Add a mentee row directly (bypasses event/enrol to keep tests fast).
     */
    private function seedMenteeRecord(int $menteeid, int $isActive = 1): \stdClass {
        global $DB;
        $now = time();
        $id  = (int) $DB->insert_record('enrol_mentorsub_mentees', (object) [
            'mentorid'       => $this->mentor->id,
            'menteeid'       => $menteeid,
            'subscriptionid' => $this->subscriptionId,
            'is_active'      => $isActive,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ]);
        return $DB->get_record('enrol_mentorsub_mentees', ['id' => $id]);
    }

    // -------------------------------------------------------------------------
    // add_mentee — failure paths
    // -------------------------------------------------------------------------

    /**
     * Throws when mentor has no active subscription.
     *
     * M-3.1 validation step 1
     */
    public function test_add_mentee_throws_when_no_subscription(): void {
        global $DB;
        // Remove the active subscription.
        $DB->set_field('enrol_mentorsub_subscriptions', 'status', 'expired',
                       ['id' => $this->subscriptionId]);

        $mentee = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_no_active_subscription');
        $this->manager->add_mentee($this->mentor->id, $mentee->id);
    }

    /**
     * Throws when mentor has reached the mentee limit.
     *
     * M-3.2
     */
    public function test_add_mentee_throws_when_limit_reached(): void {
        // Fill 3 slots (billed_max_mentees = 3).
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->seedMenteeRecord($u->id);
        }

        $extra = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_limit_reached');
        $this->manager->add_mentee($this->mentor->id, $extra->id);
    }

    /**
     * Throws when mentee does not exist in Moodle.
     *
     * M-3.3 (user not found)
     */
    public function test_add_mentee_throws_when_user_not_found(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_mentee_not_found');
        $this->manager->add_mentee($this->mentor->id, 999999);
    }

    /**
     * Throws when mentee is already assigned to a different mentor.
     *
     * M-3.3 (uniqueness)
     */
    public function test_add_mentee_throws_when_mentee_already_assigned(): void {
        global $DB;
        $mentee        = $this->getDataGenerator()->create_user();
        $otherMentor   = $this->getDataGenerator()->create_user();

        // Seed an existing assignment, but for a different mentor.
        $DB->insert_record('enrol_mentorsub_mentees', (object) [
            'mentorid'       => $otherMentor->id,
            'menteeid'       => $mentee->id,
            'subscriptionid' => $this->subscriptionId,
            'is_active'      => 1,
            'timecreated'    => time(),
            'timemodified'   => time(),
        ]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_mentee_already_assigned');
        $this->manager->add_mentee($this->mentor->id, $mentee->id);
    }

    // -------------------------------------------------------------------------
    // count_active_mentees
    // -------------------------------------------------------------------------

    /**
     * Returns 0 when no mentees exist.
     */
    public function test_count_active_mentees_returns_zero_when_empty(): void {
        $count = $this->manager->count_active_mentees($this->mentor->id);
        $this->assertEquals(0, $count);
    }

    /**
     * Counts only active mentees, not inactive ones.
     */
    public function test_count_active_mentees_excludes_inactive(): void {
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();

        $this->seedMenteeRecord($u1->id, 1); // active
        $this->seedMenteeRecord($u2->id, 0); // inactive

        $count = $this->manager->count_active_mentees($this->mentor->id);
        $this->assertEquals(1, $count);
    }

    // -------------------------------------------------------------------------
    // toggle_mentee_status
    // -------------------------------------------------------------------------

    /**
     * Deactivating an active mentee succeeds and returns success = true.
     *
     * M-3.4
     */
    public function test_toggle_mentee_deactivate_succeeds(): void {
        global $DB;
        $mentee = $this->getDataGenerator()->create_user();
        $this->seedMenteeRecord($mentee->id, 1);

        $result = $this->manager->toggle_mentee_status($this->mentor->id, $mentee->id, 0);

        $this->assertTrue($result['success']);
        $this->assertEquals('deactivated', $result['reason']);

        $rec = $DB->get_record('enrol_mentorsub_mentees',
                               ['mentorid' => $this->mentor->id, 'menteeid' => $mentee->id]);
        $this->assertEquals(0, (int) $rec->is_active);
    }

    /**
     * Activating a mentee when under the limit succeeds.
     *
     * M-3.4
     */
    public function test_toggle_mentee_activate_succeeds_under_limit(): void {
        global $DB;
        $mentee = $this->getDataGenerator()->create_user();
        $this->seedMenteeRecord($mentee->id, 0); // currently inactive

        $result = $this->manager->toggle_mentee_status($this->mentor->id, $mentee->id, 1);

        $this->assertTrue($result['success']);

        $rec = $DB->get_record('enrol_mentorsub_mentees',
                               ['mentorid' => $this->mentor->id, 'menteeid' => $mentee->id]);
        $this->assertEquals(1, (int) $rec->is_active);
    }

    /**
     * Activation fails (success = false, reason = limitreached) when at limit.
     *
     * M-3.4
     */
    public function test_toggle_mentee_activate_fails_at_limit(): void {
        // Fill 3 active slots (billed_max_mentees = 3).
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->seedMenteeRecord($u->id, 1);
        }

        // Add an inactive 4th.
        $extra = $this->getDataGenerator()->create_user();
        $this->seedMenteeRecord($extra->id, 0);

        $result = $this->manager->toggle_mentee_status($this->mentor->id, $extra->id, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('limitreached', $result['reason']);
    }

    /**
     * Returns failure with reason 'notfound' for unknown mentee.
     */
    public function test_toggle_mentee_returns_notfound_for_unknown_mentee(): void {
        $u = $this->getDataGenerator()->create_user();
        $result = $this->manager->toggle_mentee_status($this->mentor->id, $u->id, 0);
        $this->assertFalse($result['success']);
        $this->assertEquals('notfound', $result['reason']);
    }

    // -------------------------------------------------------------------------
    // get_mentees
    // -------------------------------------------------------------------------

    /**
     * Returns an array ordered by lastname ASC.
     */
    public function test_get_mentees_returns_ordered_list(): void {
        global $DB;
        $u1 = $this->getDataGenerator()->create_user(['lastname' => 'Zorro']);
        $u2 = $this->getDataGenerator()->create_user(['lastname' => 'Ames']);

        $this->seedMenteeRecord($u1->id);
        $this->seedMenteeRecord($u2->id);

        $mentees = $this->manager->get_mentees($this->mentor->id);

        $this->assertCount(2, $mentees);
        $this->assertEquals($u2->id, (int) $mentees[0]->menteeid); // Ames first
        $this->assertEquals($u1->id, (int) $mentees[1]->menteeid); // Zorro second
    }
}
