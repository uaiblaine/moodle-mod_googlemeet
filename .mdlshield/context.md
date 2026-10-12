# Review context for mod_googlemeet

An activity module that creates Google Meet rooms through the Calendar API, syncs recordings
(with transcripts and notes) from Drive, optionally reads attendance from the Meet REST API, and
optionally analyses recordings with Gemini (summary, topics, chapters, practice questions in the
core question bank). `requires = 2026042000` (5.2), `supported = [502, 503]`, review pinned to
5.3. 13 tables; five carry a `userid` (notification log, recording subscriptions, viewing
progress, practice attempts, attendance); `googlemeet_ai_analysis` records `reviewedby`.

## Who is trusted

- Site administrators are fully trusted: the OAuth 2 issuer (its login URL must be on
  `accounts.google.com`, otherwise the client throws), the Gemini key, model and prompt context,
  the `yt-dlp` path, link sharing, the AI review requirement and attendance are theirs.
- Nine capabilities. `view`: manager, both teachers, student, guest. `addinstance` (course level,
  cloned from `moodle/course:manageactivities`), `syncgoogledrive`, `editrecording`,
  `removerecording`, `generateai`: editing teacher and manager. `managequestions` (`RISK_SPAM`)
  and `viewreports` (`RISK_PERSONAL`): both teachers and manager. `subscriberecordings`
  (riskbitmask 0): student, both teachers, manager. None carries `RISK_XSS`.
- Teachers who link their Google account are trusted with that account's Drive and Calendar.
  Students and guests are untrusted; recording names, transcripts, notes, attendance display
  names and all Gemini output are untrusted text at every output.

## Surfaces

- 25 web service functions, all `ajax` (20 in `classes/external.php`, 5 in `classes/external/`),
  each calling `validate_context()` and `require_capability()` with its `services.php` capability:
  `editrecording` (9: `recording_edit_name`, `showhide_recording`, `restore_recording`,
  `trash_recording`, `save_ai_analysis`, `analyze_transcript`, `review_ai_analysis`,
  `request_sync`, `get_sync_status`); `removerecording` (2: `delete_all_recordings`,
  `purge_recording`); `generateai` (1: `generate_ai_analysis`); `managequestions` (7:
  `publish_questions`, `publish_activity_drafts`, `unpublish_questions`, `discard_questions`,
  `update_question`, `queue_generate_questions`, `get_questions`); `view` (6: `get_ai_analysis`,
  `get_practice_questions`, `check_practice_answer`, `mark_recording_progress`,
  `get_practice_session`, `log_room_entered`). Three of them are also in the official mobile
  service: `check_practice_answer`, `mark_recording_progress` and `log_room_entered`. Recording
  ids are looked up together with the activity's `googlemeetid`.
- Page scripts: `view.php` (`view`; logout and sync need `editrecording` and a sesskey, sync also
  POST; subscribe needs `subscriberecordings`, sesskey and POST); `enter.php` (`view`, redirects to
  the Meet room); `callback.php` (OAuth return, `require_login()` + `require_sesskey()`);
  `attendance.php` and `report.php` (`viewreports`; attendance actions take a sesskey, the report
  limits a separate-groups course to the viewer's groups without `moodle/site:accessallgroups`);
  `material.php` (`editrecording`, sesskey on save); `practice.php` and `calendar.php` (`view`,
  an ICS via `send_file()`); `index.php` (`require_course_login()`); `admin_status.php` (admin
  external page, `moodle/site:config`).
- `googlemeet_pluginfile()` requires a module context, `require_login()` and `view`, and serves
  two areas through `send_stored_file()`: `attachment` (itemid 0) and `recordingmaterial` (itemid
  = a live recording of this activity; a hidden one needs `editrecording`). The `intro` area is
  the third file area, served by core.
- 6 scheduled tasks (`notify_event`, `process_ai_analysis`, `process_autosync`,
  `check_stale_recurrence`, `purge_trash`, `fetch_attendance`), 7 adhoc tasks (calendar
  update/delete, question generation, new-recording notices, enrichment, video analysis, manual
  sync), 4 CLI scripts, 4 message providers, one session cache, a mobile handler; no hooks or
  observers.
- Privacy: metadata, plugin, userlist and preference providers; a `core_oauth2` subsystem link;
  Google Drive, Calendar, Gemini and the Meet API declared as external locations.

## Credentials, identity and what leaves the site

- **Google OAuth.** The plugin stores no token. It uses core's per-user OAuth 2 client
  (`\core\oauth2\api::get_user_oauth_client`, scopes `drive` and `calendar.events`, plus
  `meetings.space.readonly` only while attendance is enabled), with the sesskey in the return URL.
  Google calls go through `\core\oauth2\rest` subclasses (`classes/rest.php`,
  `local/attendance/meet_rest.php`); Drive query values written by teachers go through
  `client::drive_quote()`. The login popup URL travels in a `data-*` attribute.
- **Background work acts as the organiser.** `googlemeet.creatoremail` is the Google account that
  created the room. Tasks that call Google switch to the active Moodle user with that email via
  `local\impersonation`, which stashes and clears the `oauth2-state-*` session keys around
  `set_user()`, so one cron run never reuses another user's access token. Manual sync and calendar
  update/delete also require the linked Google email to equal `creatoremail`
  (`sync_manager::require_creator_account()`). On an existing activity the email changes only for
  `moodle/site:config` or to the editor's linked account (`mod_form::can_change_creatoremail()`).
  `googlemeet_sync_recordings()` requires `syncgoogledrive` of the current (impersonated) user.
- **Drive sharing.** With `makerecordingspublic` on (default), synced recordings get an "anyone
  with the link" reader permission so students can play the embedded file; off, only the owner
  can play them.
- **Gemini.** The key (`admin_setting_configpasswordunmask`) travels only in the `x-goog-api-key`
  header. Transcripts go to Gemini when AI is enabled; the full video is downloaded and uploaded
  only for a `generate_ai_analysis` call with `forcedownload` (background runs pass `false`).
- **AI review gate** (`local\ai_review`). With `requireaireview` on (default, and unset counts as
  on), students see AI content only when the row is `completed` and `reviewed`. Every AI rewrite
  clears `reviewed`; publishing compares the `timemodified` the teacher saw, so content written
  in between stays pending. `get_ai_analysis` strips unreviewed or in-progress text for users
  without `editrecording`.

## Facts that look like findings but are by design

- **A native `curl_init()` handle streams the video to the Gemini File API**
  (`gemini_client::upload_video_file()`): `\curl::post()` always sets `CURLOPT_POST` and
  `CURLOPT_POSTFIELDS`, which override `CURLOPT_INFILE` and upload an empty body. Its URL is the
  `X-Goog-Upload-URL` header returned by the File API start request. It is the only native cURL
  handle; every other outbound call outside `\core\oauth2\rest` uses `\curl`.
- **`yt-dlp` runs through `shell_exec()`** (`subtitle_extractor`) with `escapeshellarg()` on the
  binary path (admin setting or PATH lookup), the language (admin setting, `PARAM_ALPHANUMEXT`)
  and the Drive URL, plus `--ignore-config --no-playlist --skip-download`.
- **`view.php` saves the cards/list preference from a GET parameter without a sesskey** (only the
  viewer's own preference, idempotent). `enter.php` redirects to the room without one and logs
  the entry only when a valid sesskey is present.
- **The six writing `managequestions` functions take a `sesskey` parameter** in addition to the
  session. `check_practice_answer` returns `correct`, `correctanswerid` and the explanation after
  a submission and stores the attempt.
- The `es` pack beside `en` and `pt_br`, and `es` as default subtitle language, are deliberate.

## De-emphasise

- `amd/build/**` (minified `amd/src/**`), `docs/**`, `lang/**`, `tests/**`, and visual details of
  `styles.css` and the templates unless they show data the viewer should not see.

## Unknowns

- The review is pinned to 5.3 while the plugin also supports 5.2: a core API that differs between
  them is not reviewed against 5.2. Tests replace Google through seams
  (`sync_manager::set_runner()`, `calendar_sync::set_service_factory()`), so live API behaviour is
  outside the suite.
