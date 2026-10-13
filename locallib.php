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
 * Private googlemeet module utility functions
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\client;
use mod_googlemeet\local\recording_format;
use mod_googlemeet\local\recording_progress;
use mod_googlemeet\local\recording_search;
use mod_googlemeet\local\recordings;
use mod_googlemeet\local\schedule;
use mod_googlemeet\question_service;

require_once("$CFG->dirroot/mod/googlemeet/lib.php");

/**
 * Print googlemeet header.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @return void
 */
function googlemeet_print_header($googlemeet, $cm, $course) {
    global $PAGE, $OUTPUT;

    $PAGE->set_title($course->shortname . ': ' . $googlemeet->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->set_activity_record($googlemeet);
    echo $OUTPUT->header();
}

/**
 * Handle the write actions triggered from the activity view (logout / sync).
 *
 * Both actions need mod/googlemeet:editrecording (without it the call does nothing) and a valid
 * sesskey. Sync also needs a POST, so a GET to ?sync=1 is ignored even with a valid sesskey; it
 * queues a background sync and redirects to the view. Logout stays reachable by GET (sesskey
 * protected) for the OAuth account link and does not redirect: the page keeps rendering.
 *
 * @param object $googlemeet the googlemeet instance record
 * @param object $cm the course module record
 * @param object $course the course record
 * @return void
 */
function googlemeet_handle_view_actions($googlemeet, $cm, $course) {
    $context = context_module::instance($cm->id);

    if (!has_capability('mod/googlemeet:editrecording', $context)) {
        return;
    }

    $client = new client();

    $logout = optional_param('logout', 0, PARAM_BOOL);
    if ($logout && confirm_sesskey()) {
        $client->logout();
    }

    $sync = optional_param('sync', 0, PARAM_BOOL);
    // Sync is a write: POST only, on top of the sesskey check.
    if ($sync && confirm_sesskey() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // The queued task syncs as this user, which requires syncgoogledrive: fail now, not in cron.
        require_capability('mod/googlemeet:syncgoogledrive', $context);
        // No-JS fallback of the async button. Only queue the background sync (the page polls its
        // status when JS is on); never run Google calls inside the request.
        $redirecturl = new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
        $queued = \mod_googlemeet\local\sync_manager::request($googlemeet, (int)$GLOBALS['USER']->id);
        redirect(
            $redirecturl,
            get_string($queued ? 'sync_status_queued' : 'sync_already_running', 'googlemeet'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
}

/**
 * Handle the user's recording notification subscription toggle.
 *
 * @param object $googlemeet the googlemeet instance record
 * @param object $cm the course module record
 * @return void
 */
function googlemeet_handle_subscription_action($googlemeet, $cm) {
    global $DB, $USER;

    $subscribe = optional_param('subscribe', -1, PARAM_INT);
    if ($subscribe === -1) {
        return;
    }

    $context = context_module::instance($cm->id);
    require_capability('mod/googlemeet:subscriberecordings', $context);
    require_sesskey();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidrequest', 'error');
    }

    if ($subscribe) {
        if (!$DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id])) {
            $record = new stdClass();
            $record->googlemeetid = $googlemeet->id;
            $record->userid = $USER->id;
            $record->timecreated = time();
            $DB->insert_record('googlemeet_recording_subs', $record);
        }
    } else {
        $DB->delete_records('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id]);
    }

    redirect(new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]));
}

/**
 * Build the context of the classroom hero (mod_googlemeet/hero): next session, schedule, room
 * button, practice entry, "continue watching" card and progress summary.
 *
 * @param object $googlemeet Activity record.
 * @param object $cm Course-module record.
 * @param context_module $context Module context.
 * @param array $upcomingeventscontext Context returned by schedule::get_upcoming_events().
 * @param bool $hasvalidmeeturl Whether the activity has a valid Meet URL.
 * @param array $scheduleeventscontext Full schedule context returned by schedule::get_upcoming_events().
 * @return array Template context.
 */
function googlemeet_get_classroom_hero_context(
    $googlemeet,
    $cm,
    context_module $context,
    array $upcomingeventscontext,
    bool $hasvalidmeeturl,
    array $scheduleeventscontext = []
): array {
    $nextevent = null;
    $upcomingevents = [];
    $sourceevents = !empty($scheduleeventscontext['upcomingevents'])
        ? $scheduleeventscontext['upcomingevents']
        : ($upcomingeventscontext['upcomingevents'] ?? []);
    $maxupcomingevents = max(1, min(10, (int)($googlemeet->maxupcomingevents ?? 3)));
    foreach ($sourceevents as $event) {
        if (!empty($event->iscancelled)) {
            continue;
        }
        $upcomingevents[] = $event;
        if (!$nextevent) {
            $nextevent = $event;
        }
        if (count($upcomingevents) >= $maxupcomingevents) {
            break;
        }
    }

    $eventcontext = [];
    if ($nextevent) {
        $statuslabel = get_string('event_status_scheduled', 'googlemeet');
        if (!empty($nextevent->islive)) {
            $statuslabel = get_string('event_status_live', 'googlemeet');
        } else if (!empty($nextevent->issoon)) {
            $statuslabel = get_string('event_status_soon', 'googlemeet');
        }
        $eventcontext = [
            'hasnextevent' => true,
            'nexteventislive' => !empty($nextevent->islive),
            'nexteventissoon' => !empty($nextevent->issoon),
            'nexteventisscheduled' => !empty($nextevent->isscheduled),
            'nexteventstatus' => $nextevent->status,
            'nexteventstatuslabel' => $statuslabel,
            'nexteventtoday' => !empty($nextevent->today),
            'nexteventdate' => $nextevent->startdate,
            'nexteventstarttime' => $nextevent->starttime,
            'nexteventendtime' => $nextevent->endtime,
            'nexteventtimeinfo' => $nextevent->timeinfo,
            'nexteventtimestamp' => $nextevent->timestamp,
            'nexteventduration' => $nextevent->durationformatted,
            'nexteventhascountdown' => empty($nextevent->islive),
            'countdownprefix' => $nextevent->countdownprefix ?? get_string('event_countdown_starts_in_prefix', 'googlemeet'),
            'countdownexpired' => $nextevent->countdownexpired ?? get_string('event_countdown_started', 'googlemeet'),
        ];
    }

    $nextclassevents = [];
    foreach (array_slice($upcomingevents, 1) as $event) {
        $nextclassevents[] = [
            'date' => $event->compactdate,
            'starttime' => $event->starttime,
            'endtime' => $event->endtime,
            'duration' => $event->durationformatted,
        ];
    }

    $scheduleevents = [];
    $nextscheduledmarked = false;
    foreach (($scheduleeventscontext['upcomingevents'] ?? []) as $event) {
        $isnext = empty($event->iscancelled) && !$nextscheduledmarked;
        if ($isnext) {
            $nextscheduledmarked = true;
        }
        $scheduleevents[] = [
            'isnext' => $isnext,
            'iscancelled' => !empty($event->iscancelled),
            'cancelledreason' => $event->cancelledreason ?? '',
            'date' => $event->startdate,
            'starttime' => $event->starttime,
            'endtime' => $event->endtime,
            'duration' => $event->durationformatted,
        ];
    }

    $progresssummary = googlemeet_get_progress_summary($googlemeet, $context);
    $isstudentview = !has_capability('mod/googlemeet:editrecording', $context);

    return array_merge(
        [
        'activityname' => format_string($googlemeet->name),
        // Without upcoming sessions the hero collapses instead of leaving a tall empty card.
        'herocompact' => !$nextevent,
        'showheroprogress' => $isstudentview && $progresssummary['total'] > 0,
        'hasrecordedcount' => $progresssummary['total'] > 0,
        'recordedcountlabel' => get_string('hero_recorded_count', 'googlemeet', $progresssummary['total']),
        'hasnextevent' => false,
        'hasnextclassevents' => !empty($nextclassevents),
        'nextclassevents' => $nextclassevents,
        'hasscheduleevents' => !empty($scheduleevents),
        'scheduleevents' => $scheduleevents,
        'courseid' => (int)$cm->course,
        'calendarurl' => (new moodle_url('/calendar/view.php', ['view' => 'upcoming', 'course' => (int)$cm->course]))->out(false),
        'hascontinue' => false,
        'hasroomcta' => false,
        // Activity-wide practice entry point; null (hidden) for question managers or without ready questions.
        'practicecta' => \mod_googlemeet\local\practice_attempts::hero_cta_context(
            $googlemeet,
            $cm,
            $context,
            (int)$GLOBALS['USER']->id
        ),
        ],
        $eventcontext,
        googlemeet_get_room_cta_context($googlemeet, $context, $nextevent, $hasvalidmeeturl),
        googlemeet_get_continue_watching_context($googlemeet, $cm, $context),
        googlemeet_progress_summary_context($progresssummary)
    );
}

/**
 * Build the Meet room CTA context for the classroom hero.
 *
 * @param object $googlemeet Activity record.
 * @param context_module $context Module context.
 * @param stdClass|null $nextevent First non-cancelled upcoming/current event.
 * @param bool $hasvalidmeeturl Whether the activity has a valid Meet URL.
 * @return array Template context.
 */
function googlemeet_get_room_cta_context(
    $googlemeet,
    context_module $context,
    ?stdClass $nextevent,
    bool $hasvalidmeeturl
): array {
    if (!$hasvalidmeeturl) {
        return ['hasroomcta' => false];
    }

    $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
    $roomactive = false;
    $roomclasses = 'btn googlemeet-room-cta';
    $roomlabel = '';
    $roomnote = '';
    $roomhascountdown = false;

    if ($caneditrecording) {
        $roomactive = true;
        $roomclasses .= ' btn-primary';
        $roomlabel = get_string('room_enter_teacher', 'googlemeet');
        $roomnote = get_string('room_teacher_note', 'googlemeet');
        if ($nextevent && !empty($nextevent->islive)) {
            $roomclasses .= ' googlemeet-room-cta-live';
            $roomlabel = get_string('room_enter_live', 'googlemeet');
        } else if ($nextevent && !empty($nextevent->issoon)) {
            $roomclasses .= ' googlemeet-room-cta-soon';
            $roomlabel = get_string('room_enter_soon', 'googlemeet');
            $roomhascountdown = true;
        }
    } else if ($nextevent && !empty($nextevent->islive)) {
        $roomactive = true;
        $roomclasses .= ' btn-primary googlemeet-room-cta-live';
        $roomlabel = get_string('room_enter_live', 'googlemeet');
    } else if ($nextevent && !empty($nextevent->issoon)) {
        // Students may enter from "soon", 30 minutes before the start (see schedule::get_upcoming_events()).
        $roomactive = true;
        $roomclasses .= ' btn-primary googlemeet-room-cta-soon';
        $roomlabel = get_string('room_enter_soon', 'googlemeet');
        $roomhascountdown = true;
    } else if ($nextevent) {
        $roomclasses .= ' btn-outline-secondary disabled googlemeet-room-cta-disabled';
        $roomdate = !empty($nextevent->today)
            ? get_string('today', 'googlemeet') . ' ' . $nextevent->starttime
            : $nextevent->startdate . ' ' . $nextevent->starttime;
        $roomlabel = get_string('room_next_class', 'googlemeet', $roomdate);
    }

    if ($roomlabel === '') {
        return ['hasroomcta' => false];
    }

    return [
        'hasroomcta' => true,
        'roomactive' => $roomactive,
        'roomdisabled' => !$roomactive,
        'roomclasses' => $roomclasses,
        // Go through enter.php so the entry is logged before redirecting to Meet.
        'roomurl' => \mod_googlemeet\local\room_entry::entry_url((int)$context->instanceid)->out(false),
        'roomlabel' => $roomlabel,
        'roomnote' => $roomnote,
        'roomhascountdown' => $roomhascountdown,
        'roomtargetts' => $nextevent ? $nextevent->timestamp : 0,
        'roomcountdownprefix' => get_string('event_countdown_starts_in_prefix', 'googlemeet'),
        'roomcountdownexpired' => get_string('event_countdown_started', 'googlemeet'),
    ];
}

/**
 * Build the "continue watching" card context for the current user.
 *
 * @param object $googlemeet Activity record.
 * @param object $cm Course-module record.
 * @param context_module $context Module context.
 * @return array Template context.
 */
function googlemeet_get_continue_watching_context($googlemeet, $cm, context_module $context): array {
    global $DB, $USER;

    $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
    $visiblewhere = $caneditrecording ? '' : ' AND r.visible = 1';
    $params = [
        'googlemeetid' => $googlemeet->id,
        'userid' => $USER->id,
    ];

    $sql = "SELECT r.id,
                   r.name,
                   r.createdtime,
                   r.duration,
                   r.webviewlink,
                   grp.watchedseconds AS userwatchedseconds,
                   grp.completed AS usercompleted,
                   grp.markedviewed AS usermarkedviewed,
                   a.summary,
                   a.reviewed AS aireviewed
              FROM {googlemeet_recordings} r
              JOIN {googlemeet_recording_progress} grp ON grp.recordingid = r.id
         LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id AND a.status = 'completed'
             WHERE r.googlemeetid = :googlemeetid
               AND r.deleted = 0
               {$visiblewhere}
               AND grp.userid = :userid
               AND grp.completed = 0
               AND grp.markedviewed = 0
               AND grp.watchedseconds > 0
          ORDER BY grp.timemodified DESC, r.createdtime DESC";
    $records = $DB->get_records_sql($sql, $params, 0, 1);
    $recording = reset($records);
    $fallbackrecording = false;

    if (!$recording) {
        $sql = "SELECT r.id,
                       r.name,
                       r.createdtime,
                       r.duration,
                       r.webviewlink,
                       grp.watchedseconds AS userwatchedseconds,
                       grp.completed AS usercompleted,
                       grp.markedviewed AS usermarkedviewed,
                       a.summary,
                       a.reviewed AS aireviewed
                  FROM {googlemeet_recordings} r
             LEFT JOIN {googlemeet_recording_progress} grp
                    ON grp.recordingid = r.id AND grp.userid = :userid
             LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id AND a.status = 'completed'
                 WHERE r.googlemeetid = :googlemeetid
                   AND r.deleted = 0
                   {$visiblewhere}
                   AND (grp.id IS NULL OR (grp.completed = 0 AND grp.markedviewed = 0))
              ORDER BY r.createdtime DESC, r.id DESC";
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        $recording = reset($records);
        $fallbackrecording = true;
    }

    if (!$recording) {
        return ['hascontinue' => false];
    }
    // No unreviewed summary snippet for students.
    if (
        !empty($recording->summary) && !$caneditrecording && \mod_googlemeet\local\ai_review::is_pending_review(
            (object)['status' => 'completed', 'reviewed' => $recording->aireviewed]
        )
    ) {
        $recording->summary = '';
    }

    $progress = null;
    if (isset($recording->userwatchedseconds) || isset($recording->usercompleted)) {
        $progress = (object) [
            'watchedseconds' => (int)($recording->userwatchedseconds ?? 0),
            'completed' => (int)($recording->usercompleted ?? 0),
            'markedviewed' => (int)($recording->usermarkedviewed ?? 0),
        ];
    }
    $progressstate = recording_progress::recording_progress_state($recording, $progress);
    $latestclassfallback = $fallbackrecording && empty($progressstate['progresswatchedseconds']);

    return [
        'hascontinue' => true,
        'continueurl' => (new moodle_url(
            '/mod/googlemeet/view.php',
            ['id' => $cm->id, 'recording' => $recording->id]
        ))->out(false),
        'continuetitle' => format_string(recordings::get_lesson_titles($googlemeet, $caneditrecording)[$recording->id]['title']
            ?? recordings::display_name((string)$recording->name)),
        'continueoriginaltitle' => $recording->name,
        'continuedate' => userdate($recording->createdtime, get_string('strftimedmy', 'googlemeet')),
        'continueduration' => $recording->duration,
        'continuesummary' => !empty($recording->summary)
            ? recording_format::truncate_summary((string)$recording->summary, 180) : '',
        'continuelabel' => get_string($latestclassfallback ? 'continue_latest_label' : 'continue_watching_label', 'googlemeet'),
        'continuectalabel' => get_string($latestclassfallback ? 'continue_latest_cta' : 'continue_watching_cta', 'googlemeet'),
        'continueprogresslabel' => $progressstate['progressstatuslabel'],
        'continueprogressarialabel' => $progressstate['progressarialabel'],
        'continueprogresspct' => $progressstate['progresspct'],
        'continueprogresspcttext' => $progressstate['progresspcttext'],
        'continueprogressbarstyle' => $progressstate['progressbarstyle'],
        'continueshowprogressbar' => $progressstate['showprogressbar'],
        'continueprogresscompleted' => $progressstate['progresscompleted'],
        'continueprogresspartial' => $progressstate['progresspartial'],
        'continueprogressunseen' => $progressstate['progressunseen'],
        'continueprogressbadgeclass' => $progressstate['progresscompleted'] ? 'googlemeet-progress-badge-completed'
            : ($progressstate['progresspartial'] ? 'googlemeet-progress-badge-partial' : 'googlemeet-progress-badge-unseen'),
    ];
}

/**
 * The current user's viewing progress over the visible recordings of an activity (cached per request).
 *
 * "watched" counts the recordings the user sees as viewed (measured completion or their own
 * "Mark as viewed") and "started" those with viewing time but not viewed, the same split as the
 * per-lesson badges of recording_progress::recording_progress_state(), so the hero summary never
 * contradicts them. Activity completion counts measured completion only
 * ({@see \mod_googlemeet\completion\custom_completion::count_watched()}), so the two may differ.
 *
 * @param stdClass $googlemeet Activity record.
 * @param context_module $context Module context.
 * @return array ['total' => int, 'watched' => int, 'started' => int, 'pending' => int, 'pct' => int,
 *     'startedpct' => int, 'viewedids' => int[]]
 */
function googlemeet_get_progress_summary(stdClass $googlemeet, context_module $context): array {
    global $DB, $USER;
    // A request cache rather than a static: it is per request too, and core purges it between unit tests.
    $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'mod_googlemeet', 'progresssummary');
    $key = (int)$googlemeet->id . '_' . (int)$USER->id;
    $cached = $cache->get($key);
    if ($cached !== false) {
        return $cached;
    }

    $visiblewhere = has_capability('mod/googlemeet:editrecording', $context) ? '' : 'AND r.visible = 1';
    $params = ['googlemeetid' => (int)$googlemeet->id, 'userid' => (int)$USER->id];
    $total = (int)$DB->count_records_sql(
        "SELECT COUNT(1) FROM {googlemeet_recordings} r
          WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 {$visiblewhere}",
        $params
    );
    $viewedids = array_map('intval', $DB->get_fieldset_sql(
        "SELECT r.id
           FROM {googlemeet_recordings} r
           JOIN {googlemeet_recording_progress} p ON p.recordingid = r.id AND p.userid = :userid
                AND (p.completed = 1 OR p.markedviewed = 1)
          WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 {$visiblewhere}",
        $params
    ));
    $watched = count($viewedids);
    $started = (int)$DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {googlemeet_recordings} r
           JOIN {googlemeet_recording_progress} p ON p.recordingid = r.id AND p.userid = :userid
                AND p.completed = 0 AND p.markedviewed = 0 AND p.watchedseconds > 0
          WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 {$visiblewhere}",
        $params
    );
    $pct = $total > 0 ? (int)round($watched * 100 / $total) : 0;

    $summary = [
        'total' => $total,
        'watched' => $watched,
        'started' => $started,
        'pending' => max(0, $total - $watched),
        'pct' => $pct,
        // Capped so that both segments of the stacked bar never add up to more than 100%.
        'startedpct' => $total > 0 ? min(100 - $pct, (int)round($started * 100 / $total)) : 0,
        'viewedids' => $viewedids,
    ];
    $cache->set($key, $summary);
    return $summary;
}

/**
 * Template fields for the "N viewed · M started of T classes" progress summary.
 *
 * @param array $summary Result of googlemeet_get_progress_summary().
 * @return array
 */
function googlemeet_progress_summary_context(array $summary): array {
    $started = (int)($summary['started'] ?? 0);
    $startedpct = (int)($summary['startedpct'] ?? 0);
    $watchedpart = get_string(
        $summary['watched'] === 1 ? 'progress_count_watched_one' : 'progress_count_watched',
        'googlemeet',
        $summary['watched']
    );
    if ($started > 0) {
        $startedpart = get_string(
            $started === 1 ? 'progress_count_started_one' : 'progress_count_started',
            'googlemeet',
            $started
        );
        $label = get_string(
            'progress_summary_detail',
            'googlemeet',
            ['watched' => $watchedpart, 'started' => $startedpart, 'total' => $summary['total']]
        );
    } else {
        $label = get_string(
            'progress_summary_watchedonly',
            'googlemeet',
            ['watched' => $watchedpart, 'total' => $summary['total']]
        );
    }
    return [
        'hasprogresssummary' => $summary['total'] > 0,
        'watchedcount' => $summary['watched'],
        'totalcount' => $summary['total'],
        'pendingcount' => $summary['pending'],
        'progresssummarypct' => $summary['pct'],
        'progresssummarybarstyle' => 'width: ' . $summary['pct'] . '%;',
        'startedcount' => $started,
        'progressstartedpct' => $startedpct,
        'progressstartedbarstyle' => 'width: ' . $startedpct . '%;',
        'hasprogressstarted' => $started > 0,
        'progresssummarylabel' => $label,
        'progresssummarydone' => $summary['total'] > 0 && $summary['pending'] === 0,
    ];
}

/**
 * Per-recording count of students who opened / completed each recording (teacher list view).
 *
 * "Opened" means a googlemeet_recording_progress row exists (some viewing time was recorded). Users who can
 * edit recordings (teachers) are left out so the numbers describe students only. One grouped query per page.
 *
 * @param context_module $context Module context.
 * @param int[] $recordingids Recordings on the current page.
 * @return stdClass[] Keyed by recording id: {recordingid, opened, completedcount}.
 */
function googlemeet_get_recordings_student_stats(context_module $context, array $recordingids): array {
    global $DB;

    if (empty($recordingids)) {
        return [];
    }
    [$insql, $params] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'statsrid');
    $where = "p.recordingid {$insql}";
    $editorids = array_keys(get_users_by_capability($context, 'mod/googlemeet:editrecording', 'u.id'));
    if ($editorids) {
        [$notinsql, $notinparams] = $DB->get_in_or_equal($editorids, SQL_PARAMS_NAMED, 'statsuid', false);
        $where .= " AND p.userid {$notinsql}";
        $params += $notinparams;
    }
    return $DB->get_records_sql(
        "SELECT p.recordingid, COUNT(1) AS opened, SUM(p.completed) AS completedcount
           FROM {googlemeet_recording_progress} p
          WHERE {$where}
       GROUP BY p.recordingid",
        $params
    );
}

/**
 * Print the recordings panel: search and topic filters, the paginated list or cards and, for
 * teachers, the trash and the Google Drive sync panel.
 *
 * Prints nothing when the Google client is not enabled.
 *
 * @param object $googlemeet
 * @param object $cm
 * @param object $context
 * @param int $page Current page number (0-based).
 * @param string|null $orderoverride Optional order override from URL parameter.
 * @param string $query Free-text search.
 * @param string $topic Topic filter.
 * @param bool $pendingonly Only recordings the current user has not viewed (ignored with editrecording).
 * @return void
 */
function googlemeet_print_recordings(
    $googlemeet,
    $cm,
    $context,
    $page = 0,
    $orderoverride = null,
    $query = '',
    $topic = '',
    bool $pendingonly = false
) {
    global $CFG, $DB, $PAGE, $OUTPUT, $USER;

    $config = get_config('googlemeet');

    $client = new client();
    if (!$client->enabled) {
        return;
    }

    $params = ['googlemeetid' => $googlemeet->id];
    $hascapability = has_capability('mod/googlemeet:editrecording', $context);
    if (!$hascapability) {
        $params['visible'] = true;
    }

    // Recordings view preference (cards|list). Used both for the view modifier class on the
    // outer wrapper (so list view can use the full content width) and for the template branch.
    $recordingsview = get_user_preferences('mod_googlemeet_recordings_view', 'list');
    if ($recordingsview !== 'cards' && $recordingsview !== 'list') {
        $recordingsview = 'list';
    }

    $html = '<div id="googlemeet_recordings" class="googlemeet_recordings googlemeet-view-' . $recordingsview . '">';

    // AI features need the site switch and an API key.
    $aiconfig = get_config('googlemeet');
    $aienabled = !empty($aiconfig->enableai) && !empty($aiconfig->geminiapikey);
    $cangenerateai = $aienabled && has_capability('mod/googlemeet:generateai', $context);

    // Get pagination settings.
    $maxrecordings = isset($googlemeet->maxrecordings) ? (int) $googlemeet->maxrecordings : 12;
    $maxrecordings = max(1, min(20, $maxrecordings));

    // Get order - use override if provided, otherwise use instance setting.
    $order = $orderoverride !== null ? $orderoverride : ($googlemeet->recordingsorder ?? 'DESC');
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    $page = max(0, (int) $page);

    // Fetch every recording (no SQL limit): the search and topic filters run in PHP before pagination.
    $allrecordings = recordings::list_recordings($params, $aienabled, $order, 0, 0);

    // Distinct topics for the filter chips come from the unfiltered set.
    $alltopics = recording_search::collect_topics($allrecordings);

    // Distinguishable lesson titles (computed over the whole activity so duplicates are detected).
    $titleitems = [];
    foreach ($allrecordings as $recording) {
        $titleitems[$recording->id] = ['name' => (string)$recording->name, 'topics' => $recording->aitopics ?? []];
    }
    $lessontitles = recordings::assign_lesson_titles(
        $titleitems,
        [(string)$googlemeet->name, (string)($googlemeet->originalname ?? '')]
    );
    foreach ($allrecordings as $recording) {
        if (isset($lessontitles[$recording->id])) {
            $recording->displayname = $lessontitles[$recording->id]['title'];
        }
    }

    // Apply filters in PHP (topics are stored as JSON; this stays DB-portable).
    if (trim((string)$query) !== '') {
        if ($hascapability) {
            $allrecordings = recording_search::load_recording_search_content($allrecordings, true);
        }
        $allrecordings = recording_search::filter_recordings_by_query($allrecordings, (string)$query, $hascapability);
    }
    if (trim((string)$topic) !== '') {
        $allrecordings = recording_search::filter_recordings_by_topic($allrecordings, (string)$topic);
    }
    $progresssummary = googlemeet_get_progress_summary($googlemeet, $context);
    $showprogresssummary = !$hascapability && $progresssummary['total'] > 0;
    $pendingonly = $pendingonly && $showprogresssummary;
    if ($pendingonly) {
        $viewed = array_flip($progresssummary['viewedids']);
        $allrecordings = array_values(array_filter($allrecordings, static function ($recording) use ($viewed) {
            return !isset($viewed[(int)$recording->id]);
        }));
    }

    $totalrecordings = count($allrecordings);
    $totalpages = max(1, (int)ceil($totalrecordings / $maxrecordings));
    $page = min($page, max(0, $totalpages - 1)); // Ensure page is within bounds.
    $offset = $page * $maxrecordings;
    $recordings = array_slice($allrecordings, $offset, $maxrecordings);

    $progressbyrecording = [];
    if (!empty($recordings)) {
        $recordingids = array_map(static function ($recording) {
            return (int)$recording->id;
        }, $recordings);
        [$insql, $inparams] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'progressid');
        $progressbyrecording = $DB->get_records_sql(
            "SELECT recordingid, watchedseconds, completed, markedviewed
               FROM {googlemeet_recording_progress}
              WHERE userid = :userid
                AND recordingid {$insql}",
            ['userid' => $USER->id] + $inparams
        );
    }

    // Teachers see their students' activity per lesson instead of their own viewing progress.
    $teacherstats = ($hascapability && !empty($recordings))
        ? googlemeet_get_recordings_student_stats($context, $recordingids) : [];

    // Page-level aggregates instead of one question/file query per recording.
    $pagerecordingids = array_map(static fn($r) => (int)$r->id, $recordings);
    $questioncounts = \mod_googlemeet\local\list_aggregates::published_question_counts(
        $googlemeet,
        $cm,
        $context,
        $pagerecordingids
    );
    $materialsbyrecording = \mod_googlemeet\local\list_aggregates::materials_by_recording($context, $pagerecordingids);
    foreach ($recordings as $recording) {
        $recordingprogress = recording_progress::recording_progress_state($recording, $progressbyrecording[$recording->id] ?? null);
        foreach ($recordingprogress as $key => $value) {
            $recording->$key = $value;
        }
        if ($hascapability) {
            $stats = $teacherstats[$recording->id] ?? null;
            $opened = $stats ? (int)$stats->opened : 0;
            $completedcount = $stats ? (int)$stats->completedcount : 0;
            $recording->teacherstatsopened = $opened;
            $recording->teacherstatslabel = $opened === 0 ? get_string('list_teacherstats_none', 'googlemeet')
                : get_string($opened === 1 ? 'list_teacherstats_opened_one' : 'list_teacherstats_opened', 'googlemeet', $opened);
            $recording->teacherstatshascompleted = $completedcount > 0;
            $recording->teacherstatscompletedlabel = $completedcount > 0
                ? get_string('list_teacherstats_completed', 'googlemeet', $completedcount) : '';
        }
        $recording->publishedquestioncount = $questioncounts[(int)$recording->id] ?? 0;
        $recording->haspublishedquestions = $recording->publishedquestioncount > 0;
        $urlparams = ['id' => $cm->id, 'recording' => $recording->id];
        if ($page > 0) {
            $urlparams['rpage'] = $page;
        }
        $urlparams['rorder'] = $order;
        $recording->huburl = (new moodle_url('/mod/googlemeet/view.php', $urlparams))->out(false);
        $materialsurlparams = $urlparams;
        $materialsurlparams['tab'] = 'materials';
        $recording->materialshuburl = (new moodle_url('/mod/googlemeet/view.php', $materialsurlparams))->out(false);
        // Surface attached materials directly in the recordings list so teachers can
        // upload/manage from here and students can download without entering the hub.
        $materials = $materialsbyrecording[(int)$recording->id] ?? [];
        $recording->materials = $materials;
        $recording->hasmaterials = !empty($materials);
        $recording->materialcount = count($materials);
        $recording->cardmaterials = array_slice($materials, 0, 2);
        $recording->cardmaterialoverflow = max(0, count($materials) - count($recording->cardmaterials));
        $recording->cardhasmaterialoverflow = $recording->cardmaterialoverflow > 0;
        $recording->managematerialsurl = (new moodle_url(
            '/mod/googlemeet/material.php',
            ['id' => $cm->id, 'recording' => $recording->id]
        ))->out(false);
    }
    $prevgroup = null;
    foreach ($recordings as $recording) {
        $recording->showdategroup = ($recording->dategroup !== $prevgroup);
        $prevgroup = $recording->dategroup;
    }
    $topicchips = [];
    foreach ($alltopics as $t) {
        $topicchips[] = [
            'text' => $t,
            'url' => (new moodle_url(
                '/mod/googlemeet/view.php',
                ['id' => $cm->id, 'topic' => $t, 'rorder' => $order]
            ))->out(false),
            'active' => (recording_format::fold($t) === recording_format::fold((string)$topic)),
        ];
    }
    $cansubscriberecordings = has_capability('mod/googlemeet:subscriberecordings', $context);
    $issubscribed = $cansubscriberecordings && $DB->record_exists(
        'googlemeet_recording_subs',
        ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id]
    );
    $canpurge = has_capability('mod/googlemeet:removerecording', $context);
    $deletedrecordings = [];
    if ($hascapability) {
        $deletedrecords = $DB->get_records(
            'googlemeet_recordings',
            ['googlemeetid' => $googlemeet->id, 'deleted' => 1],
            'timedeleted DESC, createdtime DESC',
            'id,name,createdtime,timedeleted,trashreason'
        );
        $retentiondays = \mod_googlemeet\local\recording_cleanup::get_retention_days();
        foreach ($deletedrecords as $deletedrecording) {
            $deletedrecordings[] = [
                'id' => $deletedrecording->id,
                'name' => $deletedrecording->name,
                'createdtimeformatted' => userdate($deletedrecording->createdtime),
                'timedeletedformatted' => !empty($deletedrecording->timedeleted)
                    ? userdate($deletedrecording->timedeleted)
                    : get_string('never', 'googlemeet'),
                // When the retention task will purge it ('' when automatic purge is off).
                'purgenotice' => \mod_googlemeet\local\recording_cleanup::purge_notice(
                    (int)$deletedrecording->timedeleted,
                    $retentiondays,
                    null,
                    (string)$deletedrecording->trashreason
                ),
            ];
        }
    }

    // Pagination data.
    $haspagination = $totalpages > 1;
    $hasprevious = $page > 0;
    $hasnext = $page < ($totalpages - 1);

    // Sort and page links are complete URLs: each carries rorder exactly once (never an appended duplicate), the
    // filters (rq, topic, rpending) and, for the page links, rpage. Sorting returns to the first page.
    $listurl = static function (string $linkorder, ?int $linkpage = null) use ($cm, $query, $topic, $pendingonly): string {
        $params = ['id' => $cm->id];
        if (trim((string)$query) !== '') {
            $params['rq'] = $query;
        }
        if (trim((string)$topic) !== '') {
            $params['topic'] = $topic;
        }
        if ($pendingonly) {
            $params['rpending'] = 1;
        }
        $params['rorder'] = $linkorder;
        if ($linkpage !== null) {
            $params['rpage'] = $linkpage;
        }
        return (new moodle_url('/mod/googlemeet/view.php', $params))->out(false);
    };

    // Build page numbers for pagination.
    $pages = [];
    for ($i = 0; $i < $totalpages; $i++) {
        $pages[] = [
            'number' => $i + 1,
            'page' => $i,
            'active' => ($i === $page),
            'currentorder' => $order,
            'coursemoduleid' => $cm->id,
            'url' => $listurl($order, $i),
        ];
    }

    // Calculate display range.
    $start = $totalrecordings > 0 ? $offset + 1 : 0;
    $end = min($offset + $maxrecordings, $totalrecordings);

    // Per-user recordings view preference (cards|list), with toggle URLs that
    // preserve the current content state (page/order/query/topic) and carry the
    // rview action param. view.php consumes rview, sets the preference and
    // redirects to a clean URL.
    $view = $recordingsview;
    $viewurlparams = ['id' => $cm->id, 'rorder' => $order];
    if ($page > 0) {
        $viewurlparams['rpage'] = $page;
    }
    if (trim((string)$query) !== '') {
        $viewurlparams['rq'] = $query;
    }
    if (trim((string)$topic) !== '') {
        $viewurlparams['topic'] = $topic;
    }
    if ($pendingonly) {
        $viewurlparams['rpending'] = 1;
    }
    $filterbaseparams = $viewurlparams;
    unset($filterbaseparams['rpage'], $filterbaseparams['rpending']);
    $viewcardsurl = (new moodle_url(
        '/mod/googlemeet/view.php',
        $viewurlparams + ['rview' => 'cards']
    ))->out(false);
    $viewlisturl = (new moodle_url(
        '/mod/googlemeet/view.php',
        $viewurlparams + ['rview' => 'list']
    ))->out(false);

    $hasactivefilters = trim((string)$query) !== '' || trim((string)$topic) !== '' || $pendingonly;
    $html .= \mod_googlemeet\local\ai_review::render_bulk_banner($googlemeet, $cm, $context);
    $html .= $OUTPUT->render_from_template('mod_googlemeet/recordingstable', [
        'recordings' => $recordings,
        'hasrecordings' => !empty($recordings),
        'coursemoduleid' => $cm->id,
        'hascapability' => $hascapability,
        'aienabled' => $aienabled,
        'cangenerateai' => $cangenerateai,
        'cansubscriberecordings' => $cansubscriberecordings,
        'issubscribed' => $issubscribed,
        'canpurge' => $canpurge,
        'deletedrecordings' => $deletedrecordings,
        'hasdeletedrecordings' => !empty($deletedrecordings),
        'deletedrecordingcount' => count($deletedrecordings),
        'sesskey' => sesskey(),
        // Pagination data.
        'haspagination' => $haspagination,
        'hasprevious' => $hasprevious,
        'hasnext' => $hasnext,
        'currentpage' => $page,
        'previouspage' => $page - 1,
        'nextpage' => $page + 1,
        'previouspageurl' => $listurl($order, max(0, $page - 1)),
        'nextpageurl' => $listurl($order, $page + 1),
        'sortdescurl' => $listurl('DESC'),
        'sortascurl' => $listurl('ASC'),
        'pages' => $pages,
        'totalrecordings' => $totalrecordings,
        'start' => $start,
        'end' => $end,
        'totalpages' => $totalpages,
        // Order data.
        'currentorder' => $order,
        'isorderdesc' => ($order === 'DESC'),
        'isorderasc' => ($order === 'ASC'),
        // Filter data.
        // Not pre-escaped: Mustache {{ }} HTML-escapes these on output, so s() here would double-encode.
        'recordingquery' => $query,
        'selectedtopic' => $topic,
        'alltopics' => $topicchips,
        'hasactivefilters' => $hasactivefilters,
        // Keep the filter bar while a search returns nothing, so the query can still be edited.
        'showfilterbar' => !empty($recordings) || $hasactivefilters,
        // Filters folded into the "Filters" panel on phones: topic and a non-default order.
        'activefiltercount' => (trim((string)$topic) !== '' ? 1 : 0)
            + ($order !== (strtoupper((string)($googlemeet->recordingsorder ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC') ? 1 : 0),
        'showprogresssummary' => $showprogresssummary,
        'ispendingfilter' => $pendingonly,
        'pendingparam' => $pendingonly ? 1 : 0,
        'pendingfilterurl' => (new moodle_url('/mod/googlemeet/view.php', $filterbaseparams + ['rpending' => 1]))->out(false),
        'allfilterurl' => (new moodle_url('/mod/googlemeet/view.php', $filterbaseparams))->out(false),
        'clearfiltersurl' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]))->out(false),
        // View toggle (cards|list).
        'isviewcards' => ($view === 'cards'),
        'isviewlist' => ($view === 'list'),
        'viewcardsurl' => $viewcardsurl,
        'viewlisturl' => $viewlisturl,
    ] + googlemeet_progress_summary_context($progresssummary));

    if ($hascapability) {
        $lastsync = get_string('never', 'googlemeet');
        if ($googlemeet->lastsync) {
            $lastsync = userdate($googlemeet->lastsync, get_string('timedate', 'googlemeet'));
        }

        $meetingcode = client::extract_meeting_code((string)($googlemeet->url ?? ''));
        $redordingname = $meetingcode ? '"' . $meetingcode . '" ' : '';
        if ($googlemeet->originalname) {
            $redordingname .= ($redordingname !== '' ? get_string('or', 'googlemeet') . ' ' : '') .
                '"' . $googlemeet->originalname . '"';
        }

        $loginhtml = '';
        $syncbutton = '';
        $islogged = false;
        $cansync = \mod_googlemeet\local\sync_manager::can_request($context);
        $isloggedcreatoremail = $client->get_email() === $googlemeet->creatoremail;
        if (!$client->check_login()) {
            $loginhtml = $client->print_login_popup();
        } else {
            $islogged = true;
            $loginhtml = $client->print_user_info('drive');

            if ($cansync) {
                $url = new moodle_url($PAGE->url);
                $url->param('sync', true);
                $syncbutton = new single_button($url, get_string('syncwithgoogledrive', 'googlemeet'), 'post', true);
                $syncbutton = $OUTPUT->render($syncbutton);
            }
        }

        $syncstatus = \mod_googlemeet\local\sync_manager::get_status($googlemeet);
        $html .= $OUTPUT->render_from_template('mod_googlemeet/syncbutton', [
            // Async sync panel state.
            'cmid' => $cm->id,
            'syncactive' => $syncstatus['active'],
            'syncstatusmessage' => $syncstatus['active'] ? $syncstatus['message'] : '',
            'lastsync' => $lastsync,
            'creatoremail' => $googlemeet->creatoremail,
            'redordingname' => $redordingname,
            'login' => $loginhtml,
            'islogged' => $islogged,
            'syncbutton' => $syncbutton,
            'cansync' => $cansync,
            'isloggedcreatoremail' => $isloggedcreatoremail,
        ]);
    }

    $html .= '</div>';

    echo $html;
}

/**
 * Transcript shown in the teacher-only hub tab.
 *
 * Prefers the transcript of a completed AI analysis and falls back to the recording's own (Meet or
 * subtitle) transcript when the analysis stored none.
 *
 * @param stdClass|false|null $analysis googlemeet_ai_analysis row.
 * @param stdClass $recording googlemeet_recordings row.
 * @return string Plain-text transcript ('' when none).
 */
function googlemeet_hub_transcript($analysis, $recording): string {
    $transcript = ($analysis && ($analysis->status ?? '') === 'completed') ? (string)($analysis->transcript ?? '') : '';
    if (trim($transcript) === '') {
        $transcript = (string)($recording->transcripttext ?? '');
    }
    return trim($transcript) === '' ? '' : $transcript;
}

/**
 * Returns a recording the current user may open, or false.
 *
 * Trashed recordings, recordings of another activity and hidden recordings (for
 * users without editrecording) are all reported identically as "not available".
 *
 * @param object $googlemeet Activity record.
 * @param context_module $context Activity context.
 * @param int $recordingid Recording id.
 * @return object|false
 */
function googlemeet_get_accessible_recording($googlemeet, $context, int $recordingid) {
    global $DB;

    $recording = $DB->get_record(
        'googlemeet_recordings',
        ['id' => $recordingid, 'googlemeetid' => $googlemeet->id, 'deleted' => 0]
    );
    if (!$recording) {
        return false;
    }
    if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
        return false;
    }
    return $recording;
}

/**
 * Print the per-recording hub.
 *
 * @param object $googlemeet Activity record.
 * @param object $cm Course-module record.
 * @param context_module $context Module context.
 * @param object $recording Recording record scoped by the caller.
 * @return void
 */
function googlemeet_print_recording_hub($googlemeet, $cm, $context, $recording) {
    global $CFG, $DB, $OUTPUT, $PAGE, $USER;

    $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
    $canmanagequestions = has_capability('mod/googlemeet:managequestions', $context);
    $aiconfig = get_config('googlemeet');
    $aienabled = !empty($aiconfig->enableai) && !empty($aiconfig->geminiapikey);
    $questionservice = new question_service();
    $questions = $questionservice->get_questions($googlemeet, $cm, $context, $recording->id, !$canmanagequestions);
    $draftcount = 0;
    $publishedcount = 0;
    foreach ($questions as $question) {
        if ($question['isready']) {
            $publishedcount++;
        } else if ($question['isdraft']) {
            $draftcount++;
        }
    }
    if ($canmanagequestions && $questions) {
        // Teacher view: how students did on each question in the practice.
        $practicestats = \mod_googlemeet\local\practice_attempts::get_question_stats(
            (int)$googlemeet->id,
            (int)$recording->id
        );
        foreach ($questions as &$question) {
            $stat = $practicestats[(int)$question['id']] ?? null;
            $question['haspracticestats'] = !empty($stat['attempts']);
            $question['practicestatslabel'] = $question['haspracticestats']
                ? get_string('question_practice_stats', 'googlemeet', (object)[
                    'pct' => $stat['correctpct'], 'attempts' => $stat['attempts'], 'users' => $stat['users']])
                : '';
            $question['practicestatslow'] = $question['haspracticestats'] && $stat['correctpct'] < 50;
        }
        unset($question);
    }

    $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id]);
    // An unreviewed analysis is hidden from students (teachers see it with a review banner).
    $aireview = \mod_googlemeet\local\ai_review::hub_context(
        $analysis,
        $context,
        (int)$cm->id,
        $aienabled && has_capability('mod/googlemeet:generateai', $context)
    );
    $analysiscompleted = $analysis && $analysis->status === 'completed' && $aireview['aicontentvisible'];
    $statusflags = recording_format::ai_status_flags($analysis->status ?? null);
    // Teacher-only tab: never render the transcript into a student's page.
    $hubtranscript = $caneditrecording ? googlemeet_hub_transcript($analysis, $recording) : '';
    $keypoints = [];
    $topics = [];
    $chapters = [];
    if ($analysiscompleted) {
        $keypoints = json_decode($analysis->keypoints) ?: [];
        $topics = json_decode($analysis->topics) ?: [];
        $chapters = recording_format::normalise_chapters($analysis->chapters ?? '');
    }

    $rpage = optional_param('rpage', 0, PARAM_INT);
    $rorder = optional_param('rorder', null, PARAM_ALPHA);
    $backparams = ['id' => $cm->id];
    if ($rpage > 0) {
        $backparams['rpage'] = $rpage;
    }
    if ($rorder) {
        $backparams['rorder'] = $rorder;
    }
    // Previous/next are chronological whatever the list order (students think in class dates).
    [$previousrecording, $nextrecording] = \mod_googlemeet\local\list_aggregates::hub_neighbours(
        $recording,
        !$caneditrecording
    );
    $previousparams = $backparams;
    $nextparams = $backparams;
    if ($previousrecording) {
        $previousparams['recording'] = $previousrecording->id;
    }
    if ($nextrecording) {
        $nextparams['recording'] = $nextrecording->id;
    }
    $activetab = optional_param('tab', '', PARAM_ALPHA);

    $materials = googlemeet_get_recording_materials($context, $recording->id);
    $hasmaterials = !empty($materials);
    $showmaterials = $hasmaterials || $caneditrecording;

    $materialsactive = $showmaterials && $activetab === 'materials';
    $summaryactive = !$materialsactive;
    $progress = $DB->get_record(
        'googlemeet_recording_progress',
        ['recordingid' => $recording->id, 'userid' => $USER->id],
        'watchedseconds,completed,markedviewed'
    );
    $progressstate = recording_progress::recording_progress_state($recording, $progress ?: null);
    $canembed = googlemeet_recording_can_embed((string)$recording->webviewlink);
    $embedurl = $canembed ? googlemeet_get_recording_embed_url((string)$recording->webviewlink) : '';
    $durationseconds = recording_format::recording_duration_to_seconds((string)$recording->duration);
    // Deep link: view.php?id=..&recording=..&t=<seconds> opens the Drive player at that second.
    $starttime = max(0, optional_param('t', 0, PARAM_INT));
    if ($durationseconds > 0) {
        $starttime = min($starttime, $durationseconds);
    }
    $playerurl = $canembed ? googlemeet_get_recording_seek_url((string)$recording->webviewlink, $starttime) : '';
    // Approximate "continue where you left off": the last chapter/timestamp this user jumped to,
    // not the real playback position (see recording_progress::lastjump_preference_name()).
    $lastjump = 0;
    if ($canembed && $starttime === 0 && isloggedin() && !isguestuser()) {
        $lastjump = max(0, (int)get_user_preferences(recording_progress::lastjump_preference_name((int)$recording->id), 0));
        if ($durationseconds > 0 && $lastjump > $durationseconds) {
            $lastjump = 0;
        }
    }

    $lessontitles = recordings::get_lesson_titles($googlemeet, $caneditrecording);
    $lessontitle = $lessontitles[$recording->id] ?? [
        'title' => recordings::display_name((string)$recording->name), 'subtitle' => '', 'hassubtitle' => false,
    ];
    // The page header already shows the activity name: do not repeat it as the lesson subtitle.
    if (
        !empty($lessontitle['hassubtitle'])
            && core_text::strtolower(trim(format_string($lessontitle['subtitle'])))
                === core_text::strtolower(trim(format_string($googlemeet->name)))
    ) {
        $lessontitle['subtitle'] = '';
        $lessontitle['hassubtitle'] = false;
    }
    $navtitle = static function (?stdClass $navrecording) use ($lessontitles): string {
        if (!$navrecording) {
            return '';
        }
        return format_string($lessontitles[$navrecording->id]['title'] ?? recordings::display_name((string)$navrecording->name));
    };
    $navdate = static function (?stdClass $navrecording): string {
        return $navrecording ? userdate((int)$navrecording->createdtime, get_string('strftimedmy', 'googlemeet')) : '';
    };

    $templatecontext = array_merge([
        'cmid' => $cm->id,
        'recordingid' => $recording->id,
        'name' => format_string($lessontitle['title']),
        'hassubtitle' => $lessontitle['hassubtitle'],
        'subtitle' => format_string($lessontitle['subtitle']),
        'datelabel' => userdate((int)$recording->createdtime, get_string('strftimedaydate', 'langconfig')),
        'datetimeiso' => date('c', (int)$recording->createdtime),
        'previousrecordingtitle' => $navtitle($previousrecording),
        'previousrecordingdate' => $navdate($previousrecording),
        'nextrecordingtitle' => $navtitle($nextrecording),
        'nextrecordingdate' => $navdate($nextrecording),
        'originalname' => $recording->name,
        'duration' => s($recording->duration),
        'durationseconds' => $durationseconds,
        'starttime' => $canembed ? $starttime : 0,
        'playerurl' => $playerurl,
        'hasresume' => $lastjump > 0,
        'resumeseconds' => $lastjump,
        'resumelabel' => $lastjump > 0 ? googlemeet_hub_resume_label($lastjump, $chapters) : '',
        'huburl' => (new moodle_url(
            '/mod/googlemeet/view.php',
            ['id' => $cm->id, 'recording' => $recording->id]
        ))->out(false),
        'webviewlink' => $recording->webviewlink,
        'canembed' => $canembed,
        'embedurl' => $embedurl,
        'backurl' => (new moodle_url('/mod/googlemeet/view.php', $backparams))->out(false),
        'hasrecordingnav' => !empty($previousrecording) || !empty($nextrecording),
        'haspreviousrecording' => !empty($previousrecording),
        'previousrecordingurl' => $previousrecording
            ? (new moodle_url('/mod/googlemeet/view.php', $previousparams))->out(false)
            : '',
        'hasnextrecording' => !empty($nextrecording),
        'nextrecordingurl' => $nextrecording
            ? (new moodle_url('/mod/googlemeet/view.php', $nextparams))->out(false)
            : '',
        'caneditrecording' => $caneditrecording,
        'canmanagequestions' => $canmanagequestions,
        'visibilitybuttonlabel' => get_string(
            !empty($recording->visible) ? 'recording_hide_from_students_button' : 'recording_show_to_students_button',
            'googlemeet'
        ),
        'aienabled' => $aienabled,
        'sesskey' => sesskey(),
        'hasanalysis' => $analysiscompleted,
        'aistatusisprocessing' => $statusflags['aistatusisprocessing'],
        'aistatusispending' => $statusflags['aistatusispending'],
        'aistatusisfailed' => $statusflags['aistatusisfailed'],
        'aierror' => ($caneditrecording && $analysis && !empty($analysis->error)) ? s($analysis->error) : '',
        'summary' => $analysiscompleted ? implode('', array_map(static function (string $paragraph) use ($context) {
            return '<p>' . format_text($paragraph, FORMAT_PLAIN, ['context' => $context, 'para' => false]) . '</p>';
        }, googlemeet_summary_paragraphs((string)$analysis->summary))) : '',
        'keypoints' => array_map(static function ($point, $index) {
            return ['text' => (string)$point, 'number' => $index + 1];
        }, array_values($keypoints), array_keys(array_values($keypoints))),
        'keypointcount' => count($keypoints),
        'keypointshash' => recording_progress::keypoints_hash($keypoints),
        'keypointsstate' => (isloggedin() && !isguestuser())
            ? recording_progress::keypoints_state(
                (string)get_user_preferences(recording_progress::keypoints_preference_name((int)$recording->id), ''),
                recording_progress::keypoints_hash($keypoints),
                count($keypoints)
            )
            : '',
        'topics' => array_map(static function ($topic) use ($cm) {
            return [
                'text' => (string)$topic,
                'url' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'topic' => (string)$topic]))->out(false),
            ];
        }, $topics),
        'chapters' => $chapters,
        'haschapters' => !empty($chapters),
        'chaptercount' => count($chapters),
        'transcript' => $hubtranscript !== '' ? format_text($hubtranscript, FORMAT_PLAIN, ['context' => $context]) : '',
        'hastranscript' => $hubtranscript !== '',
        'hasnotes' => !empty($recording->notestext),
        'notes' => !empty($recording->notestext)
            ? format_text($recording->notestext, FORMAT_HTML, ['context' => $context])
            : '',
        'questions' => $questions,
        'hasquestions' => !empty($questions),
        // Students only get the Questions tab when there is something to practise (published questions).
        'showquestionstab' => $canmanagequestions || !empty($questions),
        'draftcount' => $draftcount,
        'publishedcount' => $publishedcount,
        'questioncount' => count($questions),
        'hasdrafts' => $draftcount > 0,
        'activitydraftcount' => $canmanagequestions
            ? $questionservice->count_activity_drafts($googlemeet, $cm, $context) : 0,
        'generationqueued' => $questionservice->is_generation_queued($recording->id),
        // Generating questions is a paid Gemini call: the button follows the web service's gate.
        'cangeneratequestions' => $aienabled && $canmanagequestions
            && has_capability('mod/googlemeet:generateai', $context),
        'summaryactive' => $summaryactive,
        // The Questions tab is never initially active (Summary is, or Materials with tab=materials).
        'questionsactive' => false,
        'showmaterials' => $showmaterials,
        'materialsactive' => $materialsactive,
        'materials' => $materials,
        'hasmaterials' => $hasmaterials,
        'materialcount' => count($materials),
        'managematerialsurl' => (new moodle_url(
            '/mod/googlemeet/material.php',
            ['id' => $cm->id, 'recording' => $recording->id]
        ))->out(false),
    ], $progressstate, $aireview);

    echo $OUTPUT->render_from_template('mod_googlemeet/recording_hub', $templatecontext);
}

/**
 * Student-facing label for the hub "continue" button.
 *
 * "Continue with chapter «X» (14:02)" when the remembered second is a chapter start, else "Continue at 14:02".
 *
 * @param int $seconds Remembered offset (last jump), > 0.
 * @param array $chapters Normalised chapters (recording_format::normalise_chapters()).
 * @return string
 */
function googlemeet_hub_resume_label(int $seconds, array $chapters): string {
    $time = recording_format::format_seconds_timestamp($seconds);
    foreach ($chapters as $chapter) {
        if (abs((int)$chapter['startseconds'] - $seconds) <= 2) {
            return get_string('hub_resume_chapter', 'googlemeet', (object)[
                'title' => format_string($chapter['title']),
                'time' => $time,
            ]);
        }
    }
    return get_string('hub_resume_time', 'googlemeet', $time);
}

/**
 * Split an AI summary into paragraphs (blank-line separated), dropping empty ones.
 *
 * Single line breaks inside a paragraph are kept (format_text turns them into <br>),
 * so list-like summaries still read correctly.
 *
 * @param string $summary Plain-text summary.
 * @return string[] Paragraphs, trimmed.
 */
function googlemeet_summary_paragraphs(string $summary): array {
    $summary = str_replace(["\r\n", "\r"], "\n", $summary);
    $paragraphs = preg_split('/\n\s*\n/', $summary) ?: [];
    return array_values(array_filter(array_map('trim', $paragraphs), static function (string $paragraph) {
        return $paragraph !== '';
    }));
}

/**
 * Get downloadable material files for a recording.
 *
 * @param context_module $context Module context.
 * @param int $recordingid Recording ID.
 * @return array
 */
function googlemeet_get_recording_materials(context_module $context, int $recordingid): array {
    return \mod_googlemeet\local\list_aggregates::materials_by_recording($context, [$recordingid])[$recordingid];
}

/**
 * Save the teacher attachment files submitted through the mod form draft area.
 *
 * Files are stored in the module context under the 'attachment' file area (itemid 0).
 *
 * @param object $googlemeet Instance object from the form (needs ->coursemodule and ->attachments).
 * @return void
 */
function googlemeet_save_attachments($googlemeet): void {
    if (empty($googlemeet->coursemodule) || !isset($googlemeet->attachments)) {
        return;
    }
    $context = context_module::instance($googlemeet->coursemodule);
    file_save_draft_area_files(
        $googlemeet->attachments,
        $context->id,
        'mod_googlemeet',
        'attachment',
        0,
        ['subdirs' => 0]
    );
}

/**
 * Print the teacher-provided attachment files as a download list for students.
 *
 * Does nothing if the activity has no attachments.
 *
 * @param context_module $context Module context.
 * @return void
 */
function googlemeet_print_attachments(context_module $context): void {
    global $OUTPUT;

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_googlemeet', 'attachment', 0, 'filename', false);
    if (!$files) {
        return;
    }

    $items = [];
    foreach ($files as $file) {
        if ($file->is_directory()) {
            continue;
        }
        $filename = $file->get_filename();
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_googlemeet',
            'attachment',
            0,
            $file->get_filepath(),
            $filename,
            true
        );
        $items[] = [
            'url' => $url->out(false),
            'icon' => $OUTPUT->pix_icon(file_file_icon($file), '', 'moodle', ['class' => 'me-2']),
            'filename' => $filename,
            'size' => display_size($file->get_filesize()),
        ];
    }

    echo $OUTPUT->render_from_template('mod_googlemeet/attachments', ['files' => $items]);
}

/**
 * Whether a Google Drive URL can be embedded with the preview player.
 *
 * @param string $webviewlink Drive web view URL.
 * @return bool
 */
function googlemeet_recording_can_embed(string $webviewlink): bool {
    return preg_match('~/file/d/([^/]+)~', $webviewlink) === 1;
}

/**
 * Convert a Google Drive view URL to an embeddable preview URL when possible.
 *
 * @param string $webviewlink Drive web view URL.
 * @return string
 */
function googlemeet_get_recording_embed_url(string $webviewlink): string {
    if (preg_match('~/file/d/([^/?#]+)~', $webviewlink, $matches)) {
        return 'https://drive.google.com/file/d/' . rawurlencode($matches[1]) . '/preview';
    }
    return $webviewlink;
}

/**
 * Build the Drive preview URL that starts playback at a given second.
 *
 * Google Drive's /preview player honours a `t` query parameter (seconds), so seeking is done by
 * reloading the iframe with `?t=<seconds>`. Returns the plain embed URL when no offset is needed,
 * and the original link untouched when it is not an embeddable Drive file.
 *
 * @param string $webviewlink Drive web view URL.
 * @param int $seconds Start offset in seconds (negative values are clamped to 0).
 * @return string
 */
function googlemeet_get_recording_seek_url(string $webviewlink, int $seconds): string {
    if (!googlemeet_recording_can_embed($webviewlink)) {
        return $webviewlink;
    }
    $url = googlemeet_get_recording_embed_url($webviewlink);
    $seconds = max(0, $seconds);
    return $seconds > 0 ? $url . '?t=' . $seconds : $url;
}

/**
 * Extract the Google Meet room from a URL as https://meet.google.com/xxx-xxxx-xxx.
 *
 * @param string $url
 * @return string|null The normalised URL, or null when the text holds no Meet room code.
 */
function googlemeet_clear_url($url) {
    // Literal dots and a URL rebuilt from the code alone: the host of the result is always meet.google.com.
    if (preg_match('~meet\.google\.com/([a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3})~i', (string)$url, $matches)) {
        return 'https://meet.google.com/' . $matches[1];
    }

    return null;
}
