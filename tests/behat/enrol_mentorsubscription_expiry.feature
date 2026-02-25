@enrol_mentorsubscription @enrol @javascript
Feature: Subscription expiry and grace period
  As an admin I need expired subscriptions to block new mentee additions,
  and the grace period setting to give past-due mentors time to pay
  before their mentees are automatically removed.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | mentor1  | Alice     | Tutor    | mentor1@example.com |
      | mentee1  | Bob       | Learner  | m1@example.com      |
    And the following "courses" exist:
      | shortname | fullname        | category |
      | MENCOMP   | Mentor Course 1 | 0        |
    And the following enrol_mentorsubscription configuration exists:
      | managed_courses      | MENCOMP |
      | pastdue_grace_days   | 3       |
    And the following enrol_mentorsubscription sub_types exist:
      | name  | billing_cycle | price | default_max_mentees | stripe_price_id | is_active |
      | Basic | monthly       | 9.99  | 5                   | price_basic     | 1         |
    And mentor1 has an active subscription to "Basic"
    And mentee1 is an active mentee of mentor1

  @M-5.2
  Scenario: Expired subscription blocks adding new mentees
    Given mentor1 subscription is expired
    And I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I click on "Add Mentee" "button"
    And I set the field "Mentee username or email" to "mentee1"
    And I press "Add"
    Then I should see "error_no_active_subscription"

  @M-5.2
  Scenario: Expired subscription shows renewal prompt on dashboard
    Given mentor1 subscription is expired
    And I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    Then I should see "Your subscription has expired"
    And I should see "Renew"

  @M-5.7
  Scenario: Past-due subscription within grace period keeps mentees enrolled
    Given mentor1 subscription is past_due and was updated 1 day ago
    And I log in as "admin"
    When the scheduled task "sync_stripe_subscriptions" runs
    Then mentee1 should still be enrolled in "Mentor Course 1"
    And mentor1 subscription status should be "past_due"

  @M-5.7
  Scenario: Past-due subscription beyond grace period expires and unenrols mentees
    Given mentor1 subscription is past_due and was updated 4 days ago
    And I log in as "admin"
    When the scheduled task "sync_stripe_subscriptions" runs
    Then mentee1 should not be enrolled in "Mentor Course 1"
    And mentor1 subscription status should be "expired"

  @M-5.3
  Scenario: Admin can view payment history for a mentor
    Given I log in as "admin"
    And I navigate to "Plugins > Enrolments > Mentor Subscription" in site administration
    When I click on "History" "link_or_button" in the "Alice Tutor" "table_row"
    Then I should see "Payment History"
    And I should see "Basic"
    And I should see "Back to admin panel"
