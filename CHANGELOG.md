# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [v5.2-r2] - 2026-10-23

Version `2026042001`, brought to the Moodle plugins directory submission guidelines.

### Fixed

- The recordings layout preference (`mod_googlemeet_recordings_view`, cards or list) is declared
  in the privacy metadata and exported with a data request; before, it was written but never
  declared or exported.
- Five stylesheet selectors applied outside the plugin's own markup: `.recording-name-text`, the
  row-action focus outlines and the date-selector calendar icons (`#id_eventdate_calendar`,
  `#id_eventenddate_calendar`, which matched the same ids in any other activity's form). They are
  now scoped to the recordings panel, the recording page and the activity form.
- Language files hold literal strings only: twelve strings built by concatenation (an apostrophe,
  blank lines) in `lang/en`, `lang/es` and `lang/pt_br` are plain literals with the same text.
- PHP 8.1+ deprecation "json_decode(): Passing null to parameter #1" when editing an activity
  saved without recurrence (`googlemeet.days` is NULL), when reading an AI analysis still being
  processed (the `get_ai_analysis` web service), and when listing or opening a recording whose
  completed analysis has no key points or topics.

### Added

- `LICENSE` (the GPL v3 text, a copy of `COPYING.txt`) in the release package.
- `tests/marketplace_readiness_test.php`, which pins the directory's file-level rules (licence
  files, English-only package, data-only lang files, README naming every table and capability,
  per-plugin settings, scoped CSS, boilerplate), and `mutations/gates.conf` for the new guards.
- README sections "Settings", "External services" (what each Google API receives, the
  credentials it needs and what stops without them), "Data stored" (every table, what it keeps and
  until when, what a data request exports and deletes) and "Translations".

### Changed

- Only English ships: `lang/pt_br` and `lang/es` are export-ignored and leave the release
  package; on the plugins directory translations reach sites through AMOS.

## [v5.2-r1] - 2026-10-13

First release of this fork, version `2026042000`, for Moodle 5.2 and 5.3
(`requires = 2026042000`, `supported = [502, 503]`). It supports **clean installs only**: there is
no upgrade path from the upstream plugin or from earlier builds of this fork, and a site running
the upstream `mod_googlemeet` must uninstall it first (same component name; uninstalling deletes
its data).

### Added

- Organiser identity: the Moodle user who owns the organiser Google account is recorded in
  `googlemeet.creatoruserid`, and every background task that acts as the organiser (auto-sync,
  recording enrichment, Calendar updates and deletions, attendance fetch) and the attendance pages
  use that id. An activity without one falls back to the single active user with the organiser
  e-mail, without writing it back.
- Capability `mod/googlemeet:manageattendance` (editing teachers and managers, `RISK_PERSONAL`) for
  linking and unlinking Meet participants and refetching a session; viewing and downloading the
  attendance still need `mod/googlemeet:viewreports`.
- `googlemeet_recording_progress.markedviewed`: "Mark as viewed" shows a recording as viewed to the
  student without counting towards completion.
- Events for teacher writes: recording updated, trashed, restored and deleted; AI analysis updated
  and reviewed; sync requested; practice questions changed. A request that writes nothing logs
  nothing.
  "Delete all recordings" logs one summary event (`recordings_bulk_trashed`, with the count)
  instead of one event per recording.
- Indexes for the cron queries that scan every activity: `googlemeet_events(eventdate)`,
  `googlemeet_recordings(deleted, trashreason, timedeleted)` and
  `googlemeet_ai_analysis(status, nextretry)`.
- Tests: a privacy provider test over every user-linked table with `component_is_compliant()`, web
  service tests through `call_external_function()`, and the first `@javascript` Behat coverage
  (recording page, AI panel and question review, recordings list, practice session).
  Later Behat scenarios cover the live-class countdowns and room button, and the chapter link copy
  feedback with the clipboard accepted and refused.
- `COPYING.txt`, CI on the moodle-an-hochschulen reusable workflow (5.3 full matrix, 5.2 one
  database) with zero-warning gates, and the fleet's coding-standard and release-hygiene files.

### Changed

- Requires Moodle 5.2 and supports 5.2 and 5.3; Moodle 5.1 and older are not supported. Releases
  are named `v5.2-r<N>` and published on GitHub only.
- Who may name the organiser: on add, edit and restore the organiser e-mail must be the acting
  user's own (linked Google account or Moodle e-mail) unless they hold `moodle/site:config`. A
  restore keeps a mapped organiser only for that user or an administrator; otherwise it blanks the
  e-mail and turns auto-sync off.
- Capabilities: queueing question generation and `analyze_transcript` (paid Gemini calls) also need
  `mod/googlemeet:generateai`; the manual Drive sync and its status poll also need
  `mod/googlemeet:syncgoogledrive`. `editrecording` is flagged `RISK_XSS`, `generateai`
  `RISK_PERSONAL` and `removerecording` `RISK_DATALOSS`. Buttons are hidden from users who lack
  the capability.
- Guests can view the activity and enter the room but write nothing under the shared guest account
  (progress, practice answers, room-entry log).
- Completion: `completed` means measured viewing time only. Heartbeats credit at most the real time
  elapsed since the last accepted one, so bursts of calls or a second tab add nothing.
- Group fences: in separate-groups mode the teacher report and the attendance report show a user
  without "Access all groups" only the members of their own groups.
  The attendance session list's matched and participant counts cover the same participants the
  viewer is shown. A manager limited to their own groups may still refetch any session: sessions
  belong to the activity, and a refetch keeps every teacher's manual links and unlinks.
- Session days (cancelled dates, holidays, "Today", reminders, auto-sync) are read in the site's
  default timezone for every viewer and for cron, and the form pickers save the day the editor
  picked.
- Web services: the question actions no longer take a `sesskey` argument (core checks the session
  key of every AJAX request, and the argument kept token clients and the Moodle app out);
  plain-text returns are typed and run through `format_string()`; every function reads its
  validated parameters.
- Outbound calls: the Gemini File API upload goes through Moodle's HTTP client (site proxy,
  blocked hosts and ports, no redirects, upload URL limited to https on
  generativelanguage.googleapis.com); `yt-dlp` runs without a shell, only for an https
  `drive.google.com` link, and is killed after two minutes; the File API status poll backs off
  and waits out a 429 or 503.
- Backup and restore: only completed AI analyses are copied, so a course copy never queues its own
  paid Gemini run.
- Course reset with a new start date moves the session dates, holiday periods, cancelled dates
  and the sessions' Moodle calendar events, as restore does, re-opens auto-sync and attendance
  fetching for sessions moved into the future, and queues an update of each activity's Google
  Calendar event (cron applies it as the organiser).
- Links from the recordings list into a recording keep the search and topic filter, and the
  recording's back, previous and next links return to the same filtered list.
- The AI retry back-off table holds only reachable entries (2 h, 4 h, 8 h), and one shared clamp
  limits the requested question count (task, prompt and audit event).
- Privacy: reminders of one activity are exported together; deleting an activity's data removes
  its AI analyses whole, its sync log and its attendance fetch state; unmatched Meet participants,
  the sync log message (e-mail addresses stored masked) and every attendance column are declared.
- Code: the logic of `lib.php` and `locallib.php` moved to autoloaded classes under
  `classes/local/` and `classes/output/` with the same behaviour; pages render through Mustache
  templates instead of `html_writer`; the JavaScript uses the DOM API and Bootstrap 5 instead of
  jQuery; the per-user progress summary uses a request cache.
- Language packs sorted, every string requested by its literal id, and the Brazilian Portuguese
  pack completed.

### Fixed

- Deleting the activity on Moodle 5.2 and 5.3 deletes its Google Calendar event again.
- The join button accepts a stored Meet URL only in the exact form the activity form saves; the
  room code rule is shared by the form, the join button and the view page.
- Students no longer receive the stored error text of a failed AI analysis, unreviewed or
  in-progress AI content, or the analysis of a hidden recording.
- Names with `&` or `<` are no longer escaped twice in the practice session, the progress report,
  the Moodle app pages, the review panel and the OAuth issuer select.
- The recordings list's sort toggle, page links, search and topic filters keep their parameters
  (once each, encoded).
- The practice "Review missed questions (n)" and live score labels show their number; practice
  counts read only the latest version of each question bank entry, and a newer draft hides the
  older published version until it is published.
- "Generating questions" shows only for the recording whose generation is queued.
- A restored session's attendance shows its real matched and participant counts.
- Video analysis downloads read the Drive file id only from drive.google.com links, URL-encode the
  confirm token, stop at 2 GB and fail when cut short instead of uploading a truncated video.
- An analysis that hits a PHP error is marked failed instead of staying "processing"; an analysis
  without a detected language is no longer stored as Spanish.
- A failed manual sync reports a stable message instead of the raw exception text. It has its own
  string instead of the generic core server error.
- Publishing an AI summary logs its event once even when two requests publish at the same time.
- Recording materials that a browser would run as a page (HTML, XHTML, SVG, XML) are always sent as
  downloads.
- The Google logout on the activity settings page needs its session key.
- "Delete all recordings" trashes the recordings it listed, by id.
- SubRip (`.srt`) transcripts are used; holiday rows added with "Add" follow the recurrence switch;
  room URLs are rebuilt on `meet.google.com` from their code.
- `install.xml` no longer declares an empty-string default on a `NOT NULL` column.
- Silent AJAX failures show the standard error dialog; badges state their text colour.

### Removed

- `db/upgrade.php` and `db/upgradelib.php`, with every code path that only served rows written by
  earlier versions (the trash timestamp fix-up, enrichment and notification tasks queued without
  recording ids).
- `.github/workflows/moodle-release.yml`: it published to the moodle.org entry of
  `mod_googlemeet`, which belongs to the upstream project.
- `locallib.php`, the empty `db/install.php`, dead functions and methods, and unused or misspelt
  strings.
- `gemini_client::analyze_video()` and `generate_summary()`, which had no caller, with the prompt
  and parse helpers only they used.

This fork started from upstream release 2.30.1 of
[hyukudan/moodle-mod_googlemeet](https://github.com/hyukudan/moodle-mod_googlemeet), itself a fork
of [ronefel/moodle-mod_googlemeet](https://github.com/ronefel/moodle-mod_googlemeet) by Rone Santos.
