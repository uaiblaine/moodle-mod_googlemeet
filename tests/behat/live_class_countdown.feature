@mod @mod_googlemeet @javascript
Feature: Live countdowns of the classroom hero
  In order to know when the next live class opens
  As a student
  I need the room button and the next-session line to count down in the browser

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

  Scenario: A class starting within half an hour shows an enabled room button with a countdown
    Given a googlemeet session of "Live class" starts in "600" seconds
    And I log in as "student1"
    When I am on the "Live class" "googlemeet activity" page
    Then I should see "Join the live class" in the ".googlemeet-room-cta-soon" "css_element"
    And I should see "Starts in" in the ".googlemeet-room-cta-countdown" "css_element"
    And I should not see "Started" in the ".googlemeet-room-cta-countdown" "css_element"
    And ".googlemeet-room-cta-disabled" "css_element" should not exist

  Scenario: A class starting later today shows a disabled room button and a coarse countdown
    Given a googlemeet session of "Live class" starts in "9000" seconds
    And I log in as "student1"
    When I am on the "Live class" "googlemeet activity" page
    Then ".googlemeet-room-cta-disabled" "css_element" should exist
    And ".googlemeet-room-cta-countdown" "css_element" should not exist
    And I should see "Starts in 2h" in the ".googlemeet-hero-countdown" "css_element"

  Scenario: The room button turns live when the countdown reaches zero
    Given a googlemeet session of "Live class" starts in "20" seconds
    And I log in as "student1"
    When I am on the "Live class" "googlemeet activity" page
    Then I wait until ".googlemeet-room-cta-live" "css_element" exists
    And I should see "LIVE · Join the class" in the ".googlemeet-room-cta-live" "css_element"
    And ".googlemeet-room-cta-countdown" "css_element" should not be visible
