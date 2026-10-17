@local @local_embedpoll
Feature: Polls in a book and a section summary
  In order to gather opinions inside book chapters and course sections
  As a teacher
  I need polls embedded in them to show and run

  Background:
    Given the "embedpoll" filter is "on"
    And the following "courses" exist:
      | shortname | fullname |
      | C1        | Course 1 |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name      | course | section | idnumber |
      | book     | Test book | C1     | 1       | book1    |

  @javascript
  Scenario: Polls in different chapters are independent
    Given the following "mod_book > chapters" exist:
      | book      | title     | pagenum | content                                                                                     |
      | Test book | Chapter 1 | 1       | <p>Agree?</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div> |
      | Test book | Chapter 2 | 2       | <p>Agree?</p><div class="embedpoll-embed" data-embedpoll="bbbbbbbbbbbbbbbb"></div> |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options |
      | book1    | aaaaaaaaaaaaaaaa | Yes, No |
      | book1    | bbbbbbbbbbbbbbbb | Yes, No |
    When I am on the "Test book" "book activity" page logged in as "student1"
    And I click on "Yes" "button"
    Then "span.progress-bar" "css_element" should exist
    And I click on "Chapter 2" "link" in the "Table of contents" "block"
    And "Yes" "button" should exist
    And "span.progress-bar" "css_element" should not exist

  Scenario: Teachers always see results without voting
    Given the following "mod_book > chapters" exist:
      | book      | title     | content                                                                                     |
      | Test book | Chapter 1 | <p>Colour</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div> |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options    |
      | book1    | aaaaaaaaaaaaaaaa | Red, Green |
    When I am on the "Test book" "book activity" page logged in as "teacher1"
    Then "span.progress-bar" "css_element" should exist
    And "[data-action='poll-vote'][disabled]" "css_element" should exist
    And I should see "No votes yet"

  Scenario: A token copied in from another activity shows nothing to students
    Given the following "mod_book > chapters" exist:
      | book      | title     | content                                                                                     |
      | Test book | Chapter 1 | <p>Colour</p><div class="embedpoll-embed" data-embedpoll="cccccccccccccccc"></div> |
    When I am on the "Test book" "book activity" page logged in as "student1"
    Then I should see "Colour"
    And "[data-region='filter-embedpoll']" "css_element" should not exist
    And I log out
    And I am on the "Test book" "book activity" page logged in as "teacher1"
    And I should see "A poll was embedded here, but it belongs to another activity or has been removed."

  @javascript
  Scenario: A poll in a section summary
    Given the following "local_embedpoll > section polls" exist:
      | course | section | embedkey         | options    | summary                                                                                     |
      | C1     | 1       | aaaaaaaaaaaaaaaa | Up, Down   | <p>Week one</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div> |
    When I am on the "C1" "Course" page logged in as "student1"
    And I click on "Up" "button"
    Then "span.progress-bar" "css_element" should exist
    And I should see "1 vote"
