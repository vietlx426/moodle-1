@qbank @qbank_managecategories @javascript
Feature: Category links on the manage categories page always show that category's own questions
  In order to trust the question bank navigation
  As a teacher
  I need clicking a category link to show that category's questions, not a stale filter left over from a previous action

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | weeks  |
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | qbank    | Qbank 1 | C1     | qbank1   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "question categories" exist:
      | contextlevel    | reference | name       |
      | Activity module | qbank1    | Category A |
      | Activity module | qbank1    | Category B |
    And the following "questions" exist:
      | questioncategory | qtype     | name       | questiontext         |
      | Category A       | truefalse | Question A | Answer this question |
    And I log in as "teacher1"

  Scenario: Clicking a category link after moving a question shows that category's own questions
    Given I am on the "Qbank 1" "core_question > question bank" page
    And I apply question bank filter "Category" with value "Category A"
    And I click on "Question A" "checkbox"
    And I click on "With selected" "button"
    And I press "Move to"
    And I open the autocomplete suggestions list in the ".search-categories" "css_element"
    And I click on "Category B" item in the autocomplete list
    And I press "Move questions"
    And I click on "Confirm" "button"
    And I wait until the page is ready
    And I click on "Questions" "text" in the "#tertiary-navigation" "css_element"
    And I click on "Categories" "list_item"
    And I wait until the page is ready
    When I click on "Category A" "link" in the "Category A" "list_item"
    Then I should not see "Question A"
