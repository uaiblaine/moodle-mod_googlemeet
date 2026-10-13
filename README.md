moodle-mod_googlemeet
=====================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-mod_googlemeet/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-mod_googlemeet/actions/workflows/ci.yml?query=branch%3Amain)

Schedule Google Meet™ rooms from Moodle and turn the recordings into study material.

Google Meet for Moodle is an activity module. A teacher creates the activity, and the plugin
creates the Google Calendar event that carries the Meet room in the teacher's linked Google
account, lists the sessions (one-off or recurring, with holidays and cancelled dates), reminds the
participants and shows an "Enter room" button shortly before each session.

After a session the plugin finds the recording in the organiser's Google Drive and gives each
recording its own page: embedded player, transcript, "Notes by Gemini", materials, viewing progress
and, optionally, an analysis by the Google Gemini API (summary, key points, topics, timestamped
chapters and practice questions published to the course question bank after a teacher review).
Attendance can be read from the Google Meet REST API.

The plugin keeps its own tables for sessions, recordings, AI analyses, viewing progress, practice
answers and attendance, and uses core subsystems for the rest: the OAuth 2 issuer for Google, the
question bank for practice questions, the message API for notifications and custom completion
rules.

This repository is a fork that diverged for good from its upstream (see [Credits](#credits)). It
keeps the component name `mod_googlemeet` and supports **clean installs only**: there is no
upgrade path from the upstream plugin or from earlier builds of this fork. A site that runs the
upstream `mod_googlemeet` must uninstall it first (*Site administration > Plugins > Plugins
overview*); uninstalling deletes every Google Meet activity and its data, so back up what you need
before.


Requirements
------------

- Moodle 5.2 or later (tested up to Moodle 5.3)
- PHP 8.3 or later (the Moodle 5.2 minimum)
- A Google account (Google Workspace recommended) that organises the meetings and owns the
  recordings, and a Google Cloud project with an OAuth 2.0 client (see
  [Google Cloud setup](#google-cloud-setup))
- Moodle cron running every minute: the Drive sync, Calendar updates and AI analyses run as
  background tasks
- Optional: a Gemini API key (AI features) and [yt-dlp](https://github.com/yt-dlp/yt-dlp) (fast
  subtitle extraction)


Installation
------------

Install the plugin like any other plugin to folder `/mod/googlemeet`.

See http://docs.moodle.org/en/Installing_plugins for details on installing
Moodle plugins.

After installation, configure a Google OAuth 2 service and select it in the plugin settings
(*Site administration > Plugins > Activity modules > Google Meet*, setting `issuerid`). Until then
teachers cannot link a Google account and no room can be created. The AI features stay off until
an administrator enables them and enters a Gemini API key.


Usage
-----

### Google Cloud setup

1. Create (or choose) a project in the [Google Cloud console](https://console.cloud.google.com/).
2. Enable the APIs the plugin calls:
   - **Google Calendar API** (create and update the meeting event and its Meet room),
   - **Google Drive API** (list recordings, transcripts and "Notes by Gemini" documents, set
     sharing),
   - **Google Meet REST API**, only if you enable attendance.
3. Configure the OAuth consent screen and create an **OAuth client ID** of type *Web application*.
   Add `https://<your-moodle>/admin/oauth2callback.php` as an authorised redirect URI.
4. In Moodle, go to *Site administration > Server > OAuth 2 services*, create a **Google** service
   with that client ID and secret, and select it in the plugin's `issuerid` setting.
5. Teachers link their Google account from the activity page. The account that created the
   activity (the organiser) is the one whose Drive is synced and whose Calendar holds the event.

Scopes requested, in addition to the basic `openid profile email` of the Moodle Google issuer:

| Scope | Why |
|---|---|
| `https://www.googleapis.com/auth/drive` | Find the recordings, transcripts and notes in the organiser's Drive (Meet creates them, not this app, so a narrower scope cannot see them), download transcripts, and grant link sharing on recordings. |
| `https://www.googleapis.com/auth/calendar.events` | Create the meeting event with its Meet room, and update or delete it when the activity changes. |
| `https://www.googleapis.com/auth/meetings.space.readonly` | Only while attendance is enabled for the site: read conference records and participants. |

A sensitive or restricted scope such as `drive` may require Google's app verification if the
consent screen is external; an *Internal* consent screen in a Google Workspace domain avoids that.

**Recording sharing.** With `makerecordingspublic` on (default), each synced recording is shared
on Drive as "anyone with the link can view", so enrolled students, who are not the Drive owner,
can play the embedded video. Anyone who obtains the link can watch it without logging in to
Moodle. With the setting off, recordings stay private and only Google accounts with explicit
access (normally just the organiser) can play them.

### Live sessions

- The activity form creates a Google Meet room and Calendar event in the calendar of the
  teacher's linked Google account.
- One-off or recurring sessions (daily or weekly), exclusion periods (holidays) and cancelled
  dates with a reason. Days are read in the site's default timezone for every viewer and for cron.
- "Enter room" button active shortly before each session, upcoming-sessions block, Moodle calendar
  events with a "Go to classroom" action, and an "Add to my calendar" link (`.ics` file and Google
  Calendar template).
- E-mail, popup and Moodle app push reminders before each session: `minutesbefore` and an optional
  early reminder `notifyhoursbefore` hours before.
- Edits to the name, dates or recurrence are pushed to the Google Calendar event in the background.
- Optional attendance read from the Google Meet REST API (`attendanceenabled`, off by default),
  with a per-session report and CSV/XLSX export.

### Recordings

- Manual or automatic sync of the recordings from the organiser's Drive, matched by meeting name
  or a custom filter, with retries (`autosynchours_default`, `maxsyncattempts`,
  `syncretryinterval`) and a teacher alert when a recording never appears. Recurrences without
  recordings for `stalerecurrence_weeks` weeks are reported to administrators.
- List or card view, search (title, topics, summary; notes and transcript for teachers), topic
  filter, pagination, show or hide per recording, rename, and a trash with restore. Recordings a
  teacher trashed are purged after `trashretentiondays`; recordings the sync trashed because they
  disappeared from Drive are never purged automatically.
- "Notes by Gemini" documents generated by Google Meet are imported, cleaned and shown in a Notes
  tab.
- Per-recording page: embedded Drive player, chapters and clickable timestamps, summary, key-points
  checklist, transcript, notes, materials (file attachments) and practice questions, with
  previous and next navigation.
- Per-student viewing progress, a teacher report on viewing and practice per student (CSV/XLSX),
  and custom completion rules (watch N recordings, watch X % of the recordings, answer N practice
  questions). "Mark as viewed" shows a recording as viewed to the student but never counts towards
  completion; only measured viewing time does.
- Optional "new recording available" notifications that learners subscribe to.

### AI features (Google Gemini, optional)

1. Get an API key from [Google AI Studio](https://aistudio.google.com/app/apikey).
2. In the plugin settings enable AI (`enableai`), paste the key (`geminiapikey`) and choose a model
   (`aimodel`, default `gemini-3.5-flash`, with automatic fallback to `gemini-3.1-flash-lite`).
3. Optionally analyse new recordings automatically (`ai_autogenerate`), adjust the safety threshold
   (`aisafetythreshold`) and decide whether AI content needs a teacher review before students see
   it (`requireaireview`, on by default).
4. Set the teaching context (`aicontext`): a short description of the kind of classes on the site
   (subject area, audience, exam or qualification) added to every Gemini prompt so summaries,
   topics, chapters and questions use the right focus. Default `Online training classes`; leave it
   empty to send no context. The output language follows the recording (analysis) or the requested
   course language (questions, chapters).

What is sent to the Gemini API (Google's terms for the key's project apply; check whether your
tier allows Google to use the data):

- the recording transcript (Drive transcript, auto-generated subtitles or text pasted by the
  teacher), the recording title and duration, and the teaching context;
- as a last resort, when no transcript or subtitles exist and a teacher asks for it, the recording
  video, downloaded from Drive and uploaded to the Gemini File API (the uploaded file is deleted
  after the analysis);
- for practice questions, the transcript again.

Practice questions are created in the course question bank as drafts; teachers review and publish
them, and students practise one question at a time with feedback (formative, no grade).

**yt-dlp (optional).** When available, yt-dlp fetches Google Drive's auto-generated subtitles (a
few hundred KB) instead of downloading the whole video. Install it to a persistent location that
only administrators can write (never `/tmp`), and set `ytdlppath` or put it in the PATH of the web
and cron user:

```bash
curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
chmod +x /usr/local/bin/yt-dlp
```

`subtitlelanguage` (default `es`) selects the subtitle track.

### Scheduled tasks

| Task | Default schedule | Purpose |
|---|---|---|
| `\mod_googlemeet\task\notify_event` | every 5 min | Session reminders |
| `\mod_googlemeet\task\process_ai_analysis` | every 10 min | Queued AI analyses, stuck-analysis cleanup |
| `\mod_googlemeet\task\process_autosync` | every 15 min | Automatic recording sync after each session |
| `\mod_googlemeet\task\check_stale_recurrence` | Mondays 04:00 | Alert administrators about recurrences with no recordings |
| `\mod_googlemeet\task\purge_trash` | daily 03:xx | Purge recordings a teacher trashed, after `trashretentiondays` |
| `\mod_googlemeet\task\fetch_attendance` | every 30 min | Read attendance (does nothing while attendance is off) |

Adhoc tasks: background Drive sync, recording enrichment (notes and transcripts), video analysis,
question generation, new-recording notifications, Calendar update and delete.

### Command-line tools

Run from the Moodle root; `--help` on each lists its options.

- `cli/process_transcripts.php`: fetch subtitles and analyse recordings in bulk.
- `cli/backfill_chapters.php`: generate timestamped chapters for recordings that already have an
  analysis.
- `cli/backfill_questions.php`: generate draft practice questions for recordings that already have
  an analysis.
- `cli/cleanup_orphans.php`: find (and, when asked, delete) data whose recording or activity no
  longer exists; a dry run by default.

### Optional integrations

The plugin works without these; they are detected at run time.

- **Question publish check**: if a local plugin provides `local/questions/fugaslib.php` with the
  functions `fugas_campos_pregunta()` and `fugas_exigir_limpia()`, practice questions are validated
  by it before they are published. Without it the check is skipped (a debugging notice is shown).
- **E-mail wrapper**: if a class `\local_achievements\email_template` exists, the new-recording
  e-mail body is wrapped with its `wrap()` method; otherwise the plugin's own template is used.

### Moodle app

The activity, its recordings, AI content, materials and practice work in the Moodle app.


Capabilities
------------

- `mod/googlemeet:view` (manager, editing teacher, teacher, student, guest): view the activity,
  its sessions and recordings, and enter the room. Guests write nothing (no progress, practice
  answers or room-entry log).
- `mod/googlemeet:addinstance` (editing teacher, manager; cloned from
  `moodle/course:manageactivities`): add a Google Meet activity to a course.
- `mod/googlemeet:syncgoogledrive` (editing teacher, manager): sync the recordings with Google
  Drive; the manual sync also needs `mod/googlemeet:editrecording`.
- `mod/googlemeet:editrecording` (editing teacher, manager; `RISK_XSS`): rename, show and hide
  recordings, move one to the trash and restore it, manage materials, view transcripts and notes,
  edit and publish AI content.
- `mod/googlemeet:removerecording` (editing teacher, manager; `RISK_DATALOSS`): move all the
  recordings of an activity to the trash at once and purge recordings for good.
- `mod/googlemeet:generateai` (editing teacher, manager; `RISK_PERSONAL`): run AI analyses and
  question generation, which send transcripts (and, as a fallback, the video) to Google Gemini.
- `mod/googlemeet:managequestions` (editing teacher, teacher, manager; `RISK_SPAM`): review, edit,
  publish and discard practice questions; queueing a generation also needs
  `mod/googlemeet:generateai`.
- `mod/googlemeet:subscriberecordings` (student, teacher, editing teacher, manager): subscribe to
  "new recording available" notifications.
- `mod/googlemeet:viewreports` (teacher, editing teacher, manager; `RISK_PERSONAL`): view and
  download the viewing and practice report and the attendance report.
- `mod/googlemeet:manageattendance` (editing teacher, manager; `RISK_PERSONAL`): link and unlink
  Meet participants to Moodle users and refetch a session's attendance.


Privacy
-------

The privacy provider (metadata, request, userlist and user preference providers) declares and
handles:

- **Per-user tables**, exported and deleted per user: the sent-reminder log
  (`googlemeet_notify_done`), recording notification subscriptions (`googlemeet_recording_subs`),
  viewing progress (`googlemeet_recording_progress`, including "Mark as viewed"), practice answers
  (`googlemeet_practice_attempts`) and attendance read from Google Meet (`googlemeet_attendance`,
  with participants that match no Moodle user).
- **User preferences**: the last jump point and the key-points checklist of each recording.
- **Activity data that names a user**: the organiser's e-mail and Moodle user
  (`googlemeet.creatoremail`, `creatoruserid`) and the teacher who published an AI analysis
  (`googlemeet_ai_analysis.reviewedby`).
- **Content that may contain participants' names or voices** but is keyed to a recording, not a
  user: recording transcripts and Gemini notes (`googlemeet_recordings`), AI analyses, and the
  sync log, which stores e-mail addresses masked. A context-wide deletion removes them with the
  activity.
- **External locations**: Google Drive and Google Calendar (requests on behalf of the linked
  teacher account), the Google Meet REST API (attendance) and the Google Gemini API (transcripts
  and, as a fallback, the video). OAuth tokens belong to the `core_oauth2` subsystem.

Recordings shared "anyone with the link" are reachable outside Moodle. Practice answers, viewing
progress and attendance are personal data; set the site's data retention policy accordingly.


Troubleshooting
---------------

- **Teachers cannot link a Google account or create a room**: no OAuth 2 issuer is selected in
  `issuerid`, or the Google service in *Site administration > Server > OAuth 2 services* lacks the
  redirect URI or the enabled Calendar and Drive APIs.
- **Nothing happens after a sync request, a save or an AI request**: cron does not run, or runs
  too rarely. The admin status page of the plugin shows the last sync runs and stuck analyses.
- **A recording never appears**: recordings are matched by meeting name in the organiser's Drive.
  A renamed recording needs a custom filter in the activity settings, and only the organiser's
  linked account can sync.
- **Students cannot play a recording**: `makerecordingspublic` is off, so only accounts with
  explicit Drive access can play it.
- **Students do not see the AI summary or chapters**: with `requireaireview` on, AI content stays
  hidden until a teacher publishes it from the recording page.
- **The attendance report is empty**: the organiser must unlink and link the Google account again
  after attendance is enabled (the Meet scope is added only then), the account type must expose
  conference records (consumer Gmail accounts may not), and Google keeps them for about 30 days.
  Matching participants to Moodle users by name is heuristic; teachers can link them by hand.
- **Viewing progress looks approximate**: the embedded Drive player does not expose the playback
  position, so progress counts the time the page is visible, and "Continue at mm:ss" is the last
  point the user jumped to. Chapter jumps use Drive's undocumented `?t=` preview parameter.

Security problems: please do not open public issues; report them privately through
[GitHub Security Advisories](https://github.com/uaiblaine/moodle-mod_googlemeet/security/advisories/new).


Credits
-------

This plugin is a fork of [hyukudan/moodle-mod_googlemeet](https://github.com/hyukudan/moodle-mod_googlemeet)
(PreparaOposiciones), itself a fork of the original
[ronefel/moodle-mod_googlemeet](https://github.com/ronefel/moodle-mod_googlemeet) by Rone Santos.
It started from upstream release 2.30.1 and is developed independently since; thanks to both
upstream authors for the plugin this work builds on. Parts of this fork were developed with the
assistance of AI coding tools.


License
-------

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

Copyright: 2026 Anderson Blaine; portions 2020-2023 Rone Santos and 2026 PreparaOposiciones.

Google Meet, Google Drive, Google Calendar and Gemini are trademarks of Google LLC. This plugin is
not affiliated with or endorsed by Google.
