# Changelog

All notable changes to `mod_googlemeet`. Versions up to 2.30.1 are the upstream
(`hyukudan/moodle-mod_googlemeet`) releases this fork started from.

## 2.30.1+uai.6 (unreleased)
- Queueing AI question generation (`mod_googlemeet_queue_generate_questions`) needs
  `mod/googlemeet:generateai` as well as `managequestions`, like every other paid Gemini call, and the
  recording hub hides the "Generate questions" button from users without it (non-editing teachers by
  default).
- Pasted or detected-language-less analyses no longer default to Spanish; readers fall back to the
  current language.
- The attendance session query bounds the session start directly, so the new `eventdate` index can
  serve it.

- Web services: the question actions (publish, unpublish, discard, update, queue generation and
  "Publish all drafts") no longer take a `sesskey` argument. Core already checks the session key of
  every AJAX request, and the argument kept token clients (and the Moodle App) out. A caller that
  still sends it gets an "invalid parameter" error.
- Web services: `analyze_transcript` sends the pasted transcript to Gemini, a paid call, so it now
  requires `mod/googlemeet:generateai` as well as `mod/googlemeet:editrecording`, like
  `generate_ai_analysis`. A Gemini safety block now returns its user message without the debug
  detail.
- Web services: plain-text returns are typed. The recording name and display name and the question
  name are `PARAM_TEXT`; the display name goes through `format_string()` first, so a name that ends
  in a bare `<` no longer fails the whole response. The visibility flag returned when a recording
  is shown or hidden is a boolean. Every legacy function reads its validated parameters, not the
  raw arguments.
- Tests: the web service tests call through `call_external_function()`, as the AJAX layer does.
- Capabilities: `mod/googlemeet:generateai` is flagged as a personal-data risk (it sends
  transcripts, and with the video fallback the video, to Google Gemini) and
  `mod/googlemeet:removerecording` as a data-loss risk.
- Database: indexes for the cron queries that scan every activity: `googlemeet_events(eventdate)`
  (reminders, past-event cleanup), `googlemeet_recordings(deleted, trashreason, timedeleted)` (trash
  purge) and `googlemeet_ai_analysis(status, nextretry)` (due analyses), which replaces the
  single-column `status` index.
- Backup and restore: a restore with user data no longer recreates pending, processing or failed AI
  analyses either, so a course copy never queues its own paid Gemini run; completed analyses travel
  as before.
- AI: `yt-dlp` runs without a shell, only for an https `drive.google.com` link, and is killed after
  two minutes instead of hanging the task. The Gemini File API status poll waits 2, 4, 8, 16, then
  30 seconds between checks within the same total budget, and waits out a 429 or 503 (honouring
  `Retry-After`) instead of failing at once. An analysis that hits a PHP error is marked failed
  instead of staying "processing". When Gemini names no language the analysis language is left
  empty instead of `es`.
- Code: dead `ai_service` methods (`generate_analysis_sync()`, `delete_analysis()`,
  `get_pending_analyses()`, `reset_stale_processing()`, `get_status_counts()`) and the runtime
  checks for the `chapters` column are removed. `cli/process_transcripts.php` now uses
  `subtitle_extractor::extract()` and the `ai_service` failure policy instead of copies of them.
- Strings: every lang string is requested by its literal id (admin status, attendance labels,
  Calendar failure notices, new-recording notices), and an unknown status or kind is shown as plain
  text instead of a missing-string marker. The settings `notificationexpanded`, `notify`,
  `minutesbefore` and `emailcontent` have their own `_desc` strings (the misspelt
  `notifycationexpanded_desc` and the unused `emailcontent_help`, `recordingavailable_subject` and
  `sync_deleted_recordings` are gone), and the AI model notes ("Stable, default", ...) are
  translatable.
- Sync: SubRip (`.srt`) transcripts, which the Drive query already listed, are now used. The sync
  result no longer carries a `deleted` counter that was never set (a recording gone from Drive is
  trashed).
- Activity settings: holiday rows added with the "Add" button are disabled with the recurrence off,
  like the saved ones. The room URL is rebuilt on `meet.google.com` from its code, so a text such as
  `meetXgoogleYcom/abc-defg-hij` is rejected instead of stored.
- Questions: the activity-wide publish reports recording names in their plain spelling, so
  "A & B" no longer shows as "A &amp; B" in the review panel.
- Code: dead functions (`googlemeet_print_heading()`, `googlemeet_print_intro()`,
  `googlemeet_has_recording()`, `googlemeet_get_future_events()`, `googlemeet_send_notification()`,
  `googlemeet_delete_recording_dependents()`), the empty `db/install.php`, an unused form property,
  `ops_status::STUCK_PROCESSING_SECONDS` and unused parameters are removed; the upcoming sessions
  query uses the DML limit argument instead of a hand-written `LIMIT`.
- Tests: the backup review-state test fails instead of skipping when the analyses are missing; the
  deprecated `course_delete_module()` is only called where its path is under test.

## 2.30.1+uai.5 (unreleased)
- Privacy: a user's reminders of one activity are exported together, each with its session and
  kind ("Minutes before" or "Early reminder"); before, each reminder overwrote the previous one and
  the export kept only the last.
- Privacy: deleting all the data of an activity still removes its AI analyses whole (they are
  generated from the participants' speech and may name them; course reset keeps them as course
  content). It now also removes the sync log rows of the activity and empties the attendance fetch
  state, so those sessions are not read from Google again.
- Privacy: unmatched Meet participants (no Moodle user) are declared; they go with the activity, a
  course reset or a context-wide deletion. Per-user requests follow the recorded user only, never
  an e-mail match. The sync log message, the AI error text and every attendance column are declared,
  and the sync log stores e-mail addresses masked (`***@domain`).
- Tests: a privacy provider test covers every user-linked table and asserts
  `component_is_compliant()`.
- Events: teacher writes are now logged. Renaming or hiding a recording (`recording_updated`),
  trashing it (`recording_trashed`, one per recording for "Delete all"), restoring it
  (`recording_restored`) and purging it (`recording_deleted`); saving, (re)generating or analysing
  an AI analysis (`ai_analysis_updated`) and publishing it to students (`ai_analysis_reviewed`, one
  per analysis for "Publish all"); queueing a Drive sync (`sync_requested`); publishing,
  unpublishing, editing, discarding or queueing practice questions (`questions_changed`, one per
  recording). A request that writes nothing (already published, sync already queued, analysis
  already completed or processing) logs nothing.
- "Delete all recordings" trashes the recordings it listed, by id, instead of every live row of the
  activity at the time of the update.
- `mod_googlemeet_generate_ai_analysis` returns the stored `timecreated` on a regeneration instead of
  reading a missing property (a PHP warning).

## 2.30.1+uai.4 (unreleased)
- Neither Gemini upload request follows redirects (the API key would travel with them); the Drive
  confirm-token probe fetches only the first MiB; multi-account Drive links (`/u/<n>/file/d/...`) parse.
- Practice questions: lists, publish batches and the per-recording published counts read only the
  latest version of each question bank entry. Editing a question in the core question bank no longer
  shows and counts the old and the new version side by side; a newer draft hides the older published
  version until it is published.
- "Generating questions" no longer shows for recording 1 while a generation of recording 10, 12 or
  100 is queued: the queued task's recording is matched exactly.
- Cancelled dates, holidays and the "Today" label take the day of a session in the site's default
  timezone, for every viewer and for cron. A viewer in a distant timezone no longer sees a session
  late on a cancelled day as scheduled, and reminders and auto-sync skip the same day the page shows.
- Holiday and cancelled dates saved before the site-timezone change (midnight in the editing teacher's timezone) are rounded to the nearest site midnight on upgrade, so they keep the day the teacher picked; the form sorts them by site day.
- The hero's "campus calendar" link is built from wwwroot, so it works on a site served under a path.
- A restored session's attendance shows its real "matched of participants" counts, counted from the
  restored attendance rows, instead of "0 matched of 0".
- The holiday and cancelled-date pickers of the activity form read the day in the site timezone, the
  same calendar the sessions are matched against, so an editor in another timezone saves the day they
  picked.
- The Gemini File API video upload goes through Moodle's HTTP client: it uses the site's outbound
  proxy (and proxy bypass list) and the cURL blocked-hosts and allowed-ports rules, follows no
  redirect, and is refused unless the upload URL Google returns is https on
  generativelanguage.googleapis.com, so the API key is never sent to another host.
- A Gemini model fallback during a web request (AJAX or web service) no longer prints its message
  into the JSON response; it still goes to debugging(), and to the task output under cron.
- Video analysis downloads: the Drive file id is read only from drive.google.com links, the confirm
  token from Drive's interstitial page is URL-encoded, and every download stops at 2 GB instead of
  writing the whole body first. A download cut short (size cap, timeout) fails instead of uploading
  a truncated video.
- Durations and subtitle timestamps with fractional seconds no longer raise the PHP 8.1+ "implicit
  conversion from float" deprecation.
- Names with "&" or "<" are no longer shown escaped twice ("A &amp; B"): the practice session's
  "From" line, the progress report (recording columns, most-failed sections, identity columns), the
  Moodle App class list and class page (titles, key points, chapters) and the OAuth issuer select of
  the plugin settings.
- Students no longer receive the stored error text of a failed AI analysis (it can carry the
  provider's raw message) through mod_googlemeet_get_ai_analysis: they get the generic "Error
  generating analysis" message; users who can edit recordings still read the stored text.
- A manual Drive sync that fails unexpectedly reports a stable "error communicating with the server"
  message instead of the raw exception text, which could also make the status web service fail as a
  whole; the detail stays in the task log and the developer debugging output. The plugin's own
  messages (account not linked, not the organiser's account, user missing) are kept.
- The recordings list's sort and page links keep a search or topic containing "&", "#", "+" or "%":
  they extend the URL built in PHP instead of pasting the text unencoded.
- The confirmation and "Hidden" texts of the recordings list's inline script are JSON-quoted, so a
  translation with an apostrophe can no longer break the teacher actions.
- Practice topic and "failed questions" counts read only the latest version of each question, like
  the practice session itself.

## 2.30.1+uai.3 (unreleased)
- Attendance: new capability `mod/googlemeet:manageattendance` (write, editing teachers and managers,
  RISK_PERSONAL). Linking or unlinking a Meet participant and refetching a session now need it;
  viewing the attendance and downloading it still need `mod/googlemeet:viewreports`, so
  non-editing teachers keep a read-only view. Refetch is a POST button instead of a link carrying
  the sesskey, and every write is POST only. "Link Google again" (relink) is unchanged: it only
  forgets the organiser's own Google token. In a course in separate groups, a user without
  "Access all groups" sees only the attendance of the members of their own groups; unmatched
  participants are shown to such a user only when they can manage attendance, and they can link
  a participant only to a member of their own groups and unlink only rows they are shown.
- Manual Drive sync: queueing (view page, `mod_googlemeet_request_sync`) and polling
  (`mod_googlemeet_get_sync_status`) now need `mod/googlemeet:syncgoogledrive` as well as
  `mod/googlemeet:editrecording`, so the request fails at once instead of in the queued task; the
  sync button and its script are hidden from users without it.
- Guests can still view the activity and enter the room, but write nothing under the shared guest
  account: `mod_googlemeet_mark_recording_progress`, `mod_googlemeet_check_practice_answer` and
  `mod_googlemeet_log_room_entered` refuse them (`guestsarenotallowed`), and entering the room from
  the web page logs no "room entered" event for them.
- `mod/googlemeet:editrecording` is flagged RISK_XSS: it uploads recording materials and edits the
  recording names and transcripts students see.
- Recording materials and attachments that a browser would run as a page (HTML, XHTML, SVG, XML)
  are always sent as downloads; PDFs and images still open inline.
- The Google logout on the activity settings page needs the session key its link already carries;
  a link without it no longer unlinks the teacher's Google account.


- Organiser identity. The Moodle user who owns the organiser Google account is now recorded in the
  new column `googlemeet.creatoruserid`, and every background task that acts as the organiser
  (auto-sync, recording enrichment, Calendar updates and deletions, the attendance fetch) and the
  attendance pages use that id instead of looking a user up by `creatoremail`.
  - Who may name the organiser: on add, on edit and on restore the organiser e-mail must be the
    acting user's own (their linked Google account or their Moodle e-mail) unless they hold
    `moodle/site:config`. The form reports `creatoremail_notyours`; `googlemeet_add_instance()` and
    `googlemeet_update_instance()` throw it too. Before, a teacher could type another user's e-mail
    (or restore a crafted backup carrying it) and cron would then use that user's Google token.
  - A restore keeps a mapped creator (backup with users) only when the restoring user is that
    creator or holds `moodle/site:config`, and otherwise keeps the e-mail only when the restoring
    user may name it (then records them, or for an administrator the single active owner); anything
    else blanks the e-mail and turns auto-sync off, so a copy restored by someone else needs its
    teacher to link a Google account again.
  - The upgrade backfills the id when exactly one active user has the e-mail (case-insensitive),
    else leaves 0. An activity with no recorded id still answers with that single owner, but the
    answer is never written back (read paths used to persist it, binding the wrong user for good
    when an e-mail changed hands).

## 2.30.1+uai.2 (unreleased)
- Teacher report: in separate-groups mode, a user without "Access all groups" sees only the students
  of their own groups within the activity's grouping, and nobody when they belong to no group (the
  page and the download used to list every student in that case). The activity's effective group
  mode is used instead of the course's.
- `mod_googlemeet_get_ai_analysis` refuses a hidden recording to users who cannot edit recordings,
  like the other recording web services.
- Deleting the activity on Moodle 5.2 and 5.3 deletes its Google Calendar event again: core now
  deletes modules through the course format actions, which the deletion check did not recognise.
- "Mark as viewed" (recording hub and Moodle App) no longer completes the activity: it sets the new
  `googlemeet_recording_progress.markedviewed` column, which shows the recording as "Viewed" to the
  student but never counts towards completion. `completed` now means measured viewing time only
  (heartbeat seconds reaching the completion threshold), and only it feeds the completion rules
  and the teacher statistics. `mod_googlemeet_mark_recording_progress` keeps its `completed`
  parameter (it now sets `markedviewed`) and returns `markedviewed`; the hub keeps sending
  heartbeats after a manual mark so measured completion can still be reached. Existing rows are
  grandfathered: no completion already granted is revoked. Privacy export and backup carry the
  new field.
- Heartbeats credit at most the real time elapsed since the last accepted one: the per-call 5-second
  grace is gone, a call that credits nothing no longer restarts the clock (a burst of calls cannot
  add time), a row another tab inserted first is capped the same way, and the first heartbeat of a
  recording only starts the clock.

## 2.30.1+uai.1 (unreleased)
- Requires Moodle 5.2 (`requires = 2026042000`); supports Moodle 5.2 and 5.3 (`supported = [502, 503]`).
  Moodle 5.0 and 5.1 are no longer supported.
- `install.xml`: `googlemeet.originalname` no longer declares an empty-string default on a
  `NOT NULL` column (XMLDB printed a debugging message on every install).
- CI moves to the moodle-an-hochschulen reusable workflow (5.3 full matrix, 5.2 one database),
  with the fleet's zero-warning gates; release workflow added.
- `COPYING.txt`, `CHANGELOG.md`, coding-standard and release-hygiene files added.
- Code comments audited against the code; planning ids and references to the development
  environment removed from comments and from `install.xml` and `services.php` descriptions.
- Moodle coding style, PHPDoc, ESLint, Stylelint and Mustache lint pass with zero warnings: test helper
  classes moved to `tests/fixtures/`, PHPUnit coverage attributes, `!important` replaced by plugin
  selectors, Example contexts for every template, rebuilt AMD modules.
- AJAX failures that were silent (edit modal analysis, clipboard copy, analysis polling, key-point
  checklist migration) now show the standard error dialog.
- Badges state their text colour; the AI edit modal close button label is translated.
- Language packs sorted; the Brazilian Portuguese pack is complete.

## 2.30.1 (2026-10-10)
- Teaching context setting for AI prompts (`aicontext`); prompts no longer contain domain-specific
  examples.
- Generic CLI hints and sample data; licence headers completed.

## 2.30.0 (2026-10-10)
- Single cascade delete for recordings and activities; `cli/cleanup_orphans.php`; trash retention
  (`trashretentiondays`, `purge_trash` task); "Delete all recordings" moves to the trash.
- Course reset, backup of AI content without user data, numeric recording duration.
- Activity index with next session and progress; background Drive sync with status polling; fewer
  queries in lists; admin sync status page and sync log.
- Reminders tolerate cron gaps, optional early reminder, Moodle app push, richer new-recording
  notice, teacher alert when auto-sync gives up, "Add to my calendar".
- Teacher review of AI content (`requireaireview`), Gemini safety threshold, stuck-analysis handling.
- Google Calendar event updates on edit and safe deletion when the activity is deleted.
- Custom completion rules, `room_entered` event, optional attendance from the Meet REST API.
- Moodle app: activity and recording views.
- GitHub Actions CI and Behat tests.

## 2.29.1 (2026-10-09)
- Layout fixes for the activity list filters, search result hints, viewing report width on phones,
  chapter list on rotation, "Select all" label.

## 2.29.0 (2026-10-09)
- Requires Moodle 5.0. Key-points checklist stored per user; recording hub and list redesign;
  12 recordings per page by default.
- AI review flow with bulk publishing of practice questions.
- Practice attempts stored; practice page (missed questions, by topic); teacher report with the new
  `mod/googlemeet:viewreports` capability.

## 2.28.1 (2026-10-09)
- Friendly message for links to deleted or hidden recordings.

## 2.28.0 (2026-10-09)
- Practice questions are included in backup, restore, duplication and course copy; restored
  activities no longer share the original's Calendar event.

## 2.27.1 (2026-10-09)
- Reminders only to active students; optional question publish check; organiser email can only be
  changed by an admin or to the editor's own account; no yt-dlp fallback in `/tmp`; graceful pages
  for invalid links; session cache for Google user info.

## 2.27.0 / 2.26.0 (2026-10-08)
- Hub and list design pass, print summary, dark mode fixes.
- Chapter jumps, clickable timestamps, deep links (`&t=N`), transcript search.

## 2.25.x (2026-07)
- Virtual classroom: "Enter room" button, viewing progress, classroom hero, AI chapters,
  backfill CLIs, Moodle calendar actions, live show/hide toggle.

## 2.18 – 2.24
- "Notes by Gemini" import; recording materials; soft delete with trash; sync robustness; search
  across transcripts and notes; AMD modules; Spanish translation.

## 2.10 – 2.14
- Automatic sync after sessions; security hardening of web services; PHPUnit suite; subscriptions
  to new recordings; recording hub; AI practice questions; materials; mobile practice.

## 2.3 – 2.8
- Exclusion periods, cancelled sessions, AI analysis with Gemini, pagination and sorting, recording
  filter, manual transcript analysis, subtitle extraction with yt-dlp, CLI bulk processing.

## Earlier
- Modernisation of the upstream code (Moodle 4.0+, events-based logging, Ionic 5 app support).
