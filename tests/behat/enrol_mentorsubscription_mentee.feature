@enrol_mentorsubscription @enrol @javascript
Feature: Mentee management
  As a subscribed mentor I can add and remove mentees so that they enrol in my subscription courses.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | mentor1  | Alice     | Tutor    | mentor1@example.com |
      | mentee1  | Bob       | Learner  | mentee1@example.com |
      | mentee2  | Carol     | Student  | mentee2@example.com |
    And the following "courses" exist:
      | shortname | fullname           | category |
      | MENCOMP   | Mentor Course 1    | 0        |
    And the following enrol_mentorsubscription configuration exists:
      | managed_courses | MENCOMP |
    And the following enrol_mentorsubscription sub_types exist:
      | name     | billing_cycle | price | default_max_mentees | stripe_price_id | is_active |
      | Basic    | monthly       | 9.99  | 2                   | price_basic     | 1         |
    And mentor1 has an active subscription to "Basic"

  @M-3.1
  Scenario: Subscribed mentor can add a mentee
    Given I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I click on "Add Mentee" "button"
    And I set the field "Mentee username or email" to "mentee1"
    And I press "Add"
    Then I should see "Bob Learner" in the mentees list
    And I should see "1 / 2 mentees"

  @M-3.1 @M-3.5
  Scenario: Mentee is automatically enrolled in subscription courses after being added
    Given I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I click on "Add Mentee" "button"
    And I set the field "Mentee username or email" to "mentee1"
    And I press "Add"
    And I log out
    And I log in as "mentee1"
    Then I should see "Mentor Course 1" in my courses

  @M-3.4
  Scenario: Mentor can deactivate a mentee
    Given mentee1 is an active mentee of mentor1
    And I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    When I click on "Deactivate" "link_or_button" in the "Bob Learner" "table_row"
    Then I should see "Inactive" in the "Bob Learner" "table_row"

  @M-3.4 @M-3.6
  Scenario: Deactivated mentee is unenrolled from subscription courses
    Given mentee1 is an active mentee of mentor1
    And Bob Learner is enrolled in course "Mentor Course 1" through enrol_mentorsubscription
    When I log in as "mentor1"
    And I navigate to "/enrol/mentorsubscription/dashboard.php"
    And I click on "Deactivate" "link_or_button" in the "Bob Learner" "table_row"
    And I log out
    And I log in as "mentee1"
    Then I should not see "Mentor Course 1" in my courses
