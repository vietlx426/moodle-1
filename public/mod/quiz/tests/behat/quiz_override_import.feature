@mod @mod_quiz
Feature: Quiz overrides bulk import
  In order to efficiently manage quiz overrides for many users or groups
  As a teacher
  I need to be able to import overrides from a CSV file.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher  | Teacher   | One      | teacher@example.com  |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher  | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group 1 | C1     | G1       |
      | Group 2 | C1     | G2       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G2    |
    And the following "activities" exist:
      | activity | name      | course | idnumber |
      | quiz     | Test quiz | C1     | quiz1    |

  Scenario Outline: Teacher can see the import overrides button on the overrides page
    Given I am on the "Test quiz" "mod_quiz > <page>" page logged in as "teacher"
    Then I should see "<button>"

    Examples:
      | page            | button                 |
      | User overrides  | Import user overrides  |
      | Group overrides | Import group overrides |

  Scenario Outline: Import overrides form displays the upload element and template link
    Given I am on the "Test quiz" "mod_quiz > <page>" page logged in as "teacher"
    Then I should see "<heading>"
    And I should see "Override file"
    And I should see "Template file"

    Examples:
      | page                  | heading                |
      | User override import  | Import user overrides  |
      | Group override import | Import group overrides |

  Scenario: Cancelling the import form returns the teacher to the overrides page
    Given I am on the "Test quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I press "Cancel"
    Then I should see "User overrides"
    And I should see "Add user override"

  @javascript @_file_upload
  Scenario: Uploading a CSV with incorrect headers shows an error notification
    Given I am on the "Test quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/invalid_headers_import.csv" file to "Override file" filemanager
    And I press "Validate"
    Then I should see "Wrong header"

  @javascript @_file_upload
  Scenario: Uploading a valid user overrides CSV shows the preview with a success notification
    Given I create user override import CSV fixture "user_override_import.csv" with:
      | username | timeopen                | timeclose               | timelimit | attempts | password | set_password |
      | student1 | 2027-01-01 08:00 +00:00 | 2027-01-01 10:00 +00:00 | 3600      | 2        |          | 0            |
      | student2 | 2027-01-02 08:00 +00:00 | 2027-01-02 10:00 +00:00 | 7200      | 1        |          | 1            |
    And I am on the "Test quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/user_override_import.csv" file to "Override file" filemanager
    And I press "Validate"
    Then I should see "Import overrides preview"
    And I should see "CSV parsed correctly and all rows passed validation checks"
    And I should see "Student One"
    And I should see "Student Two"
    And I should see "Adding"

  @javascript @_file_upload
  Scenario: Uploading a user overrides CSV with invalid data shows an error notification in the preview
    Given I am on the "Test quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/invalid_data_user_import.csv" file to "Override file" filemanager
    And I press "Validate"
    Then I should see "Import overrides preview"
    And I should see "The CSV file contains errors that must be resolved before importing"

  @javascript @_file_upload
  Scenario: Confirming a valid user override import creates the overrides and shows success
    Given I create user override import CSV fixture "user_override_import.csv" with:
      | username | timeopen                | timeclose               | timelimit | attempts | password | set_password |
      | student1 | 2027-01-01 08:00 +00:00 | 2027-01-01 10:00 +00:00 | 3600      | 2        |          | 0            |
      | student2 | 2027-01-02 08:00 +00:00 | 2027-01-02 10:00 +00:00 | 7200      | 1        |          | 1            |
    And I am on the "Test quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/user_override_import.csv" file to "Override file" filemanager
    And I press "Validate"
    And I click on "Confirm" "button"
    And I click on "Yes" "button" in the "Confirmation" "dialogue"
    Then I should see "Overrides successfully imported."
    And I should see "Student One"
    And I should see "Student Two"

  @javascript @_file_upload
  Scenario: Uploading a valid group overrides CSV shows the preview with a success notification
    Given I create group override import CSV fixture "group_override_import.csv" with:
      | groupidnumber | groupname | timeopen                | timeclose               | timelimit | attempts | password | set_password |
      | G1            |           | 2027-01-01 08:00 +00:00 | 2027-01-01 10:00 +00:00 | 3600      | 2        |          | 0            |
      | G2            |           | 2027-01-02 08:00 +00:00 | 2027-01-02 10:00 +00:00 | 7200      | 1        |          | 1            |
    And I am on the "Test quiz" "mod_quiz > Group override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/group_override_import.csv" file to "Override file" filemanager
    And I press "Validate"
    And I pause
    Then I should see "Import overrides preview"
    And I should see "CSV parsed correctly and all rows passed validation checks"
    And I should see "Group 1"
    And I should see "Group 2"
    And I should see "Adding"

  @javascript @_file_upload
  Scenario: Confirming a valid group override import creates the overrides and shows success
    Given I create group override import CSV fixture "group_override_import.csv" with:
      | groupidnumber | groupname | timeopen                | timeclose               | timelimit | attempts | password | set_password |
      | G1            |           | 2027-01-01 08:00 +00:00 | 2027-01-01 10:00 +00:00 | 3600      | 2        |          | 0            |
      | G2            |           | 2027-01-02 08:00 +00:00 | 2027-01-02 10:00 +00:00 | 7200      | 1        |          | 1            |
    And I am on the "Test quiz" "mod_quiz > Group override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/group_override_import.csv" file to "Override file" filemanager
    And I press "Validate"
    And I pause
    And I click on "Confirm" "button"
    And I click on "Yes" "button" in the "Confirmation" "dialogue"
    Then I should see "Overrides successfully imported."
    And I should see "Group 1"
    And I should see "Group 2"

  @javascript @_file_upload
  Scenario: Importing a user override matching quiz defaults marks an existing override for deletion
    Given the following "activities" exist:
      | activity | name       | course | idnumber   | timelimit |
      | quiz     | Timed quiz | C1     | quiz_timed | 3600      |
    And the following "mod_quiz > user overrides" exist:
      | quiz       | user     | timelimit |
      | Timed quiz | student1 | 3600      |
    And I create user override import CSV fixture "same_as_default_user_import.csv" with:
      | username | timelimit |
      | student1 | 3600      |
    And I am on the "Timed quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/same_as_default_user_import.csv" file to "Override file" filemanager
    And I press "Validate"
    Then I should see "Import overrides preview"
    And I should see "CSV parsed correctly and all rows passed validation checks"
    And I should see "Deleting"

  @javascript @_file_upload
  Scenario: Importing a user override matching quiz defaults shows an error when no override exists
    Given the following "activities" exist:
      | activity | name       | course | idnumber   | timelimit |
      | quiz     | Timed quiz | C1     | quiz_timed | 3600      |
    And I create user override import CSV fixture "same_as_default_user_import.csv" with:
      | username | timelimit |
      | student1 | 3600      |
    And I am on the "Timed quiz" "mod_quiz > User override import" page logged in as "teacher"
    When I upload "mod/quiz/tests/behat/fixtures/same_as_default_user_import.csv" file to "Override file" filemanager
    And I press "Validate"
    Then I should see "Import overrides preview"
    And I should see "The CSV file contains errors that must be resolved before importing"
    And I should see "No override value found"
