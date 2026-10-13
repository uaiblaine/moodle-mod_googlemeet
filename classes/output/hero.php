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

namespace mod_googlemeet\output;

use context_module;
use mod_googlemeet\local\recording_format;
use mod_googlemeet\local\recording_progress;
use mod_googlemeet\local\recordings;
use moodle_url;
use stdClass;

/**
 * Classroom hero of the activity view (mod_googlemeet/hero): next session and schedule, the Meet
 * room button, the "continue watching" card and the current user's progress summary, which the
 * recordings list and the course index show too.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hero {
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
    public static function get_classroom_hero_context(
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

        $progresssummary = self::get_progress_summary($googlemeet, $context);
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
            'calendarurl' => (new moodle_url(
                '/calendar/view.php',
                ['view' => 'upcoming', 'course' => (int)$cm->course]
            ))->out(false),
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
            self::get_room_cta_context($googlemeet, $context, $nextevent, $hasvalidmeeturl),
            self::get_continue_watching_context($googlemeet, $cm, $context),
            self::progress_summary_context($progresssummary)
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
    public static function get_room_cta_context(
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
    public static function get_continue_watching_context($googlemeet, $cm, context_module $context): array {
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
    public static function get_progress_summary(stdClass $googlemeet, context_module $context): array {
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
     * @param array $summary Result of self::get_progress_summary().
     * @return array
     */
    public static function progress_summary_context(array $summary): array {
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
}
