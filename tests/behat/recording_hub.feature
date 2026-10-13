@mod @mod_googlemeet @javascript
Feature: Interactions of the recording hub
  In order to study a recorded class
  As a student or a teacher
  I need the tabs, chapters, progress, study aids, practice player and management actions of the hub to respond in the browser

  Background:
    Given an offline Google issuer is configured for googlemeet
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity   | name       | course | idnumber |
      | googlemeet | Live class | C1     | gm1      |
    And the following "mod_googlemeet > recordings" exist:
      | googlemeet | name         | webviewlink                      | createdtime |
      | Live class | Tema 1 intro | https://example.com/recordings/1 | 1700000000  |
      | Live class | Tema 2 study | https://example.com/recordings/2 | 1700086400  |
    And the following "mod_googlemeet > aianalyses" exist:
      | recording    | summary                             | keypoints                                       | chapters                                                                                  | transcript                                                                                       | reviewed |
      | Tema 2 study | Elements of the administrative act. | ["Acts are presumed valid.","Defects can be cured."] | [{"title":"Opening remarks","start":"00:00"},{"title":"Curing defects","start":"14:02"}] | 00:00 Good morning, today we study the administrative act. 14:02 An administrative act can be cured. | 1        |

  Scenario: A student marks a recording as viewed
    Given I log in as "student1"
    And I open the recording hub of "Tema 1 intro" in googlemeet "Live class"
    And ".googlemeet-progress-badge-unseen" "css_element" should exist
    When I press "Mark as viewed"
    Then ".googlemeet-progress-badge-completed" "css_element" should exist
    And the "disabled" attribute of ".googlemeet-mark-viewed" "css_element" should be set
    And I should not see "Mark as viewed"
    And I reload the page
    And ".googlemeet-progress-badge-completed" "css_element" should exist
    And I should not see "Mark as viewed"

  Scenario: A teacher hides a recording from students without leaving the hub
    Given I log in as "teacher1"
    And I open the recording hub of "Tema 1 intro" in googlemeet "Live class"
    When I press "Hide from students"
    Then I should see "Show to students" in the ".googlemeet-hub-secondary-actions" "css_element"
    And I reload the page
    And I should see "Show to students" in the ".googlemeet-hub-secondary-actions" "css_element"

  Scenario: A teacher renames a recording from the hub
    Given I log in as "teacher1"
    And I open the recording hub of "Tema 1 intro" in googlemeet "Live class"
    When I click on "Edit" "button" in the ".googlemeet-hub-title" "css_element"
    Then the field "Name" matches value "Tema 1 intro"
    And I set the field "Name" to "Tema 1 renamed"
    And I click on "Save changes" "button" in the "Rename recording" "dialogue"
    And the "data-originalname" attribute of ".recording-name-text" "css_element" should contain "Tema 1 renamed"

  Scenario: On a phone the chapter list starts collapsed and the toggle opens and closes it
    Given I change viewport size to "480x800"
    And I log in as "student1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class"
    Then I should not see "Curing defects"
    When I click on "Show" "button" in the ".googlemeet-chapters-panel" "css_element"
    Then I should see "Curing defects"
    And I click on "Hide" "button" in the ".googlemeet-chapters-panel" "css_element"
    And I should not see "Curing defects"

  Scenario: Without an embedded player a chapter takes the teacher to the transcript
    Given I log in as "teacher1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class"
    When I click on "Curing defects, starts at 14:02" "button"
    Then I should see "Transcript moved to 14:02"
    And I should see "14:02" in the ".googlemeet-transcript-highlight" "css_element"

  Scenario: A teacher opens the transcript from a deep link and searches it
    Given I log in as "teacher1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class" with "#transcript"
    When I set the field "Search the transcript" to "administrative"
    Then I should see "2 matches"
    And ".googlemeet-transcript-match" "css_element" should exist
    And I set the field "Search the transcript" to "parliament"
    And I should see "No matches"
    And ".googlemeet-transcript-match" "css_element" should not exist

  Scenario: A student ticks a key point and finds it ticked after a reload
    Given I log in as "student1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class"
    And I should see "0 of 2 reviewed"
    When I click on "#googlemeet-keypoint-1" "css_element"
    Then I should see "1 of 2 reviewed"
    And I reload the page
    And I should see "1 of 2 reviewed"

  Scenario: A student practises the published questions from the summary and goes back to it
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem                                 | options                                          | correct |
      | Tema 2 study | Which body can cure a defective act? | The issuing body,Any court,The parliament,Nobody | 0       |
    And I log in as "student1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class"
    When I press "Start practice"
    Then I should see "Which body can cure a defective act?"
    And I click on "The issuing body" "text" in the ".googlemeet-practice-options" "css_element"
    And I press "Check"
    And I should see "Correct" in the ".googlemeet-practice-feedback" "css_element"
    And I press "Finish"
    And I should see "100%" in the ".googlemeet-practice-complete" "css_element"
    And I should see "1 of 1 correct"
    And I press "Back to the summary"
    And I should see "Start practice"

  Scenario: A deep link opens the Questions tab and loads the practice player
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem                                 | options                                          | correct |
      | Tema 2 study | Which body can cure a defective act? | The issuing body,Any court,The parliament,Nobody | 0       |
    And I log in as "student1"
    When I open the recording hub of "Tema 2 study" in googlemeet "Live class" with "#questions"
    Then I should see "Which body can cure a defective act?"
    And I should see "Question 1 of 1"

  Scenario: A teacher edits a draft question in the edit dialogue
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem                                 | options                                          | correct | ready |
      | Tema 2 study | Which body can cure a defective act? | The issuing body,Any court,The parliament,Nobody | 0       | 0     |
    And I log in as "teacher1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class" with "#questions"
    When I click on "Edit" "button" in the ".googlemeet-question-card" "css_element"
    Then the field "Question" matches value "Which body can cure a defective act?"
    And I set the field "Question" to "Which body may cure a defective act?"
    And I click on "Save changes" "button" in the "Edit" "dialogue"
    And I should see "Which body may cure a defective act?"

  Scenario: A teacher discards a draft question after confirming
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem                                 | options                                          | correct | ready |
      | Tema 2 study | Which body can cure a defective act? | The issuing body,Any court,The parliament,Nobody | 0       | 0     |
    And I log in as "teacher1"
    And I open the recording hub of "Tema 2 study" in googlemeet "Live class" with "#questions"
    When I click on "Discard" "button" in the ".googlemeet-question-card" "css_element"
    And I click on "Discard" "button" in the "Discard" "dialogue"
    Then I should see "Generate questions with AI to create drafts for teacher review."

  # The Drive preview iframe of this recording loads from drive.google.com: drop this scenario on a runner without network.
  Scenario: A student seeks the embedded player from a deep link, a chapter and a timestamp
    Given the following "mod_googlemeet > recordings" exist:
      | googlemeet | name          | webviewlink                                | createdtime |
      | Live class | Tema 3 player | https://drive.google.com/file/d/behat/view | 1700172800  |
    And the following "mod_googlemeet > aianalyses" exist:
      | recording     | summary                       | chapters                                                                                  | reviewed |
      | Tema 3 player | Review 14:02 before the test. | [{"title":"Opening remarks","start":"00:00"},{"title":"Curing defects","start":"14:02"}] | 1        |
    And I log in as "student1"
    And I open the recording hub of "Tema 3 player" in googlemeet "Live class" with "&t=842"
    Then I should see "Curing defects" in the ".googlemeet-chapter-active" "css_element"
    When I click on "Opening remarks, starts at 00:00" "button"
    Then I should see "Opening remarks" in the ".googlemeet-chapter-active" "css_element"
    And I should see "Video moved to 0:00"
    When I click on "Jump to 14:02 in the video" "button"
    Then I should see "Curing defects" in the ".googlemeet-chapter-active" "css_element"
