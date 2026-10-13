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

namespace mod_googlemeet;

use DateTime;
use mod_googlemeet\local\recordings;
use moodle_url;
use dml_missing_record_exception;
use moodle_exception;
use stdClass;

/**
 * Google OAuth client for mod_googlemeet: Calendar event creation and Drive recording sync as the current user.
 *
 * @package     mod_googlemeet
 * @copyright   2023 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /**
     * OAuth 2 client
     * @var \core\oauth2\client
     */
    private $client = null;

    /**
     * OAuth 2 Issuer
     * @var \core\oauth2\issuer
     */
    private $issuer = null;

    /**
     * @var bool|null Memoised result of check_login() for this instance (null = not checked yet).
     */
    private $loggedin = null;

    /**
     * Cached Google Docs listing for notes during a sync.
     * @var array|null
     */
    private $notesdocscache = null;

    /** @var int Defensive page cap for Google Drive list calls. */
    private const DRIVE_LIST_MAX_PAGES = 10;

    /** @var int Max allowed distance between a recording and its Gemini notes doc. */
    private const NOTES_PROXIMITY_SECONDS = 36 * 3600;

    /** @var bool False when googlemeet/issuerid names no enabled OAuth issuer. */
    public $enabled = true;

    /**
     * Google scopes always requested: Drive (recordings, transcripts, notes) and Calendar events.
     */
    const SCOPES = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/calendar.events';

    /**
     * Scopes requested from Google.
     *
     * The Meet REST API read-only scope is only requested while real attendance is enabled
     * for the site, so sites that do not use it never ask teachers for it.
     *
     * @return string Space-separated scopes.
     */
    public static function get_scopes(): string {
        $scopes = self::SCOPES;
        if (\mod_googlemeet\local\attendance\scope::site_enabled()) {
            $scopes .= ' ' . \mod_googlemeet\local\attendance\scope::MEET_SCOPE;
        }
        return $scopes;
    }

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct() {
        try {
            $this->issuer = \core\oauth2\api::get_issuer(get_config('googlemeet', 'issuerid'));
        } catch (dml_missing_record_exception $e) {
            $this->enabled = false;
        }

        if ($this->issuer && (!$this->issuer->get('enabled') || $this->issuer->get('id') == 0)) {
            $this->enabled = false;
        }

        // Only build the OAuth client (and validate the issuer host) when the plugin is
        // actually configured. Calling get_user_oauth_client() with a null issuer — e.g. in a
        // cron context where issuerid is unset — would otherwise error.
        if ($this->enabled) {
            $client = $this->get_user_oauth_client();
            if ($client->get_login_url()->get_host() !== 'accounts.google.com') {
                throw new moodle_exception('invalidissuerid', 'googlemeet');
            }
        }
    }

    /**
     * Get a cached user authenticated oauth client.
     *
     * @return \core\oauth2\client
     */
    protected function get_user_oauth_client() {
        if ($this->client) {
            return $this->client;
        }

        $returnurl = new moodle_url('/mod/googlemeet/callback.php');
        $returnurl->param('callback', 'yes');
        $returnurl->param('sesskey', sesskey());

        $this->client = \core\oauth2\api::get_user_oauth_client($this->issuer, $returnurl, self::get_scopes(), true);

        return $this->client;
    }

    /**
     * Meet REST API attendance source authenticated as the current user.
     *
     * @return \mod_googlemeet\local\attendance\meet_api_source
     */
    public function get_attendance_source(): \mod_googlemeet\local\attendance\meet_api_source {
        return new \mod_googlemeet\local\attendance\meet_api_source(
            new \mod_googlemeet\local\attendance\meet_rest($this->get_user_oauth_client())
        );
    }

    /**
     * Escape a value for safe interpolation inside a Google Drive query string literal.
     *
     * Drive query terms such as `name contains "..."` or `name = "..."` use double-quoted
     * string literals. Values controlled by the teacher (activity name, recording filter,
     * meeting code, recording filename) must have any backslash and double-quote escaped,
     * otherwise a `"` would break out of the literal and let the value alter the query
     * (query injection). See https://developers.google.com/drive/api/guides/ref-search-terms.
     *
     * @param string $value The raw value to embed inside a double-quoted Drive query literal.
     * @return string The escaped value (without surrounding quotes).
     */
    private function drive_quote($value) {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
    }

    /**
     * Extract the Google Meet code from a room URL.
     *
     * @param string $url Room URL.
     * @return string|null The lower-case meeting code, or null when the URL does not contain one.
     */
    public static function extract_meeting_code(string $url): ?string {
        if (preg_match('~meet\.google\.com/([a-z]{3}-[a-z]{4}-[a-z]{3})~i', $url, $matches)) {
            return \core_text::strtolower($matches[1]);
        }

        return null;
    }

    /**
     * List all Drive files for a query, following nextPageToken with a defensive cap.
     *
     * @param object $service The REST service.
     * @param array $params Drive files.list parameters.
     * @return array Accumulated files from all retrieved pages.
     */
    protected function list_all_pages($service, array $params): array {
        $files = [];
        $pagetoken = null;
        $pages = 0;

        do {
            $requestparams = $params;
            if ($pagetoken !== null && $pagetoken !== '') {
                $requestparams['pageToken'] = $pagetoken;
            }

            $response = helper::request($service, 'list', $requestparams, false);
            $files = array_merge($files, $response->files ?? []);
            $pagetoken = $response->nextPageToken ?? null;
            $pages++;

            if ($pagetoken && $pages >= self::DRIVE_LIST_MAX_PAGES) {
                $message = 'mod_googlemeet: Drive list pagination stopped after ' .
                    self::DRIVE_LIST_MAX_PAGES . ' pages for query: ' . ($params['q'] ?? '');
                debugging($message, DEBUG_DEVELOPER);
                if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
                    mtrace($message);
                }
                break;
            }
        } while (!empty($pagetoken));

        return $files;
    }

    /**
     * Build the Drive parent query for the localised Meet Recordings folders.
     *
     * @param object $service The REST service.
     * @return string Drive query fragment, or empty string when no folder was found.
     */
    private function get_meet_recordings_parents_query($service): string {
        // Search for Meet Recordings folder in multiple languages.
        // Google localises the auto-created folder name to the account language.
        $folderparams = [
            'q' => '(name = "Meet Recordings" or name contains "Registros de reuniones") and
                    trashed = false and
                    mimeType = "application/vnd.google-apps.folder" and
                    "me" in owners',
            'pageSize' => 1000,
            'fields' => 'nextPageToken, files(id,owners)',
        ];

        $folders = $this->list_all_pages($service, $folderparams);
        $parents = '';
        $folderscount = count($folders);
        for ($i = 0; $i < $folderscount; $i++) {
            $parents .= 'parents="' . $this->drive_quote($folders[$i]->id) . '"';
            if ($i + 1 < $folderscount) {
                $parents .= ' or ';
            }
        }

        return $parents;
    }

    /**
     * Print the login in a popup.
     *
     * @param array|null $attr Custom attributes to be applied to popup div.
     *
     * @return string HTML code
     */
    public function print_login_popup($attr = null) {
        global $OUTPUT, $PAGE;

        $client = $this->get_user_oauth_client();
        $loginurl = $client->get_login_url();

        // No inline onclick: the URL travels in a data-* attribute (escaped by Mustache) and the
        // mod_googlemeet/google_login module opens it, so no URL value is interpolated into inline JS.
        // It must stay a window.open() popup: callback.php reloads the opener and closes the popup.
        $buttonid = 'googlemeet_login_' . random_string(12);
        $PAGE->requires->js_call_amd('mod_googlemeet/google_login', 'init', [$buttonid]);

        return $OUTPUT->render_from_template('mod_googlemeet/google_login_button', [
            'buttonid' => $buttonid,
            'loginurl' => $loginurl->out(false),
        ]);
    }

    /**
     * Print user info.
     *
     * @param string|null $scope 'calendar' or 'drive' Defines which link will be used.
     *
     * @return string HTML code
     */
    public function print_user_info($scope = null) {
        global $OUTPUT, $PAGE;

        if (!$this->check_login()) {
            return '';
        }

        $userinfo = $this->get_cached_userinfo();
        if ($userinfo === null) {
            return '';
        }

        $username = $userinfo['username'];
        $name = $userinfo['name'];
        $userpicture = $userinfo['picture'];

        $userurl = '#';
        if ($scope == 'calendar') {
            $userurl = 'https://calendar.google.com/';
        }
        if ($scope == 'drive') {
            $userurl = 'https://drive.google.com/';
        }

        $logouturl = new moodle_url($PAGE->url);
        $logouturl->param('logout', true);
        $logouturl->param('sesskey', sesskey());

        return $OUTPUT->render_from_template('mod_googlemeet/google_user_info', [
            'userurl' => $userurl,
            'picture' => $userpicture,
            'name' => $name,
            'username' => $username,
            'logouturl' => $logouturl->out(false),
            'logouticon' => $OUTPUT->pix_icon('logout', '', 'googlemeet', ['class' => 'm-0']),
        ]);
    }

    /**
     * Whether the current user has a valid Google login (memoised for this instance).
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        if ($this->loggedin === null) {
            $client = $this->get_user_oauth_client();
            $this->loggedin = (bool)$client->is_logged_in();
            if (!$this->loggedin) {
                self::purge_userinfo_cache();
            }
        }
        return $this->loggedin;
    }

    /**
     * Cache key for the current Moodle user + OAuth issuer.
     *
     * The key includes the Moodle user id because cron tasks switch users (set_user) within a
     * single session; the issuer id keeps entries apart if the configured issuer changes.
     *
     * @return string
     */
    private static function userinfo_cache_key(): string {
        global $USER;
        return 'u' . (int)($USER->id ?? 0) . '_i' . (int)get_config('googlemeet', 'issuerid');
    }

    /**
     * Drop the cached Google userinfo for the current user (logout, new link, expired token).
     *
     * @return void
     */
    public static function purge_userinfo_cache(): void {
        \cache::make('mod_googlemeet', 'userinfo')->delete(self::userinfo_cache_key());
    }

    /**
     * Linked Google account info, cached in the session.
     *
     * Avoids the userinfo + avatar HTTP requests on every page load of a linked teacher.
     * Entries live for an hour (cache TTL) and are purged on logout/relink.
     *
     * @return array|null ['username' => email, 'name' => full name, 'picture' => base64 jpeg] or null.
     */
    public function get_cached_userinfo(): ?array {
        if (!$this->check_login()) {
            return null;
        }

        $cache = \cache::make('mod_googlemeet', 'userinfo');
        $key = self::userinfo_cache_key();
        $cached = $cache->get($key);
        if (is_array($cached) && isset($cached['username'])) {
            return $cached;
        }

        $userinfo = $this->get_user_oauth_client()->get_userinfo();
        if (empty($userinfo) || !is_array($userinfo)) {
            return null;
        }

        $data = [
            'username' => (string)($userinfo['username'] ?? ''),
            'name' => trim(($userinfo['firstname'] ?? '') . ' ' . ($userinfo['lastname'] ?? '')),
            'picture' => !empty($userinfo['picture']) ? base64_encode($userinfo['picture']) : '',
        ];
        $cache->set($key, $data);
        return $data;
    }

    /**
     * Logout.
     *
     * @return void
     */
    public function logout() {
        global $PAGE;

        if ($this->check_login()) {
            $url = new moodle_url($PAGE->url);
            $client = $this->get_user_oauth_client();
            $client->log_out();
            $this->loggedin = null;
            self::purge_userinfo_cache();

            // End the request with a page that navigates back to $PAGE->url. out(false) gives the URL
            // without HTML entities, and json_encode() keeps it from breaking out of the script literal.
            $jsurl = json_encode($url->out(false));
            $html = '<!DOCTYPE html><html><head><script type="text/javascript">' .
                    'window.location = ' . $jsurl . ';' .
                    '</script></head><body></body></html>';
            die($html);
        }
    }

    /**
     * Store the access token.
     *
     * @return void
     */
    public function callback() {
        $client = $this->get_user_oauth_client();
        // A (possibly different) Google account is being linked: forget any cached userinfo.
        self::purge_userinfo_cache();
        $this->loggedin = null;
        // This will upgrade to an access token if we have an authorization code and save the access token in the session.
        $client->is_logged_in();
    }

    /**
     * Google REST service authenticated as the current user, for {@see \mod_googlemeet\local\calendar_sync}.
     *
     * @return rest
     */
    public function get_rest_service(): rest {
        return new rest($this->get_user_oauth_client());
    }

    /**
     * Create a meeting event in Google Calendar
     *
     * @param object $googlemeet An object from the form.
     *
     * @return object Google Calendar event
     */
    public function create_meeting_event($googlemeet) {
        global $USER;

        $calendarid = 'primary';

        // Same body as the edit sync, so times are formatted in the timezone actually sent as timeZone
        // (not the server's) and the summary is the plain activity name: Meet names the recordings
        // after it, and the Drive sync matches them by that name.
        $timezone = \core_date::get_user_timezone($USER);
        $eventrawpost = \mod_googlemeet\local\calendar_sync::build_event_body($googlemeet, $timezone);
        if (empty($eventrawpost['recurrence'])) {
            unset($eventrawpost['recurrence']);
        }

        $service = new rest($this->get_user_oauth_client());

        $eventparams = [
            'calendarid' => $calendarid,
        ];

        $eventresponse = helper::request($service, 'insertevent', $eventparams, json_encode($eventrawpost));

        $conferenceparams = [
            'calendarid' => $calendarid,
            'eventid' => $eventresponse->id,
            'conferenceDataVersion' => 1,
        ];

        $conferencerawpost = [
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => $eventresponse->id,
                ],
            ],
        ];

        $conferenceresponse = helper::request($service, 'createconference', $conferenceparams, json_encode($conferencerawpost));

        return $conferenceresponse;
    }

    /**
     * Get recordings from Google Drive and sync with database.
     *
     * @param object $googlemeet An object instance.
     * @param bool $noredirect When true, skip the final redirect() call and return stats.
     *                         Required for background/cron contexts (no $PAGE, no HTTP).
     * @param bool $deferenrichment When true, only upsert Drive metadata and queue enrichment.
     *
     * @return array|void Stats array ['inserted','updated','deleted','trashed','restored','found'] when $noredirect=true;
     *                    nothing when the user is not logged in to Google.
     */
    public function syncrecordings($googlemeet, $noredirect = false, bool $deferenrichment = false) {
        global $PAGE, $DB, $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

        if ($this->check_login()) {
            $service = new rest($this->get_user_oauth_client());

            $parents = $this->get_meet_recordings_parents_query($service);
            $meetingcode = self::extract_meeting_code((string)($googlemeet->url ?? ''));
            if ($meetingcode === null) {
                debugging('mod_googlemeet: could not extract meeting code from URL for activity #' .
                    ($googlemeet->id ?? '?') . '; Drive sync will use name/filter matching only.', DEBUG_DEVELOPER);
                $meetingcode = '';
            }
            $name = $googlemeet->name;
            $customfilter = trim($googlemeet->recordingfilter ?? '');

            // Always match the meeting code and the activity name, plus the custom filter when set,
            // so a custom filter that matches no filename does not hide the recordings.
            $conditions = [];
            if ($meetingcode !== '') {
                $conditions[] = 'name contains "' . $this->drive_quote($meetingcode) . '"';
            }
            $conditions[] = 'name contains "' . $this->drive_quote($name) . '"';
            if (!empty($customfilter) && $customfilter !== $name) {
                $conditions[] = 'name contains "' . $this->drive_quote($customfilter) . '"';
            }
            $namefilter = '(' . implode(' or ', $conditions) . ')';

            // Without a Meet Recordings folder, search all of the user's Drive (no parent filter).
            if (empty($parents)) {
                $recordingparams = [
                    'q' => 'trashed = false and
                            mimeType = "video/mp4" and
                            "me" in owners and
                            ' . $namefilter,
                    'pageSize' => 100,
                    'fields' => 'nextPageToken, files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink)',
                ];
            } else {
                $recordingparams = [
                    'q' => '(' . $parents . ') and
                            trashed = false and
                            mimeType = "video/mp4" and
                            "me" in owners and
                            ' . $namefilter,
                    'pageSize' => 1000,
                    'fields' => 'nextPageToken, files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink)',
                ];
            }

            $recordings = $this->list_all_pages($service, $recordingparams);

            // Additional filtering for duplicate check across activities.
            $recordings = $this->filter_recordings_for_activity($recordings, $meetingcode, $name, $googlemeet->id, $customfilter);

            // Remove sync param to avoid redirect loop (skipped when running in cron).
            $url = null;
            if (!$noredirect) {
                $url = new moodle_url($PAGE->url);
                $url->remove_params(['sync']);
            }
            $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'trashed' => 0, 'restored' => 0];

            $recordingscount = $recordings ? count($recordings) : 0;
            if ($recordingscount > 0) {
                // In inline mode, pre-fetch existing rows so we only do heavy enrichment
                // for new recordings and for existing recordings that still lack notes.
                $existingids = [];
                $existingnotes = [];
                if (!$deferenrichment) {
                    $existingids = $DB->get_fieldset_select(
                        'googlemeet_recordings',
                        'recordingid',
                        'googlemeetid = ?',
                        [$googlemeet->id]
                    );
                    $existingids = array_flip($existingids);
                    // Map recordingid => notestext so we know which existing recordings
                    // still lack notes (Gemini notes are often generated later).
                    $existingnotes = $DB->get_records_menu(
                        'googlemeet_recordings',
                        ['googlemeetid' => $googlemeet->id],
                        '',
                        'recordingid, notestext'
                    );
                }

                for ($i = 0; $i < $recordingscount; $i++) {
                    $recording = $recordings[$i];

                    // Drive has finished processing the video; otherwise the sync skips it as unprocessed.
                    if (isset($recording->videoMediaMetadata)) {
                        // Sharing as "anyone with the link" is what lets students who do not own the file
                        // play it in the embedded player. googlemeet/makerecordingspublic (default on) can
                        // be turned off to keep recordings private, at the cost of playback for anyone but
                        // the Drive owner.
                        if (
                            !$deferenrichment
                                && get_config('googlemeet', 'makerecordingspublic')
                                && !in_array('anyoneWithLink', $recording->permissionIds)
                        ) {
                            $permissionparams = [
                                'fileid' => $recording->id,
                                'fields' => 'id',
                            ];
                            $permissionrawpost = [
                                "role" => "reader",
                                "type" => "anyone",
                            ];
                            helper::request($service, 'create_permission', $permissionparams, json_encode($permissionrawpost));
                        }

                        // Format it into a human-readable time.
                        $duration = $this->formatseconds((int)$recording->videoMediaMetadata->durationMillis);

                        $createdtime = new DateTime($recording->createdTime);

                        $recordings[$i]->recordingId = $recording->id;
                        $recordings[$i]->duration = $duration;
                        $recordings[$i]->createdTime = $createdtime->getTimestamp();

                        if (!$deferenrichment && !isset($existingids[$recording->id])) {
                            // Only fetch the transcript (and run yt-dlp) for recordings about to be inserted:
                            // recordings::sync_recordings() never writes a transcript to an existing row.
                            $transcriptdata = $this->find_transcript_for_recording($service, $parents, $recording->name);
                            if ($transcriptdata) {
                                $recordings[$i]->transcriptfileid = $transcriptdata['fileid'];
                                $recordings[$i]->transcripttext = $transcriptdata['content'];
                            }

                            if (empty($recordings[$i]->transcripttext) && !empty($recording->webViewLink)) {
                                $extractor = new subtitle_extractor(get_config('googlemeet', 'subtitlelanguage') ?: 'es');
                                if ($extractor->is_available()) {
                                    $cctext = $extractor->extract($recording->webViewLink);
                                    if (!empty($cctext)) {
                                        $recordings[$i]->transcripttext = $cctext;
                                    }
                                }
                            }
                        }

                        // Notes are fetched for new recordings AND for existing ones
                        // that still have no notes, because Gemini notes are often
                        // published after the recording first appears.
                        $existingnotestext = $existingnotes[$recording->id] ?? null;
                        if (!$deferenrichment && (!isset($existingids[$recording->id]) || empty($existingnotestext))) {
                            $notesdata = $this->find_notes_for_recording(
                                $service,
                                $recording->name,
                                $recordings[$i]->createdTime
                            );
                            if ($notesdata) {
                                $recordings[$i]->notestext = $notesdata['content'];
                                $recordings[$i]->notesdocid = $notesdata['docid'];
                            }
                        }

                        unset($recordings[$i]->id);
                        unset($recordings[$i]->permissionIds);
                        unset($recordings[$i]->videoMediaMetadata);
                    } else {
                        $recordings[$i]->unprocessed = true;
                    }
                }

                $result = recordings::sync_recordings($googlemeet->id, $recordings, $deferenrichment);
                $stats = $result['stats'];
            } else {
                // No recordings found, but still update lastsync time.
                $DB->set_field('googlemeet', 'lastsync', time(), ['id' => $googlemeet->id]);
            }

            if ($noredirect) {
                $stats['found'] = $recordingscount;
                return $stats;
            }

            // Build feedback message.
            $message = $this->build_sync_message($stats, $recordingscount);
            $messagetype = ((int)($stats['inserted'] ?? 0) > 0 || (int)($stats['restored'] ?? 0) > 0)
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_INFO;

            redirect($url, $message, null, $messagetype);
        }
    }

    /**
     * Build a user-friendly sync feedback message.
     *
     * @param array $stats Array with inserted, updated, trashed and restored counts.
     * @param int $totalfound Total recordings found in Drive.
     * @return string The feedback message.
     */
    public function build_sync_message(array $stats, int $totalfound): string {
        $parts = [];

        if (($stats['inserted'] ?? 0) > 0) {
            $parts[] = get_string('sync_new_recordings', 'googlemeet', $stats['inserted']);
        }
        if (($stats['updated'] ?? 0) > 0) {
            $parts[] = get_string('sync_updated_recordings', 'googlemeet', $stats['updated']);
        }
        if (($stats['trashed'] ?? 0) > 0) {
            $parts[] = get_string('sync_trashed_recordings', 'googlemeet', $stats['trashed']);
        }
        if (($stats['restored'] ?? 0) > 0) {
            $parts[] = get_string('sync_restored_recordings', 'googlemeet', $stats['restored']);
        }

        if (empty($parts)) {
            if ($totalfound > 0) {
                return get_string('sync_no_changes', 'googlemeet', $totalfound);
            } else {
                return get_string('sync_no_recordings_found', 'googlemeet');
            }
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Filter recordings to match only those belonging to this specific activity.
     *
     * The Drive API "contains" filter is broad and may return recordings from
     * other activities with similar names. A recording already linked to this activity is kept;
     * one linked only to other activities is dropped. A new recording is kept when its name
     * (case-insensitive) contains the custom filter, contains the meeting code, starts with the
     * activity name, or contains the activity name followed by "(" or "-".
     *
     * @param array $recordings Array of recording objects from Drive API
     * @param string $meetingcode The meeting code (e.g., "abc-defg-hij")
     * @param string $activityname The activity name in Moodle
     * @param int $googlemeetid The current activity ID
     * @param string $customfilter Custom filter pattern set by user
     * @return array Filtered array of recordings
     */
    protected function filter_recordings_for_activity($recordings, $meetingcode, $activityname, $googlemeetid, $customfilter = '') {
        global $DB;

        if (empty($recordings)) {
            return [];
        }

        // Batch query: get all existing recordings by their IDs to avoid N+1 queries.
        $recordingids = array_filter(array_map(function ($r) {
            return $r->id ?? null;
        }, $recordings));

        // Map Drive recording id => [googlemeetid => true]. A Drive file can belong to several
        // activities (a duplicated or restored activity keeps its source's recordings); the copy's
        // own rows must not be skipped here, or the sync would trash them.
        $existingrecordings = [];
        if (!empty($recordingids)) {
            [$insql, $params] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
            $records = $DB->get_recordset_select(
                'googlemeet_recordings',
                "recordingid $insql",
                $params,
                '',
                'id, recordingid, googlemeetid'
            );
            foreach ($records as $rec) {
                $existingrecordings[$rec->recordingid][(int)$rec->googlemeetid] = true;
            }
            $records->close();
        }

        $filtered = [];
        $activitynamelower = \core_text::strtolower(trim($activityname));
        $customfilterlower = \core_text::strtolower(trim($customfilter));

        foreach ($recordings as $recording) {
            $recordingname = $recording->name ?? '';
            $recordingnamelower = \core_text::strtolower($recordingname);
            $recordingid = $recording->id ?? null;

            // Check if this recording already exists using our batch-loaded map.
            $owners = $existingrecordings[$recordingid] ?? [];

            if (!empty($owners) && empty($owners[(int)$googlemeetid])) {
                // Skip this recording - it's already associated with another activity (and not
                // with this one).
                continue;
            }

            // If recording already exists in this activity, include it (for updates).
            if (!empty($owners[(int)$googlemeetid])) {
                $filtered[] = $recording;
                continue;
            }

            // For new recordings, apply name-based filtering.

            // Priority 1: If custom filter is set, check it first (case-insensitive contains).
            if (!empty($customfilterlower)) {
                if (strpos($recordingnamelower, $customfilterlower) !== false) {
                    $filtered[] = $recording;
                    continue;
                }
                // Custom filter didn't match — fall through to meetingcode/name checks
                // instead of skipping. The custom filter is a bonus, not exclusive.
            }

            // Check 2: Recording name contains the exact meeting code.
            if (!empty($meetingcode) && strpos($recordingnamelower, \core_text::strtolower($meetingcode)) !== false) {
                $filtered[] = $recording;
                continue;
            }

            // Check 3: Recording name starts with the activity name.
            // This handles filenames like "Activity Name (2024-01-15 10:00).mp4".
            if (!empty($activitynamelower) && strpos($recordingnamelower, $activitynamelower) === 0) {
                $filtered[] = $recording;
                continue;
            }

            // Check 4: the name contains the activity name at a word boundary, followed by optional
            // spaces and "(" or "-", e.g. a filename with a prefix before the activity name.
            $pattern = preg_quote($activitynamelower, '/');
            if (preg_match('/\b' . $pattern . '\s*[\(\-]/i', $recordingnamelower)) {
                $filtered[] = $recording;
                continue;
            }
        }

        return $filtered;
    }

    /**
     * Find and fetch transcript for a recording.
     *
     * @param rest $service The REST service
     * @param string $parents The parent folders query
     * @param string $videoname The video filename
     * @return array|null Array with 'fileid' and 'content' or null if not found
     */
    private function find_transcript_for_recording($service, $parents, $videoname) {
        // Get the base name without extension.
        $basename = pathinfo($videoname, PATHINFO_FILENAME);

        // Search for text, VTT or SubRip files whose name contains the video's base name. Without a
        // Meet Recordings folder ($parents empty) search all of Drive, as the recording search does,
        // instead of sending an invalid "() and ..." parent clause.
        $parentclause = !empty($parents) ? '(' . $parents . ') and ' : '';
        $transcriptparams = [
            'q' => $parentclause . 'trashed = false and
                    "me" in owners and
                    (mimeType = "text/plain" or mimeType = "text/vtt" or mimeType = "application/x-subrip") and
                    name contains "' . $this->drive_quote($basename) . '"',
            'pageSize' => 10,
            'fields' => 'nextPageToken, files(id,name,mimeType)',
        ];

        try {
            $files = $this->list_all_pages($service, $transcriptparams);

            if (!empty($files)) {
                // Prefer timed captions (.sbv, .vtt, .srt: parse_transcript() drops their times), then .txt.
                $transcriptfile = null;
                foreach ($files as $file) {
                    $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
                    if ($ext === 'sbv' || $ext === 'vtt' || $ext === 'srt') {
                        $transcriptfile = $file;
                        break;
                    }
                    if ($ext === 'txt' && !$transcriptfile) {
                        $transcriptfile = $file;
                    }
                }

                if ($transcriptfile) {
                    // Download the transcript content.
                    $content = $this->download_file_content($service, $transcriptfile->id);
                    if ($content) {
                        // Parse the transcript to extract just the text.
                        $parsedcontent = $this->parse_transcript($content, pathinfo($transcriptfile->name, PATHINFO_EXTENSION));
                        return [
                            'fileid' => $transcriptfile->id,
                            'content' => $parsedcontent,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Transcript not found or error, continue without it.
            debugging("Failed to fetch transcript: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Find and fetch the Gemini meeting notes (a Google Doc) for a recording.
     *
     * Notes are optional and often generated after the recording appears, so this
     * is called both for new recordings and for existing ones still missing notes. The Google Docs
     * the user owns are listed once per client instance and the match is chosen by
     * {@see self::select_notes_candidate_for_recording()}.
     *
     * Notes are searched across the whole Drive, not only the Meet Recordings folders.
     *
     * @param rest $service The REST service
     * @param string $videoname The video filename
     * @param int $recordingcreatedtime Recording creation timestamp.
     * @return array|null ['docid' => string, 'content' => string] or null
     */
    private function find_notes_for_recording($service, $videoname, int $recordingcreatedtime) {
        if (self::recording_notes_prefix($videoname) === '' || $recordingcreatedtime <= 0) {
            return null;
        }
        $notesparams = [
            'q' => 'trashed = false and "me" in owners and mimeType = "application/vnd.google-apps.document"',
            'orderBy' => 'createdTime desc',
            'pageSize' => 1000,
            'fields' => 'nextPageToken, files(id,name,createdTime)',
        ];

        try {
            if ($this->notesdocscache === null) {
                $this->notesdocscache = $this->list_all_pages($service, $notesparams);
            }
            if (empty($this->notesdocscache)) {
                return null;
            }
            $candidate = self::select_notes_candidate_for_recording(
                $this->notesdocscache,
                $videoname,
                $recordingcreatedtime,
                true
            );
            if (!$candidate) {
                return null;
            }
            $html = $this->export_doc_html($service, $candidate->id);
            if (empty($html)) {
                return null;
            }
            $clean = $this->sanitize_notes_html($html);
            if (trim($clean) === '') {
                return null;
            }
            return [
                'docid' => $candidate->id,
                'content' => $clean,
            ];
        } catch (\Exception $e) {
            debugging('Failed to fetch notes: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Enrich already-synced recording rows with permissions, transcript and notes.
     *
     * @param object $googlemeet Activity instance.
     * @param int[] $recordingids Local googlemeet_recordings ids.
     * @return int[] Recording ids that were still active and processed.
     */
    public function enrich_recordings($googlemeet, array $recordingids): array {
        global $DB;

        $recordingids = array_values(array_unique(array_filter(array_map('intval', $recordingids))));
        if (empty($recordingids)) {
            return [];
        }

        $service = new rest($this->get_user_oauth_client());
        $parents = $this->get_meet_recordings_parents_query($service);
        $processedids = [];

        foreach ($recordingids as $recordingid) {
            $recording = $DB->get_record('googlemeet_recordings', [
                'id' => $recordingid,
                'googlemeetid' => $googlemeet->id,
            ]);

            if (!$recording) {
                mtrace("mod_googlemeet enrichment: recording #{$recordingid} no longer exists; skipping.");
                continue;
            }

            if (!empty($recording->deleted)) {
                mtrace("mod_googlemeet enrichment: recording #{$recordingid} is deleted; skipping.");
                continue;
            }

            if (get_config('googlemeet', 'makerecordingspublic')) {
                try {
                    $permissionparams = [
                        'fileid' => $recording->recordingid,
                        'fields' => 'id',
                    ];
                    $permissionrawpost = [
                        'role' => 'reader',
                        'type' => 'anyone',
                    ];
                    helper::request($service, 'create_permission', $permissionparams, json_encode($permissionrawpost));
                } catch (\Throwable $e) {
                    debugging('mod_googlemeet enrichment: failed to create recording permission for #' .
                        $recordingid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            $update = (object)[
                'id' => $recordingid,
                'timemodified' => time(),
            ];
            $haschanges = false;

            if (empty($recording->transcripttext)) {
                $transcriptdata = $this->find_transcript_for_recording($service, $parents, $recording->name);
                if ($transcriptdata) {
                    $update->transcriptfileid = $transcriptdata['fileid'];
                    $update->transcripttext = $transcriptdata['content'];
                    $recording->transcripttext = $update->transcripttext;
                    $haschanges = true;
                }

                if (empty($recording->transcripttext) && !empty($recording->webviewlink)) {
                    $extractor = new subtitle_extractor(get_config('googlemeet', 'subtitlelanguage') ?: 'es');
                    if ($extractor->is_available()) {
                        $cctext = $extractor->extract($recording->webviewlink);
                        if (!empty($cctext)) {
                            $update->transcripttext = $cctext;
                            $recording->transcripttext = $cctext;
                            $haschanges = true;
                        }
                    }
                }
            }

            if (empty($recording->notestext)) {
                $notesdata = $this->find_notes_for_recording(
                    $service,
                    $recording->name,
                    (int)$recording->createdtime
                );
                if ($notesdata) {
                    $update->notestext = $notesdata['content'];
                    $update->notesdocid = $notesdata['docid'];
                    $haschanges = true;
                }
            }

            if ($haschanges) {
                $DB->update_record('googlemeet_recordings', $update);
            }

            $processedids[] = $recordingid;
        }

        return $processedids;
    }

    /**
     * Select the best Gemini notes document for a recording from an already fetched docs list.
     *
     * A candidate's name contains the recording prefix (see recording_notes_prefix()) and it was
     * created within NOTES_PROXIMITY_SECONDS of the recording. Names mentioning Gemini, Notas or
     * Notes are preferred over the rest; within the chosen group the nearest in time wins.
     *
     * @param array $docs Google Docs Drive file objects.
     * @param string $videoname Recording filename.
     * @param int $recordingcreatedtime Recording creation timestamp.
     * @param bool $debugdiscarded Whether to emit debugging() for discarded temporal candidates.
     * @return stdClass|null Selected Drive file object.
     */
    public static function select_notes_candidate_for_recording(
        array $docs,
        string $videoname,
        int $recordingcreatedtime,
        bool $debugdiscarded = false
    ): ?stdClass {
        $prefix = self::recording_notes_prefix($videoname);
        if ($prefix === '' || $recordingcreatedtime <= 0) {
            return null;
        }

        $preferred = [];
        $fallback = [];
        foreach ($docs as $file) {
            $filename = $file->name ?? '';
            if (stripos($filename, $prefix) === false) {
                continue;
            }

            $doctime = self::google_time_to_timestamp($file->createdTime ?? null);
            if ($doctime === null) {
                if ($debugdiscarded) {
                    debugging(
                        'mod_googlemeet: notes candidate discarded without createdTime: ' . $filename,
                        DEBUG_DEVELOPER
                    );
                }
                continue;
            }

            $distance = abs($doctime - $recordingcreatedtime);
            if ($distance > self::NOTES_PROXIMITY_SECONDS) {
                if ($debugdiscarded) {
                    debugging(
                        'mod_googlemeet: notes candidate discarded outside 36h window: ' . $filename,
                        DEBUG_DEVELOPER
                    );
                }
                continue;
            }

            $entry = ['file' => $file, 'distance' => $distance];
            if (preg_match('/Gemini|Notas|Notes/iu', $filename)) {
                $preferred[] = $entry;
            } else {
                $fallback[] = $entry;
            }
        }

        $pool = !empty($preferred) ? $preferred : $fallback;
        if (empty($pool)) {
            return null;
        }

        usort($pool, static function (array $a, array $b): int {
            return $a['distance'] <=> $b['distance'];
        });

        return $pool[0]['file'];
    }

    /**
     * Derive the filename prefix shared by Meet recordings and Gemini notes.
     *
     * Strips a trailing "- Recording" suffix (hyphen, en or em dash); when there is none, strips
     * the file extension instead.
     *
     * @param string $videoname Recording filename.
     * @return string Prefix used for matching.
     */
    private static function recording_notes_prefix(string $videoname): string {
        $prefix = preg_replace('/\s*[-–—]\s*Recording\s*$/iu', '', $videoname);
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === trim($videoname)) {
            $prefix = preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', trim($videoname));
        }

        return trim($prefix);
    }

    /**
     * Convert a Google RFC3339 timestamp to Unix time.
     *
     * @param mixed $value Timestamp value.
     * @return int|null Unix timestamp, or null when invalid.
     */
    private static function google_time_to_timestamp($value): ?int {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        try {
            return (new DateTime($value))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Export a Google Doc to HTML.
     *
     * @param rest $service The REST service
     * @param string $docid The Google Doc file ID
     * @return string|null The HTML body or null on failure
     */
    private function export_doc_html($service, $docid) {
        try {
            $params = ['fileid' => $docid, 'mimeType' => 'text/html'];
            return helper::request($service, 'export', $params, false);
        } catch (\Exception $e) {
            debugging("Failed to export notes doc {$docid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Sanitize the HTML exported from a Google Doc into safe, structure-preserving HTML.
     *
     * Google Docs HTML wraps content in <html><head><style>...</style></head><body>...</body>.
     * We keep only the body's inner HTML and run it through Moodle's clean_text() with
     * FORMAT_HTML, which strips scripts/styles/event handlers but preserves headings/lists.
     *
     * @param string $html Raw exported HTML
     * @return string Safe HTML (may be empty string)
     */
    private function sanitize_notes_html($html) {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Extract inner <body> if present; otherwise use the whole string.
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }

        // Drop <style> blocks Google Docs inlines in the body.
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Strip Google Docs' inline presentation (style/class/id/dir/lang attributes) so the
        // plugin's own CSS governs appearance; otherwise the export's fonts and sizes override the
        // notes panel. Semantic tags (h1-h6, ul/li, p, strong) survive and carry the structure.
        $html = preg_replace('/\s(?:style|class|id|dir|lang)="[^"]*"/i', '', $html);

        $html = clean_text($html, FORMAT_HTML);

        // The Gemini Doc adds chrome we don't want in the panel: a header, the full
        // transcript appended at the end (shown in its own tab), and promo/disclaimer
        // paragraphs. Keep only the notes (Resumen/Summary/Resumo …). Markers are
        // localized (es/en/pt); a language-agnostic fallback also removes the transcript.
        // Step (a): drop everything before the first notes heading.
        $html = preg_replace('/^.*?(?=<h[23]\b[^>]*>(?:(?!<\/h[23]>).)*?(?:Resumen|Summary|Resumo)\b)/isu', '', $html);
        // Step (b): drop the transcript appendix: localized markers first...
        $html = preg_replace('/<p\b[^>]*>(?:(?!<\/p>).)*?📖\s*(?:Transcripci[oó]n|Transcript|Transcri[çc][aã]o).*$/isu', '', $html);
        $html = preg_replace('/<h2\b[^>]*>(?:(?!<\/h2>).)*?(?:Transcripci[oó]n|Transcript|Transcri[çc][aã]o).*$/isu', '', $html);
        // ...then language-agnostic: cut from the first timestamp heading (<h3>HH:MM:SS</h3>), used only by the transcript.
        $html = preg_replace('/<h3\b[^>]*>(?:(?!<\/h3>).)*?\b\d{1,2}:\d{2}:\d{2}\b.*$/isu', '', $html);
        // Step (c): drop Gemini promo/disclaimer paragraphs (es/en/pt).
        $html = preg_replace(
            '/<p\b[^>]*>(?:(?!<\/p>).)*?(?:Revisa las notas de Gemini|Check Gemini.?s notes|Revise as notas do Gemini'
                . '|Gemini (?:toma|tom[oó]|takes) notas|Obt[eé]n sugerencias|Get tips|Responde una breve encuesta'
                . '|Take a .{0,14}survey|Responda .{0,14}pesquisa)(?:(?!<\/p>).)*?<\/p>/isu',
            '',
            $html
        );
        // Step (d): timestamp references point to the (removed) transcript anchors; flatten to plain text.
        $html = preg_replace('/<a\b[^>]*href="#[^"]*"[^>]*>(.*?)<\/a>/is', '$1', $html);

        return trim($html);
    }

    /**
     * Download file content from Google Drive.
     *
     * @param rest $service The REST service
     * @param string $fileid The file ID
     * @return string|null The file content or null on failure
     */
    private function download_file_content($service, $fileid) {
        try {
            $params = ['fileid' => $fileid, 'alt' => 'media'];
            $response = helper::request($service, 'get', $params, false);
            return $response;
        } catch (\Exception $e) {
            debugging("Failed to download file {$fileid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Parse transcript content to extract plain text.
     *
     * @param string $content The raw transcript content
     * @param string $format The file format (sbv, vtt, srt, txt)
     * @return string The parsed text content
     */
    private function parse_transcript($content, $format) {
        $format = strtolower($format);

        if ($format === 'txt') {
            // Plain text, return as is.
            return trim($content);
        }

        // For SBV, VTT and SubRip formats, remove timestamps and cue numbers and keep only text.
        $lines = explode("\n", $content);
        $text = [];
        $skipnext = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines.
            if (empty($line)) {
                continue;
            }

            // Skip WEBVTT header.
            if (strpos($line, 'WEBVTT') === 0) {
                continue;
            }

            // Skip timestamp lines, written as "00:00:00.000 --> 00:00:00.000" or "0:00:00.000,0:00:00.000".
            if (preg_match('/^\d{1,2}:\d{2}:\d{2}[.,]\d{3}/', $line)) {
                continue;
            }

            // Skip cue identifiers (numbers).
            if (preg_match('/^\d+$/', $line)) {
                continue;
            }

            // This is actual transcript text.
            // Remove speaker labels like "[Speaker Name]" or "<v Speaker Name>".
            $line = preg_replace('/^\[.*?\]\s*/', '', $line);
            $line = preg_replace('/<v\s+[^>]+>/', '', $line);
            $line = preg_replace('/<\/v>/', '', $line);

            if (!empty($line)) {
                $text[] = $line;
            }
        }

        return implode(' ', $text);
    }

    /**
     * Format a duration as M:SS below an hour, else H:MM:SS.
     *
     * @param int $milli The time in milliseconds.
     *
     * @return string The formatted time
     */
    protected function formatseconds($milli = 0) {
        // Whole seconds first: % on a float with a fraction is deprecated since PHP 8.1. Truncating here
        // gives the same digits the per-part floor() calls gave.
        $secs = (int) floor($milli / 1000);

        if ($secs < MINSECS) {
            return '0:' . str_pad((string) $secs, 2, "0", STR_PAD_LEFT);
        } else if ($secs < HOURSECS) {
            return intdiv($secs, MINSECS) . ':' . str_pad((string) ($secs % MINSECS), 2, "0", STR_PAD_LEFT);
        } else {
            return intdiv($secs, HOURSECS) . ':' .
                str_pad((string) intdiv($secs % HOURSECS, MINSECS), 2, "0", STR_PAD_LEFT) . ':' .
                str_pad((string) ($secs % MINSECS), 2, "0", STR_PAD_LEFT);
        }
    }

    /**
     * Get the email of the logged in Google account
     *
     * @return string The email, or '' when no Google account is logged in
     */
    public function get_email() {
        $userinfo = $this->get_cached_userinfo();
        return $userinfo['username'] ?? '';
    }
}
