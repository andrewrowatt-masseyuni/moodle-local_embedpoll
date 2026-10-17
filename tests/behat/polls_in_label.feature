@local @local_embedpoll
Feature: Polls in a text and media area
  In order to gather opinions inline
  As a teacher
  I need polls embedded in a text and media area to show and run on the course page

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

  Scenario: Several polls in one text all show, with results hidden from students
    Given the following "activities" exist:
      | activity | name | course | idnumber | intro                                                                                                                                                                         | introformat |
      | label    | L1   | C1     | label1   | <p>Fruit</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div><p>Colour</p><div class="embedpoll-embed" data-embedpoll="bbbbbbbbbbbbbbbb"></div> | 1           |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options           |
      | label1   | aaaaaaaaaaaaaaaa | Apple, Banana     |
      | label1   | bbbbbbbbbbbbbbbb | Red, Green, Blue  |
    When I am on the "C1" "Course" page logged in as "student1"
    Then "//div[contains(@class,'filter-embedpoll')][.//button[contains(.,'Apple')]]" "xpath_element" should exist
    And "//div[contains(@class,'filter-embedpoll')][.//button[contains(.,'Green')]]" "xpath_element" should exist
    And "span.progress-bar" "css_element" should not exist
    And I should see "Select an option to vote and see the results."

  @javascript
  Scenario: Two polls with the same options are two polls
    Given the following "activities" exist:
      | activity | name | course | idnumber | intro                                                                                                                                                                         | introformat |
      | label    | L1   | C1     | label1   | <p>First</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div><p>Second</p><div class="embedpoll-embed" data-embedpoll="bbbbbbbbbbbbbbbb"></div> | 1           |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options |
      | label1   | aaaaaaaaaaaaaaaa | Yes, No |
      | label1   | bbbbbbbbbbbbbbbb | Yes, No |
    When I am on the "C1" "Course" page logged in as "student1"
    And I click on "(//div[contains(@class,'filter-embedpoll')])[1]//button[contains(.,'Yes')]" "xpath_element"
    Then "(//div[contains(@class,'filter-embedpoll')])[1]//span[contains(@class,'progress-bar')]" "xpath_element" should exist
    And "(//div[contains(@class,'filter-embedpoll')])[2]//span[contains(@class,'progress-bar')]" "xpath_element" should not exist

  @javascript
  Scenario: A student can vote and then change their vote
    Given the following "activities" exist:
      | activity | name | course | idnumber | intro                                                                                       | introformat |
      | label    | L1   | C1     | label1   | <p>Pick one</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div> | 1           |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options          |
      | label1   | aaaaaaaaaaaaaaaa | Red, Green, Blue |
    When I am on the "C1" "Course" page logged in as "student1"
    And I click on "Green" "button"
    Then "//button[contains(.,'Green')][@aria-pressed='true']" "xpath_element" should exist
    And "span.progress-bar" "css_element" should exist
    And I click on "Blue" "button"
    And "//button[contains(.,'Blue')][@aria-pressed='true']" "xpath_element" should exist
    And "//button[contains(.,'Green')][@aria-pressed='true']" "xpath_element" should not exist
    And I should see "1 vote"

  @javascript
  Scenario: Duplicating a text and media area gives the copy polls of its own
    Given the following "activities" exist:
      | activity | name | course | idnumber | intro                                                                                       | introformat |
      | label    | L1   | C1     | label1   | <p>Pick one</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div> | 1           |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options          |
      | label1   | aaaaaaaaaaaaaaaa | Red, Green, Blue |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I turn editing mode on
    And I duplicate "Pick one" activity
    And I log out
    When I am on the "C1" "Course" page logged in as "student1"
    And I click on "(//div[contains(@class,'filter-embedpoll')])[1]//button[contains(.,'Red')]" "xpath_element"
    Then "(//div[contains(@class,'filter-embedpoll')])[1]//span[contains(@class,'progress-bar')]" "xpath_element" should exist
    And "(//div[contains(@class,'filter-embedpoll')])[2]//span[contains(@class,'progress-bar')]" "xpath_element" should not exist

  @javascript
  Scenario: A compact poll marks the chosen option by its tint alone
    Given the following "activities" exist:
      | activity | name | course | idnumber | intro                                                                                       | introformat |
      | label    | L1   | C1     | label1   | <p>Pick one</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div> | 1           |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options          | compact |
      | label1   | aaaaaaaaaaaaaaaa | Red, Green, Blue | 1       |
    When I am on the "C1" "Course" page logged in as "student1"
    Then ".filter-embedpoll.embedpoll-compact" "css_element" should exist
    And ".filter-embedpoll i.fa-circle" "css_element" should not exist
    And I click on "Green" "button"
    And "//button[contains(@class,'embedpoll-selected')][contains(.,'Green')][@aria-pressed='true']" "xpath_element" should exist
    And I should see "1 vote"
    And "span.progress-bar" "css_element" should not exist
    And ".filter-embedpoll i.fa-circle-check" "css_element" should not exist

  @javascript
  Scenario: A poll shows a percentage, a number of votes, or nothing beside each option
    Given the following "activities" exist:
      | activity | name | course | idnumber | intro                                                                                                                                                                 | introformat |
      | label    | L1   | C1     | label1   | <p>Counts</p><div class="embedpoll-embed" data-embedpoll="aaaaaaaaaaaaaaaa"></div><p>Nothing</p><div class="embedpoll-embed" data-embedpoll="bbbbbbbbbbbbbbbb"></div> | 1           |
    And the following "local_embedpoll > polls" exist:
      | activity | embedkey         | options   | stats |
      | label1   | aaaaaaaaaaaaaaaa | Yes, No   | count |
      | label1   | bbbbbbbbbbbbbbbb | Red, Blue | none  |
    When I am on the "C1" "Course" page logged in as "student1"
    And I click on "Yes" "button"
    And I click on "Red" "button"
    Then "//button[contains(.,'Yes')]//span[contains(@class,'embedpoll-option-stat')][contains(.,'1 vote')]" "xpath_element" should exist
    And "(//div[contains(@class,'filter-embedpoll')])[1]//span[contains(@class,'embedpoll-option-stat')][contains(.,'%')]" "xpath_element" should not exist
    And "(//div[contains(@class,'filter-embedpoll')])[2]//span[contains(@class,'embedpoll-option-stat')]" "xpath_element" should not exist
    And "(//div[contains(@class,'filter-embedpoll')])[2]//span[contains(@class,'progress-bar')]" "xpath_element" should exist
