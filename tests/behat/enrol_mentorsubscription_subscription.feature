@enrol_mentorsubscription @enrol @javascript
Feature: Mentor subscription management
  As a mentor I can subscribe to a plan so that I can manage my mentees.
  As an admin I can view and manage all subscription plans.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | mentor1  | Alice     | Tutor    | mentor1@example.com |
    And the following "categories" exist:
      | name    | category | idnumber |
      | Cat1    | 0        | CAT1     |
    And the following "courses" exist:
      | shortname | fullname    | category |
      | COURSE1   | Test Course | CAT1     |
    And I log in as "admin"
    And I navigate to "Plugins > Enrolments > Mentor Subscription" in site administration
    And the following enrol_mentorsubscription sub_types exist:
      | name         | billing_cycle | price | default_max_mentees | stripe_price_id  | is_active |
      | Starter Plan | monthly       | 29.99 | 3                   | price_starter    | 1         |
      | Pro Plan     | annual        | 199   | 10                  | price_pro        | 1         |

  @M-4.7
  Scenario: Admin can create a new subscription type
    Given I navigate to "Plugins > Enrolments > Mentor Subscription" in site administration
    When I click on "Add Subscription Type" "link"
    And I set the following fields to these values:
      | Name                | Enterprise Plan |
      | Billing Cycle       | annual          |
      | Price               | 499.00          |
      | Default Max Mentees | 25              |
      | Stripe Price ID     | price_ent       |
    And I press "Save changes"
    Then I should see "Subscription type saved"
    And I should see "Enterprise Plan"

  @M-4.7
  Scenario: Admin can deactivate a subscription type
    Given I navigate to "Plugins > Enrolments > Mentor Subscription" in site administration
    When I click on "Deactivate" "link_or_button" in the "Starter Plan" "table_row"
    Then I should see "Subscription type updated"
    And I should see "Activate" in the "Starter Plan" "table_row"

  @M-2.0
  Scenario: Mentor views available subscription plans
    Given I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    Then I should see "Starter Plan"
    And I should see "29.99"
    And I should see "Pro Plan"

  @M-2.3
  Scenario: Mentor cannot subscribe when already subscribed
    Given mentor1 has an active subscription to "Starter Plan"
    And I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    Then I should not see "Subscribe" button next to "Starter Plan"
    And I should see my active subscription details
