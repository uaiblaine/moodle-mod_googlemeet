@mod @mod_googlemeet @javascript
Feature: Copy feedback for a chapter link
  In order to share a moment of a recorded class
  As a student
  I need the hub to tell me whether the link was copied or to give it to me to copy by hand

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity   | name       | course | idnumber |
      | googlemeet | Live class | C1     | gm1      |
    And the following "mod_googlemeet > recordings" exist:
      | googlemeet | name          | webviewlink                                | createdtime |
      | Live class | Tema 3 player | https://drive.google.com/file/d/behat/view | 1700172800  |
    And the following "mod_googlemeet > aianalyses" exist:
      | recording     | summary                       | chapters                                                                                  | reviewed |
      | Tema 3 player | Review 14:02 before the test. | [{"title":"Opening remarks","start":"00:00"},{"title":"Curing defects","start":"14:02"}] | 1        |

  Scenario: A copied chapter link is confirmed
    Given I log in as "student1"
    And I open the recording hub of "Tema 3 player" in googlemeet "Live class"
    And the browser clipboard accepts writes
    When I click on "Copy link to 14:02" "button"
    Then I should see "Link to 14:02 copied" in the "[data-region='chapter-feedback']" "css_element"

  Scenario: A refused clipboard write shows the link to copy by hand
    Given I log in as "student1"
    And I open the recording hub of "Tema 3 player" in googlemeet "Live class"
    And the browser clipboard refuses writes
    When I click on "Copy link to 14:02" "button"
    Then I should see "Copy this link:" in the "[data-region='chapter-feedback']" "css_element"
    And I should see "recording=" in the "[data-region='chapter-feedback']" "css_element"
