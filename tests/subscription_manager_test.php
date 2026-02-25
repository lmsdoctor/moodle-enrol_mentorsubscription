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
 * PHPUnit tests for subscription_manager.
 *
 * Covers: create_active_subscription, get_active_subscription, get_history,
 *         process_renewal, expire_subscription, snapshot immutability.
 *
 * M-6.7
 *
 * @package    enrol_mentorsubscription
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_mentorsubscription;

defined('MOODLE_INTERNAL') || die();

use enrol_mentorsubscription\subscription\subscription_manager;
use advanced_testcase;

/**
 * Tests for subscription_manager.
 *
 * @covers \enrol_mentorsubscription\subscription\subscription_manager
 */
class subscription_manager_test extends advanced_testcase {

    /** @var \stdClass Test mentor user. */
    private \stdClass $mentor;

    /** @var int Sub-type ID used for test subscriptions. */
    private int $subtypeId;

    /** @var subscription_manager SUT */
    private subscription_manager $manager;

    /**
     * Set up fresh state for each test.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->mentor = $this->getDataGenerator()->create_user();
        $this->manager = new subscription_manager();

        // Insert a minimal sub_type record so FK constraints are satisfied.
        $this->subtypeId = (int) $DB->insert_record('enrol_mentorsub_sub_types', (object) [
            'name'                => 'Monthly Test',
            'billing_cycle'       => 'monthly',
            'price'               => '29.99',
            'default_max_mentees' => 5,
            'stripe_price_id'     => 'price_test_monthly',
            'is_active'           => 1,
            'sort_order'          => 0,
            'timecreated'         => time(),
            'timemodified'        => time(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a synthetic active subscription for the test mentor.
     */
    private function createSubscription(
        float $price = 29.99,
        int $maxMentees = 5,
        string $cycle = 'monthly',
        int $periodOffset = 2592000  // 30 days
    ): int {
        $now = time();
        return $this->manager->create_active_subscription(
            $this->mentor->id,
            $this->subtypeId,
            $price,
            $maxMentees,
            $cycle,
            'sub_test_' . uniqid(),
            'cus_test_' . uniqid(),
            'price_test_monthly',
            $now,
            $now + $periodOffset
        );
    }

    // -------------------------------------------------------------------------
    // create_active_subscription
    // -------------------------------------------------------------------------

    /**
     * Creating a subscription returns a valid positive integer ID.
     */
    public function test_create_active_subscription_returns_id(): void {
        $id = $this->createSubscription();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    /**
     * The created record has status = 'active'.
     */
    public function test_create_active_subscription_sets_status_active(): void {
        global $DB;
        $id = $this->createSubscription();
        $record = $DB->get_record('enrol_mentorsub_subscriptions', ['id' => $id]);
        $this->assertEquals('active', $record->status);
    }

    /**
     * Snapshot fields are stored immutably (price and limit at time of creation).
     */
    public function test_create_active_subscription_snapshot_is_immutable(): void {
        global $DB;
        $id = $this->createSubscription(price: 99.00, maxMentees: 20);
        $record = $DB->get_record('enrol_mentorsub_subscriptions', ['id' => $id]);

        $this->assertEquals(99.00, (float) $record->billed_price);
        $this->assertEquals(20, (int) $record->billed_max_mentees);
    }

    // -------------------------------------------------------------------------
    // get_active_subscription
    // -------------------------------------------------------------------------

    /**
     * Returns null when the mentor has no active subscription.
     */
    public function test_get_active_subscription_returns_null_when_none(): void {
        $result = $this->manager->get_active_subscription($this->mentor->id);
        $this->assertNull($result);
    }

    /**
     * Returns the subscription record when one exists.
     */
    public function test_get_active_subscription_returns_record(): void {
        $id = $this->createSubscription();
        $result = $this->manager->get_active_subscription($this->mentor->id);
        $this->assertNotNull($result);
        $this->assertEquals($id, (int) $result->id);
    }

    /**
     * Returns null for a different user even when a subscription exists for the mentor.
     */
    public function test_get_active_subscription_is_user_scoped(): void {
        $this->createSubscription();
        $otherUser = $this->getDataGenerator()->create_user();
        $result = $this->manager->get_active_subscription($otherUser->id);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // get_history
    // -------------------------------------------------------------------------

    /**
     * Returns an empty array when no subscriptions exist.
     */
    public function test_get_history_returns_empty_array_when_none(): void {
        $result = $this->manager->get_history($this->mentor->id);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Returns all subscription records for the mentor, newest first.
     */
    public function test_get_history_returns_all_records_newest_first(): void {
        global $DB;
        $id1 = $this->createSubscription();
        // Simulate renewal creating a second record.
        $id2 = $this->createSubscription();

        // Force id2 to appear newer by setting a higher timecreated.
        $DB->set_field('enrol_mentorsub_subscriptions', 'timecreated', time() + 100, ['id' => $id2]);

        $history = $this->manager->get_history($this->mentor->id);
        $this->assertCount(2, $history);
        // Newest should be first (timecreated DESC).
        $this->assertEquals($id2, (int) $history[0]->id);
    }

    // -------------------------------------------------------------------------
    // process_renewal
    // -------------------------------------------------------------------------

    /**
     * process_renewal marks the previous subscription as 'superseded'.
     */
    public function test_process_renewal_supersedes_previous(): void {
        global $DB;
        $prevId = $this->createSubscription();
        $now    = time();

        $this->manager->process_renewal($prevId, [
            'userid'                => $this->mentor->id,
            'subtypeid'             => $this->subtypeId,
            'billed_price'          => 29.99,
            'billed_max_mentees'    => 5,
            'billing_cycle'         => 'monthly',
            'stripe_subscription_id' => 'sub_renew_' . uniqid(),
            'stripe_customer_id'    => 'cus_test',
            'stripe_price_id_used'  => 'price_test_monthly',
            'period_start'          => $now,
            'period_end'            => $now + 2592000,
        ]);

        $prev = $DB->get_record('enrol_mentorsub_subscriptions', ['id' => $prevId]);
        $this->assertEquals('superseded', $prev->status);
    }

    /**
     * process_renewal creates a new 'active' subscription record.
     */
    public function test_process_renewal_creates_new_active_record(): void {
        global $DB;
        $prevId = $this->createSubscription();
        $now    = time();

        $newId = $this->manager->process_renewal($prevId, [
            'userid'                => $this->mentor->id,
            'subtypeid'             => $this->subtypeId,
            'billed_price'          => 29.99,
            'billed_max_mentees'    => 5,
            'billing_cycle'         => 'monthly',
            'stripe_subscription_id' => 'sub_renew_' . uniqid(),
            'stripe_customer_id'    => 'cus_test',
            'stripe_price_id_used'  => 'price_test_monthly',
            'period_start'          => $now,
            'period_end'            => $now + 2592000,
        ]);

        $this->assertGreaterThan(0, $newId);
        $new = $DB->get_record('enrol_mentorsub_subscriptions', ['id' => $newId]);
        $this->assertEquals('active', $new->status);
    }

    // -------------------------------------------------------------------------
    // expire_subscription
    // -------------------------------------------------------------------------

    /**
     * expire_subscription sets status to 'expired'.
     */
    public function test_expire_subscription_sets_status_expired(): void {
        global $DB;
        $id = $this->createSubscription();
        $this->manager->expire_subscription($id);
        $record = $DB->get_record('enrol_mentorsub_subscriptions', ['id' => $id]);
        $this->assertEquals('expired', $record->status);
    }

    /**
     * expire_subscription deactivates all active mentees of that mentor.
     */
    public function test_expire_subscription_deactivates_mentees(): void {
        global $DB;
        $id     = $this->createSubscription();
        $mentee = $this->getDataGenerator()->create_user();

        // Insert an active mentee record directly (bypassing mentorship_manager to stay unit).
        $DB->insert_record('enrol_mentorsub_mentees', (object) [
            'mentorid'       => $this->mentor->id,
            'menteeid'       => $mentee->id,
            'subscriptionid' => $id,
            'is_active'      => 1,
            'timecreated'    => time(),
            'timemodified'   => time(),
        ]);

        $this->manager->expire_subscription($id);

        $menteeRec = $DB->get_record('enrol_mentorsub_mentees',
                                     ['mentorid' => $this->mentor->id, 'menteeid' => $mentee->id]);
        $this->assertEquals(0, (int) $menteeRec->is_active);
    }

    /**
     * expire_subscription throws if the subscription does not exist.
     */
    public function test_expire_subscription_throws_on_invalid_id(): void {
        $this->expectException(\dml_missing_record_exception::class);
        $this->manager->expire_subscription(999999);
    }
}
