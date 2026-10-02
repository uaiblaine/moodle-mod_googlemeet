# Review context for mod_googlemeet

`mod_googlemeet` is an activity module that creates Google Meet rooms through the Google
Calendar API, lists the sessions, syncs their recordings (and Meet transcripts and notes) from
Google Drive, and optionally analyses a recording with the Google Gemini API (summary, key
points, chapters, practice questions in the question bank). It talks to three Google APIs:
Calendar, Drive and Gemini. It has nine tables of its own, three of them keyed to a user (notification log,
recording subscriptions, viewing progress). The plugin declares no `$plugin->supported`; it requires
Moodle 4.0 and the review is pinned to 5.2.

## Who is trusted

- Site administrators are fully trusted. They choose the OAuth 2 issuer (a core OAuth 2
  service, which must log in at `accounts.google.com`, otherwise the client throws), the Gemini
  API key, the model and the `yt-dlp` path.
- The plugin's capabilities are module-scoped. `view` goes to every archetype including
  guest. `editrecording`, `removerecording`, `syncgoogledrive`, `generateai` and `addinstance`
  go to editing teacher and manager. `managequestions` (`RISK_SPAM`) also goes to the
  non-editing teacher. `subscriberecordings` goes to student, teachers and manager. None of
  them carries `RISK_XSS` or `RISK_PERSONAL`.
- Teachers who connect their Google account are trusted with that account's Drive and
  Calendar. Students and guests are untrusted; recording names, transcripts, notes and
  Gemini output are untrusted text at every output.

## Surfaces

- 19 web service functions, all `ajax`, all in `classes/external.php`, each with
  `validate_context()` and `require_capability()` in the code. By `services.php` capability:
  `editrecording` (6: `recording_edit_name`, `showhide_recording`, `restore_recording`,
  `trash_recording`, `save_ai_analysis`, `analyze_transcript`); `removerecording` (2:
  `delete_all_recordings`, `purge_recording`); `generateai` (1: `generate_ai_analysis`);
  `managequestions` (6: `publish_questions`, `unpublish_questions`, `discard_questions`,
  `update_question`, `queue_generate_questions`, `get_questions`); `view` (4:
  `get_ai_analysis`, `get_practice_questions`, `check_practice_answer`,
  `mark_recording_progress`). Recording ids are always re-checked against the activity
  (`googlemeetid` of the course module), and hidden recordings need `editrecording`.
- Page scripts: `view.php` (view and the logout/sync actions, which need `editrecording`
  and a sesskey), `index.php`, `material.php` (`editrecording`, sesskey on writes) and
  `callback.php` (OAuth return; `require_login()` and `require_sesskey()`).
- `googlemeet_pluginfile()` serves two areas, `attachment` and `recordingmaterial`, behind
  `mod/googlemeet:view` and the hidden-recording rule, through `send_stored_file()`.
- Scheduled tasks: `notify_event`, `process_ai_analysis`, `process_autosync`,
  `check_stale_recurrence`. Adhoc tasks: `generate_questions`, `notify_new_recordings`,
  `process_recording_enrichment`, `process_video_analysis`. Three CLI scripts under `cli/`.
  No hooks or event observers are registered. A mobile handler is declared in `db/mobile.php`.
- Privacy: a full provider (metadata, plugin and userlist). Tables without a user id (AI
  analysis, recordings) are declared for transparency, and Google Calendar, Drive and Gemini
  are declared as external locations.

## Credentials and what leaves the site

- **Google OAuth.** The plugin stores no token itself. It uses core's per-user OAuth 2 client
  (`\core\oauth2\api::get_user_oauth_client`, scopes `drive` and `calendar.events`), so
  access tokens live in the user's session and refresh tokens in core's `oauth2_refresh_token`
  table. The login popup is opened from a `data-*` attribute, not an inline handler.
- **Cron acts as the activity creator.** `googlemeet.creatoremail` is the Google account that
  created the Meet room. `process_autosync` finds the active Moodle user with that same
  email, calls `set_user()` on that user and syncs Drive with that user's refresh token.
  Identity is therefore tied to email equality; a change that weakens that lookup, or that
  syncs for a user who is not the creator, is a finding. `sync_recordings()` itself checks
  `syncgoogledrive` against the current user, which in cron is that impersonated creator.
- **Drive sharing.** With `makerecordingspublic` on (the default), synced recordings get
  an "anyone with the link" reader permission so students can play the embedded file. Turning it
  off is a privacy gain that breaks playback for everyone but the owning account.
- **Gemini.** The API key (`admin_setting_configpasswordunmask`) is sent only in the
  `x-goog-api-key` header, never in a URL. Recording transcripts go to Gemini when AI is
  enabled. The full video is downloaded and uploaded only on an explicit `generateai` request
  with `forcedownload`; background tasks never escalate to a video download.
- Drive query strings built from teacher-controlled values go through `drive_quote()`.
  Outbound calls use Moodle's `\curl`, with one exception below.

## Facts that look like findings but are by design

- **A raw `curl_init()` handle streams the video to Gemini** in
  `gemini_client` (`CURLOPT_UPLOAD`). `\curl::post()` cannot stream a file body. The upload URL
  comes from a response header of Google's File API and is not host-checked; the code treats it
  as trusted. Taking that URL from any other source would be a finding.
- **`view.php` changes the cards/list view preference through a GET parameter without a
  sesskey.** It only writes the viewer's own preference and is idempotent.
- **`yt-dlp` runs through `shell_exec` with `escapeshellarg()`** on its path, the language
  (`PARAM_ALPHANUMEXT`) and the Drive URL, to read Drive's auto-generated subtitles. The path
  is an admin setting. Any new argument interpolated into that command is a finding.
- **The question WS functions take a `sesskey` parameter** in addition to the session; this
  is intentional. `check_practice_answer` reveals the correct answer to any viewer after
  they submit, and only for questions in the ready state.
- Spanish text in prompts and `mtrace` output, and the `es` language pack beside `en` and
  `pt_br`, are not a language-pack finding.

## De-emphasise

- `amd/build/**` is minified output of `amd/src/**`; review the source.
- `docs/**`, `lang/**` and `tests/**` carry no production behaviour.
- Visual details of `styles.css` and the Mustache templates, unless they show data the viewer
  should not see.
- Unknown: whether every Drive and Calendar call survives a Moodle version above 5.2; the code
  declares only a lower bound.
