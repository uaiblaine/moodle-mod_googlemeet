@mod @mod_googlemeet @javascript
Feature: Interactions of the recordings list and the practice session
  In order to find and manage recorded classes without leaving the activity page
  As a student or a teacher
  I need the live filters, the inline row actions and the practice player to respond in the browser

  Background:
    Given an offline Google issuer is configured for googlemeet
    And the following config values are set as admin:
      | enableai     | 1        | googlemeet |
      | geminiapikey | test-key | googlemeet |
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
      | googlemeet | name         | createdtime |
      | Live class | Tema 1 intro | 1700000000  |

  Scenario: Typing in the search filters the list after a pause and keeps the caret in the field
    Given the following "mod_googlemeet > recordings" exist:
      | googlemeet | name          | createdtime |
      | Live class | Tema 2 repaso | 1700086400  |
    And I am on the "Live class" "googlemeet activity" page logged in as student1
    And I should see "Tema 1 intro"
    When I set the field "Search classes" to "repaso"
    Then ".googlemeet-filters-clear" "css_element" should exist
    And I should see "Tema 2 repaso"
    And I should not see "Tema 1 intro"
    And the field "Search classes" matches value "repaso"
    And the focused element is "Search classes" "field"

  Scenario: Choosing a topic filters the list at once
    Given the following "mod_googlemeet > recordings" exist:
      | googlemeet | name          | createdtime |
      | Live class | Tema 2 repaso | 1700086400  |
    And the following "mod_googlemeet > aianalyses" exist:
      | recording     | topics     | reviewed |
      | Tema 2 repaso | ["Tema A"] | 1        |
    And I am on the "Live class" "googlemeet activity" page logged in as student1
    When I set the field "topic" to "Tema A"
    Then ".googlemeet-filters-clear" "css_element" should exist
    And I should see "Tema 2 repaso"
    And I should not see "Tema 1 intro"
    And the field "topic" matches value "Tema A"

  Scenario: The filters panel is open on desktop and collapses on a phone
    Given I am on the "Live class" "googlemeet activity" page logged in as student1
    Then the "open" attribute of ".googlemeet-filters-panel" "css_element" should be set
    And ".googlemeet-filterbar-live" "css_element" should exist
    When I change viewport size to "480x800"
    Then the "open" attribute of ".googlemeet-filters-panel" "css_element" should not be set

  Scenario: The sort toggle reverses the order of the classes
    Given the following "mod_googlemeet > recordings" exist:
      | googlemeet | name          | createdtime |
      | Live class | Tema 2 repaso | 1700086400  |
    And I am on the "Live class" "googlemeet activity" page logged in as student1
    # Read the order inside the list only: the page header names the latest class too.
    Then the "aria-current" attribute of "Newest first" "link" should contain "true"
    And "//div[contains(@class,'googlemeet-recordings-listview')]//*[text()[contains(.,'Tema 2 repaso')]]" "xpath_element" should appear before "//div[contains(@class,'googlemeet-recordings-listview')]//*[text()[contains(.,'Tema 1 intro')]]" "xpath_element"
    When I click on "Oldest first" "link"
    Then the "aria-current" attribute of "Oldest first" "link" should contain "true"
    And "//div[contains(@class,'googlemeet-recordings-listview')]//*[text()[contains(.,'Tema 1 intro')]]" "xpath_element" should appear before "//div[contains(@class,'googlemeet-recordings-listview')]//*[text()[contains(.,'Tema 2 repaso')]]" "xpath_element"

  Scenario: A teacher renames a class inline with Enter
    Given I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on "Actions" "button" in the "#googlemeet_recordings_table" "css_element"
    And I click on "Rename" "button" in the "#googlemeet_recordings_table" "css_element"
    Then the focused element is ".googlemeet-edit-input" "css_element"
    When I type "Tema 1 renamed"
    And I press the enter key
    Then I should see "Tema 1 renamed"
    And ".googlemeet-edit-input" "css_element" should not exist
    And I reload the page
    And I should see "Tema 1 renamed"

  Scenario: Escape leaves the inline rename without saving
    Given I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on "Actions" "button" in the "#googlemeet_recordings_table" "css_element"
    And I click on "Rename" "button" in the "#googlemeet_recordings_table" "css_element"
    And I type "Discarded name"
    And I press the escape key
    Then ".googlemeet-edit-input" "css_element" should not exist
    And I should see "Tema 1 intro"
    And I should not see "Discarded name"

  Scenario: A teacher hides a class from students without reloading
    Given I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on "Actions" "button" in the "#googlemeet_recordings_table" "css_element"
    And I click on "Hide from students" "button" in the "#googlemeet_recordings_table" "css_element"
    Then I should see "Hidden from students"
    And ".googlemeet-recording-hidden" "css_element" should exist
    And I reload the page
    And I click on "Actions" "button" in the "#googlemeet_recordings_table" "css_element"
    And I should see "Show to students" in the ".googlemeet-row-actions-menu" "css_element"
    And I am on the "Live class" "googlemeet activity" page logged in as student1
    And I should not see "Tema 1 intro"

  Scenario: Moving a class to the trash asks first, and the trash restores it
    Given I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on "Actions" "button" in the "#googlemeet_recordings_table" "css_element"
    And I click on "Move to trash" "button" in the "#googlemeet_recordings_table" "css_element"
    Then I should see "This recording will be moved to the trash. You can restore it later." in the "Delete" "dialogue"
    And I click on "Cancel" "button" in the "Delete" "dialogue"
    And I should not see "Trash (1)"
    When I click on "Actions" "button" in the "#googlemeet_recordings_table" "css_element"
    And I click on "Move to trash" "button" in the "#googlemeet_recordings_table" "css_element"
    And I click on "Delete" "button" in the "Delete" "dialogue"
    Then I should see "Trash (1)"
    When I click on ".googlemeet-recordings-trash summary" "css_element"
    And I click on "Restore" "button" in the ".googlemeet-recordings-trash-panel" "css_element"
    Then ".googlemeet-recordings-trash" "css_element" should not exist
    And I should see "Tema 1 intro"

  Scenario: Deleting a class permanently from the trash asks first
    Given the following "mod_googlemeet > recordings" exist:
      | googlemeet | name          | deleted | timedeleted |
      | Live class | Old recording | 1       | 1700000000  |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on ".googlemeet-recordings-trash summary" "css_element"
    And I click on "Delete permanently" "button" in the ".googlemeet-recordings-trash-panel" "css_element"
    And I click on "Cancel" "button" in the "Delete permanently" "dialogue"
    Then I should see "Old recording" in the ".googlemeet-recordings-trash-panel" "css_element"
    When I click on "Delete permanently" "button" in the ".googlemeet-recordings-trash-panel" "css_element"
    And I click on "Delete permanently" "button" in the "Delete permanently" "dialogue"
    Then ".googlemeet-recordings-trash" "css_element" should not exist
    And I should see "Tema 1 intro"

  Scenario: A student answers a practice session by topic, reviews the miss and retries
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | topics     | reviewed |
      | Tema 1 intro | ["Tema A"] | 1        |
    And the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem                 | options                     | correct |
      | Tema 1 intro | Capital of Portugal? | Madrid, Lisboa, Paris, Roma | 1       |
    And I log in as "student1"
    When I visit the practice session of googlemeet "Live class" for topic "Tema A"
    Then I should see "Capital of Portugal?"
    And I should see "Question 1 of 1"
    And the "disabled" attribute of "Check" "button" should be set
    When I click on "Madrid" "text" in the ".googlemeet-practice-options" "css_element"
    And I press "Check"
    Then I should see "Incorrect" in the ".googlemeet-practice-feedback" "css_element"
    And I should see "Lisboa" in the ".googlemeet-practice-feedback" "css_element"
    And ".googlemeet-practice-option-incorrect" "css_element" should exist
    When I press "Finish"
    Then I should see "0 of 1 correct"
    When I press "Review missed questions (1)"
    Then I should see "Reviewing missed questions"
    When I click on "Lisboa" "text" in the ".googlemeet-practice-options" "css_element"
    And I press "Check"
    Then ".googlemeet-practice-feedback.alert-success" "css_element" should exist
    When I press "Finish"
    Then I should see "1 of 1 correct"
    When I press "Repeat all"
    Then I should see "Question 1 of 1"
    And the "disabled" attribute of "Check" "button" should be set
