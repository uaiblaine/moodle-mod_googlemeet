<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat step definitions for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps for mod_googlemeet.
 */
class behat_mod_googlemeet extends behat_base {
    // phpcs:disable moodle.Files.LineLength -- A step definition regex cannot be wrapped.
    /**
     * Visit a plugin script of an activity with an explicit recording id (e.g. a stale link).
     *
     * @Given /^I visit the "(?P<script_string>[^"]*)" script of googlemeet "(?P<name_string>[^"]*)" with recording id "(?P<recording_string>\d+)"$/
     * @param string $script view.php or material.php
     * @param string $name Activity name.
     * @param string $recordingid Recording id to request.
     */
    public function i_visit_script_of_googlemeet_with_recording(string $script, string $name, string $recordingid): void {
        global $DB;
        if (!in_array($script, ['view.php', 'material.php'], true)) {
            throw new Exception('Unsupported script ' . $script);
        }
        $instance = $DB->get_record('googlemeet', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $instance->id, $instance->course, false, MUST_EXIST);
        $this->getSession()->visit($this->locate_path('/mod/googlemeet/' . $script . '?id=' . $cm->id .
            '&recording=' . $recordingid));
    }
    // phpcs:enable

    /**
     * Configure an offline Google OAuth 2 issuer (no network) so the recordings list is rendered.
     *
     * Without an enabled issuer the activity page only shows the live-class block.
     *
     * @Given /^an offline Google issuer is configured for googlemeet$/
     */
    public function an_offline_google_issuer_is_configured(): void {
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'Google (behat)', 'image' => '', 'baseurl' => 'https://accounts.google.com',
            'clientid' => 'behat-client', 'clientsecret' => 'behat-secret', 'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email', 'loginparams' => '',
            'loginparamsoffline' => 'access_type=offline&prompt=consent', 'alloweddomains' => '', 'enabled' => 1,
            'showonloginpage' => 0, 'basicauth' => 0, 'servicetype' => 'google',
        ]);
        $issuer->create();
        foreach (
            [
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            ] as $name => $url
        ) {
            (new \core\oauth2\endpoint(0, (object)['issuerid' => $issuer->get('id'), 'name' => $name, 'url' => $url]))->create();
        }
        set_config('issuerid', $issuer->get('id'), 'googlemeet');
    }

    // phpcs:disable moodle.Files.LineLength -- A step definition regex cannot be wrapped.
    /**
     * Open the activity-wide practice session of one topic (practice.php?mode=topic).
     *
     * @Given /^I visit the practice session of googlemeet "(?P<name_string>[^"]*)" for topic "(?P<topic_string>[^"]*)"$/
     * @param string $name Activity name.
     * @param string $topic Topic, as the AI analysis lists it.
     */
    public function i_visit_practice_session_of_googlemeet_for_topic(string $name, string $topic): void {
        global $DB;
        $instance = $DB->get_record('googlemeet', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $instance->id, $instance->course, false, MUST_EXIST);
        $url = new moodle_url('/mod/googlemeet/practice.php', ['id' => $cm->id, 'mode' => 'topic', 'topic' => $topic]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }
    // phpcs:enable

    // phpcs:disable moodle.Files.LineLength -- A step definition regex cannot be wrapped.
    /**
     * Leave the AI analysis of a recording in 'processing' since long before the stuck threshold.
     *
     * Simulates a background worker that died, without running any task or calling Gemini.
     *
     * @Given /^the AI analysis of googlemeet recording "(?P<recording_string>[^"]*)" is stuck$/
     * @param string $recording Recording name.
     */
    public function the_ai_analysis_of_googlemeet_recording_is_stuck(string $recording): void {
        $this->rewrite_ai_analysis($recording, ['status' => 'processing', 'error' => null, 'timemodified' => 1]);
    }

    /**
     * Mark the AI analysis of a recording as failed with a stored error.
     *
     * Simulates the outcome of a background worker, without running any task or calling Gemini.
     *
     * @Given /^the AI analysis of googlemeet recording "(?P<recording_string>[^"]*)" failed with "(?P<error_string>[^"]*)"$/
     * @param string $recording Recording name.
     * @param string $error Stored error (a message, or a special code such as ai_subtitles_unavailable).
     */
    public function the_ai_analysis_of_googlemeet_recording_failed_with(string $recording, string $error): void {
        $this->rewrite_ai_analysis($recording, ['status' => 'failed', 'error' => $error, 'timemodified' => time()]);
    }
    // phpcs:enable

    /**
     * Overwrite fields of the AI analysis row of a recording.
     *
     * @param string $recording Recording name (the first match when names repeat).
     * @param array $fields Column => value.
     * @throws Exception When the recording or its analysis does not exist.
     */
    protected function rewrite_ai_analysis(string $recording, array $fields): void {
        global $DB;
        $recordingid = $DB->get_field('googlemeet_recordings', 'id', ['name' => $recording], IGNORE_MULTIPLE);
        if (!$recordingid) {
            throw new Exception('The recording "' . $recording . '" does not exist');
        }
        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);
        if (!$analysis) {
            throw new Exception('The recording "' . $recording . '" has no AI analysis');
        }
        foreach ($fields as $field => $value) {
            $analysis->$field = $value;
        }
        $DB->update_record('googlemeet_ai_analysis', $analysis);
    }

    // phpcs:disable moodle.Files.LineLength -- A step definition regex cannot be wrapped.
    /**
     * Open the lesson hub of one recording (view.php?id=..&recording=..).
     *
     * @Given /^I open the recording hub of "(?P<recording_string>[^"]*)" in googlemeet "(?P<name_string>[^"]*)"$/
     * @param string $recording Recording name.
     * @param string $name Activity name.
     */
    public function i_open_the_recording_hub_of_in_googlemeet(string $recording, string $name): void {
        $this->i_open_the_recording_hub_of_in_googlemeet_with($recording, $name, '');
    }

    /**
     * Open the lesson hub of one recording with a suffix: more parameters ("&t=842") or a tab fragment ("#questions").
     *
     * @Given /^I open the recording hub of "(?P<recording_string>[^"]*)" in googlemeet "(?P<name_string>[^"]*)" with "(?P<suffix_string>[^"]*)"$/
     * @param string $recording Recording name.
     * @param string $name Activity name.
     * @param string $suffix Text appended to the hub URL as it is.
     */
    public function i_open_the_recording_hub_of_in_googlemeet_with(string $recording, string $name, string $suffix): void {
        global $DB;
        $instance = $DB->get_record('googlemeet', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $instance->id, $instance->course, false, MUST_EXIST);
        $recordingid = $DB->get_field(
            'googlemeet_recordings',
            'id',
            ['googlemeetid' => $instance->id, 'name' => $recording],
            MUST_EXIST
        );
        $url = new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'recording' => $recordingid]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false) . $suffix));
    }
    // phpcs:enable

    // phpcs:disable moodle.Files.LineLength -- A step definition regex cannot be wrapped.
    /**
     * Create a one-hour session of an activity that starts a number of seconds from now.
     *
     * @Given /^a googlemeet session of "(?P<name_string>[^"]*)" starts in "(?P<seconds_number>\d+)" seconds$/
     * @param string $name Activity name.
     * @param int $seconds Seconds from now until the session starts.
     */
    public function a_googlemeet_session_starts_in(string $name, int $seconds): void {
        global $DB;
        $instance = $DB->get_record('googlemeet', ['name' => $name], '*', MUST_EXIST);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $instance->id,
            'eventdate' => time() + $seconds,
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);
    }

    /**
     * Replace navigator.clipboard.writeText in the open page, so the copy feedback does not depend on the browser's permission.
     *
     * @Given /^the browser clipboard (?P<outcome_string>accepts|refuses) writes$/
     * @param string $outcome accepts (the promise resolves) or refuses (it rejects, as a denied permission does).
     */
    public function the_browser_clipboard_writes(string $outcome): void {
        $result = $outcome === 'accepts' ? 'Promise.resolve()' : "Promise.reject(new Error('denied'))";
        $this->getSession()->executeScript(
            "Object.defineProperty(navigator, 'clipboard', {configurable: true, " .
            "value: {writeText: function() { return " . $result . "; }}});"
        );
    }
    // phpcs:enable
}
