# Changelog

All notable changes to `mod_googlemeet`. Versions up to 2.30.1 are the upstream
(`hyukudan/moodle-mod_googlemeet`) releases this fork started from.

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
