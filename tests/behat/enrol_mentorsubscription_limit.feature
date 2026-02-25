@enrol_mentorsubscription @enrol @javascript
Feature: Mentee limit enforcement
  As a subscribed mentor adding mentees beyond my plan limit should be blocked,
  and an admin override should allow temporary exceptions.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | mentor1  | Alice     | Tutor    | mentor1@example.com |
      | mentee1  | Bob       | L1       | m1@example.com      |
      | mentee2  | Carol     | L2       | m2@example.com      |
      | mentee3  | Dan       | L3       | m3@example.com      |
      | mentee4  | Eve       | L4       | m4@example.com      |
    And the following enrol_mentorsubscription sub_types exist:
      | name     | billing_cycle | price | default_max_mentees | stripe_price_id | is_active |
      | Tiny     | monthly       | 9.99  | 3                   | price_tiny      | 1         |
    And mentor1 has an active subscription to "Tiny"
    And the following mentees are assigned to mentor1:
      | user    |
      | mentee1 |
      | mentee2 |
      | mentee3 |

  @M-3.2
  Scenario: Adding a mentee beyond the plan limit is blocked
    Given I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I click on "Add Mentee" "button"
    And I set the field "Mentee username or email" to "mentee4"
    And I press "Add"
    Then I should see "error_limit_reached"
    And I should see "3 / 3 mentees"
    And "Eve L4" should not exist in the mentees list

  @M-4.3
  Scenario: Admin can set an override limit for a mentor
    Given I log in as "admin"
    And I navigate to "Plugins > Enrolments > Mentor Subscription" in site administration
    When I click on "Override" "link_or_button" in the "Alice Tutor" "table_row"
    And I set the field "Override max mentees" to "5"
    And I press "Save override"
    Then I should see "Override saved"
    And I should see "5" as the override for "Alice Tutor"

  @M-4.3
  Scenario: Mentor can add a mentee after admin grants override
    Given admin has set override for mentor1 to 5
    And I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I click on "Add Mentee" "button"
    And I set the field "Mentee username or email" to "mentee4"
    And I press "Add"
    Then I should see "Eve L4" in the mentees list
    And I should see "4 / 5 mentees"

  @M-3.4
  Scenario: Re-activating a mentee when limit is reached is blocked
    Given I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I change mentee1 to inactive
    And I try to reactivate mentee1
    Then I should see limit is reached notification
    And mentee3 should remain active and mentee1 should remain inactive after hitting limit attempt
