@mod @mod_googlemeet @javascript
Feature: AI analysis panel and draft question review in the Google Meet activity
  In order to curate what students read about a class
  As a teacher
  I need to open the AI analysis of a recording, edit it by hand and review the draft questions

  # Only flows that read or save stored data: no step confirms a generation, so Gemini is never called.
  Background:
    Given an offline Google issuer is configured for googlemeet
    And the following config values are set as admin:
      | name         | value             | plugin     |
      | enableai     | 1                 | googlemeet |
      | geminiapikey | behat-offline-key | googlemeet |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name       | course | idnumber |
      | googlemeet | Live class | C1     | gm1      |
    And the following "mod_googlemeet > recordings" exist:
      | googlemeet | name         |
      | Live class | Tema 1 intro |

  Scenario: A teacher opens and closes the AI panel of a recording
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | summary                    | transcript            | aimodel          |
      | Tema 1 intro | Plain summary of the class | Hello class & welcome | gemini-2.5-flash |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "View or generate AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    Then ".googlemeet-ai-panel" "css_element" should be visible
    And I should see "Plain summary of the class" in the ".googlemeet-ai-panel" "css_element"
    And I should see "Hello class & welcome" in the ".googlemeet-ai-transcript-text" "css_element"
    And I should see "Regenerate" in the ".googlemeet-ai-panel" "css_element"
    When I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "View or generate AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    Then ".googlemeet-ai-panel" "css_element" should not be visible

  Scenario: Regenerating a hand-edited analysis asks for confirmation and can be cancelled
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | summary                    | aimodel |
      | Tema 1 intro | Plain summary of the class | manual  |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "View or generate AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    When I click on "Regenerate" "button" in the ".googlemeet-ai-panel" "css_element"
    Then I should see "This will replace the current summary" in the "Replace the current analysis?" "dialogue"
    And I should see "This analysis was edited by hand: those changes will be lost." in the "Replace the current analysis?" "dialogue"
    And I click on "Cancel" "button" in the "Replace the current analysis?" "dialogue"
    And I should see "Plain summary of the class" in the ".googlemeet-ai-panel" "css_element"
    And the ".googlemeet-ai-generate-btn" "css_element" should be enabled

  Scenario: The manual edit modal loads the stored analysis and saves the teacher's changes
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | summary                    | transcript            | aimodel          |
      | Tema 1 intro | Plain summary of the class | Hello class & welcome | gemini-2.5-flash |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "Edit AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    Then I should see "Edit AI Analysis" in the "#googlemeet-ai-edit-modal-title" "css_element"
    And the field "googlemeet-ai-edit-summary" matches value "Plain summary of the class"
    And the field "googlemeet-ai-edit-transcript" matches value "Hello class & welcome"
    When I set the field "googlemeet-ai-edit-summary" to "Summary edited by hand"
    And I click on "Save" "button" in the "#googlemeet-ai-edit-modal" "css_element"
    Then I should see "Analysis saved successfully"
    And I wait "2" seconds
    And I wait until the page is ready
    And I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "View or generate AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    And I should see "Summary edited by hand" in the ".googlemeet-ai-panel" "css_element"
    And I should not see "Plain summary of the class" in the ".googlemeet-ai-panel" "css_element"

  Scenario: Analysing a transcript in the edit modal needs a transcript and confirms the replacement
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | summary                    | transcript            | aimodel          |
      | Tema 1 intro | Plain summary of the class | Hello class & welcome | gemini-2.5-flash |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "Edit AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    And the field "googlemeet-ai-edit-transcript" matches value "Hello class & welcome"
    When I set the field "googlemeet-ai-edit-transcript" to ""
    And I click on "Analyze with Gemini" "button" in the "#googlemeet-ai-edit-modal" "css_element"
    Then I should see "Please paste a transcript first"
    When I set the field "googlemeet-ai-edit-transcript" to "A new transcript"
    And I click on "Analyze with Gemini" "button" in the "#googlemeet-ai-edit-modal" "css_element"
    Then I should see "This will replace" in the "Replace the current analysis?" "dialogue"
    And I click on "Cancel" "button" in the "Replace the current analysis?" "dialogue"
    And the field "googlemeet-ai-edit-summary" matches value "Plain summary of the class"

  Scenario: An analysis already in progress shows the background status and reports a stuck worker
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | status     | summary |
      | Tema 1 intro | processing |         |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "View or generate AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    And I should see "No AI analysis available yet." in the ".googlemeet-ai-panel" "css_element"
    When I click on "Generate AI Analysis" "button" in the ".googlemeet-ai-panel" "css_element"
    Then I should see "Analysis in progress" in the ".googlemeet-ai-processing" "css_element"
    And I should see "Queued since" in the ".googlemeet-ai-processing" "css_element"
    And I should not see "No AI analysis available yet." in the ".googlemeet-ai-panel" "css_element"
    And the ".googlemeet-ai-generate-btn" "css_element" should be disabled
    When the AI analysis of googlemeet recording "Tema 1 intro" is stuck
    And I click on "Check status" "button" in the ".googlemeet-ai-processing" "css_element"
    Then I should see "The analysis seems stuck" in the ".googlemeet-ai-error" "css_element"
    And ".googlemeet-ai-processing" "css_element" should not be visible
    And the ".googlemeet-ai-generate-btn" "css_element" should be enabled
    And I should see "Regenerate" in the ".googlemeet-ai-generate-btn" "css_element"

  Scenario: A failed analysis without subtitles offers a transcription from the video, which can be cancelled
    Given the following "mod_googlemeet > aianalyses" exist:
      | recording    | status     | summary |
      | Tema 1 intro | processing |         |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on ".googlemeet-row-actions-toggle" "css_element"
    And I click on "View or generate AI analysis" "button" in the ".googlemeet-row-actions-menu" "css_element"
    And I click on "Generate AI Analysis" "button" in the ".googlemeet-ai-panel" "css_element"
    And I should see "Analysis in progress" in the ".googlemeet-ai-processing" "css_element"
    When the AI analysis of googlemeet recording "Tema 1 intro" failed with "ai_subtitles_unavailable"
    And I click on "Check status" "button" in the ".googlemeet-ai-processing" "css_element"
    Then I should see "No auto-generated subtitles were found for this recording." in the ".googlemeet-ai-error" "css_element"
    And I should see "Generate AI Analysis" in the ".googlemeet-ai-generate-btn" "css_element"
    When I click on "Transcribe from video" "button" in the ".googlemeet-ai-error" "css_element"
    Then I should see "This will download the full video" in the "Transcribe from video" "dialogue"
    And I click on "Cancel" "button" in the "Transcribe from video" "dialogue"
    And I should see "Transcribe from video" in the ".googlemeet-ai-error" "css_element"

  Scenario: Selecting draft questions updates the bulk bar, and a discard removes only the selected one
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem            | options        | correct | ready |
      | Tema 1 intro | What is first?  | A1, B1, C1, D1 | 0       | 0     |
      | Tema 1 intro | What is second? | A2, B2, C2, D2 | 1       | 0     |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on "Open class" "link"
    And I click on "#googlemeet-questions-tab" "css_element"
    And the "Publish selected (0)" "button" should be disabled
    When I click on "#googlemeet-question-select-all" "css_element"
    Then I should see "Deselect all" in the ".googlemeet-question-bulkbar" "css_element"
    And the "Publish selected (2)" "button" should be enabled
    When I click on "#googlemeet-question-select-all" "css_element"
    Then I should see "Select all" in the ".googlemeet-question-bulkbar" "css_element"
    And I should not see "Deselect all" in the ".googlemeet-question-bulkbar" "css_element"
    And the "Discard selected" "button" should be disabled
    When I click on "What is first?" "checkbox"
    And I click on "Discard selected" "button"
    And I click on "Discard selected" "button" in the "Discard selected" "dialogue"
    And I wait "2" seconds
    And I wait until the page is ready
    And I click on "#googlemeet-questions-tab" "css_element"
    Then I should see "What is second?" in the ".googlemeet-question-list" "css_element"
    And I should not see "What is first?" in the ".googlemeet-question-list" "css_element"

  Scenario: Publishing one draft question publishes only that one
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem            | options        | correct | ready |
      | Tema 1 intro | What is first?  | A1, B1, C1, D1 | 0       | 0     |
      | Tema 1 intro | What is second? | A2, B2, C2, D2 | 1       | 0     |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on "Open class" "link"
    And I click on "#googlemeet-questions-tab" "css_element"
    And I should not see "Unpublish" in the ".googlemeet-question-list" "css_element"
    When I click on "Publish" "button" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' googlemeet-question-card ')][contains(., 'What is first?')]" "xpath_element"
    And I wait "2" seconds
    And I wait until the page is ready
    And I click on "#googlemeet-questions-tab" "css_element"
    Then I should see "Unpublish" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' googlemeet-question-card ')][contains(., 'What is first?')]" "xpath_element"
    And I should not see "Unpublish" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' googlemeet-question-card ')][contains(., 'What is second?')]" "xpath_element"

  Scenario: Publishing every draft of the class asks for confirmation first
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem            | options        | correct | ready |
      | Tema 1 intro | What is first?  | A1, B1, C1, D1 | 0       | 0     |
      | Tema 1 intro | What is second? | A2, B2, C2, D2 | 1       | 0     |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on "Open class" "link"
    And I click on "#googlemeet-questions-tab" "css_element"
    When I click on "Publish all for this class (2)" "button"
    Then I should see "The 2 draft questions of this class will be published" in the "Publish all for this class" "dialogue"
    And I click on "Publish all for this class" "button" in the "Publish all for this class" "dialogue"
    And I wait "2" seconds
    And I wait until the page is ready
    And I click on "#googlemeet-questions-tab" "css_element"
    Then I should not see "Publish all for this class" in the ".googlemeet-question-bulkbar" "css_element"
    And I should see "Unpublish" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' googlemeet-question-card ')][contains(., 'What is second?')]" "xpath_element"

  Scenario: Publishing every draft of the activity reports the outcome
    Given the following "mod_googlemeet > practicequestions" exist:
      | recording    | stem            | options        | correct | ready |
      | Tema 1 intro | What is first?  | A1, B1, C1, D1 | 0       | 0     |
    And I am on the "Live class" "googlemeet activity" page logged in as teacher1
    And I click on "Open class" "link"
    And I click on "#googlemeet-questions-tab" "css_element"
    When I click on "Publish all drafts in the activity (1)" "button"
    And I click on "Publish all draft questions in the activity" "button" in the "Publish all draft questions in the activity" "dialogue"
    And I wait "2" seconds
    And I wait until the page is ready
    And I click on "#googlemeet-questions-tab" "css_element"
    Then I should see "Unpublish" in the ".googlemeet-question-list" "css_element"
    And I should not see "Publish all drafts in the activity" in the "#googlemeet-questions-panel" "css_element"
