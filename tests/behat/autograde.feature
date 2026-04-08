@qtype @qtype_aitext
Feature: Configure automatic AI feedback generation for aitext questions
  As a teacher
  I want to control whether AI feedback is generated automatically on submission
  So that I can choose to trigger it manually when needed

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |

  @javascript
  Scenario: The autograde checkbox is checked by default when creating a new aitext question
    Given I am on the "Course 1" "core_question > course question bank" page logged in as "teacher1"
    When I press "Create a new question ..."
    And I set the field "AI Text" to "1"
    And I click on "Add" "button" in the "Choose a question type to add" "dialogue"
    Then the field "Automatic AI feedback" matches value "1"

  @javascript
  Scenario: Teacher can disable automatic AI feedback when editing a question
    Given I am on the "Course 1" "core_question > course question bank" page logged in as "teacher1"
    When I press "Create a new question ..."
    And I set the field "AI Text" to "1"
    And I click on "Add" "button" in the "Choose a question type to add" "dialogue"
    And I set the following fields to these values:
      | Question name        | AI Autograde Test |
      | Question text        | What is 2+2?      |
      | AI prompt            | Grade this answer  |
      | Default mark         | 1                  |
    And I set the field "Automatic AI feedback" to ""
    And I press "id_submitbutton"
    Then I should see "AI Autograde Test"
