@core @core_question
Feature: Question bank name updates when course is renamed
  In order to keep question bank names consistent with the course
  As an editing teacher
  I need the default question bank name to update when the course is renamed

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry1    | Teacher1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname                              | shortname | category |
      | Old Course Name                       | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  @javascript
  Scenario: Default question bank name updates when course is renamed
    # Create the default question bank while the course has a temporary name.
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I navigate to "Question banks" in current page administration
    And I click on "Create default question bank" "button"
    Then I should see "Old Course Name course question bank"
    # Rename the course (simulating approval).
    And I am on the "C1" "Course" page
    And I navigate to "Settings" in current page administration
    And I set the following fields to these values:
      | Course full name | New Course Name |
    And I press "Save and display"
    # Verify the question bank name has been updated.
    When I navigate to "Question banks" in current page administration
    Then I should see "New Course Name course question bank"
    And I should not see "Old Course Name"

  @javascript
  Scenario: Custom question bank name is not changed when course is renamed
    # Create a question bank with a custom name (not derived from course name).
    Given the following "activities" exist:
      | activity | name                | course | section |
      | qbank    | My custom exam bank | C1     | 0       |
    And I am on the "C1" "Course" page logged in as "teacher1"
    # Rename the course.
    When I navigate to "Settings" in current page administration
    And I set the following fields to these values:
      | Course full name | New Course Name |
    And I press "Save and display"
    # Verify the custom bank name was NOT changed.
    And I navigate to "Question banks" in current page administration
    Then I should see "My custom exam bank"
