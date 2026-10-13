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

namespace mod_googlemeet\local;

use core_date;
use DateTime;
use mod_googlemeet\helper;
use stdClass;

/**
 * The session schedule of an activity: holiday periods and cancelled dates, the session day, the
 * googlemeet_events rows built from the recurrence settings and their core calendar mirrors, the
 * upcoming-session list and the auto-sync retry reset.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule {
    /**
     * Save holiday/exclusion periods for a googlemeet instance.
     *
     * @param object $googlemeet The googlemeet instance data from the form.
     * @return void
     */
    public static function save_holidays($googlemeet) {
        global $DB;

        // Delete all existing holiday periods for this instance.
        $DB->delete_records('googlemeet_holidays', ['googlemeetid' => $googlemeet->id]);

        // Only save holidays if recurrence is enabled.
        if (empty($googlemeet->addmultiply) || empty($googlemeet->holiday_repeats)) {
            return;
        }

        // Save new holiday periods.
        for ($i = 0; $i < $googlemeet->holiday_repeats; $i++) {
            $startkeyarr = "holidaystartdate";
            $endkeyarr = "holidayenddate";
            $namekeyarr = "holidayname";

            // Get data from the repeat elements (they come as arrays).
            $startdate = $googlemeet->{$startkeyarr}[$i] ?? null;
            $enddate = $googlemeet->{$endkeyarr}[$i] ?? null;
            $name = $googlemeet->{$namekeyarr}[$i] ?? '';

            // Only save if both dates are set and valid.
            if ($startdate && $enddate && $enddate >= $startdate) {
                $holiday = new stdClass();
                $holiday->googlemeetid = $googlemeet->id;
                $holiday->name = $name;
                $holiday->startdate = $startdate;
                $holiday->enddate = $enddate;
                $holiday->timemodified = time();

                $DB->insert_record('googlemeet_holidays', $holiday);
            }
        }
    }

    /**
     * Get holiday/exclusion periods for a googlemeet instance.
     *
     * @param int $googlemeetid The googlemeet instance ID.
     * @return array Array of holiday period objects.
     */
    public static function get_holidays($googlemeetid) {
        global $DB;
        return $DB->get_records('googlemeet_holidays', ['googlemeetid' => $googlemeetid], 'startdate ASC');
    }

    /**
     * Calendar day of a session timestamp, in the site's default timezone.
     *
     * Every session-day decision (cancelled dates, holidays, the "today" flag) goes through this, so
     * a viewer in another timezone and cron, which runs as a user in the site timezone, agree on the
     * day of a session near midnight. The viewer's own timezone is only for displaying times.
     *
     * @param int $timestamp Unix timestamp.
     * @return string Day as Y-m-d; such keys compare in calendar order.
     */
    public static function session_day(int $timestamp): string {
        $date = new DateTime('@' . $timestamp);
        $date->setTimezone(core_date::get_server_timezone_object());
        return $date->format('Y-m-d');
    }

    /**
     * Check if a given date falls within any holiday period.
     *
     * Days are site days ({@see self::session_day()}).
     *
     * @param int $timestamp The timestamp to check.
     * @param array $holidays Array of holiday period objects.
     * @return bool True if the date is within a holiday period.
     */
    public static function is_holiday($timestamp, $holidays) {
        $datestart = self::session_day((int)$timestamp);

        foreach ($holidays as $holiday) {
            $holidaystart = self::session_day((int)$holiday->startdate);
            $holidayend = self::session_day((int)$holiday->enddate);

            if ($datestart >= $holidaystart && $datestart <= $holidayend) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save cancelled dates for a googlemeet instance.
     *
     * @param object $googlemeet The googlemeet instance data from the form.
     * @return void
     */
    public static function save_cancelled($googlemeet) {
        global $DB;

        // Delete all existing cancelled dates for this instance.
        $DB->delete_records('googlemeet_cancelled', ['googlemeetid' => $googlemeet->id]);

        if (empty($googlemeet->cancelled_repeats)) {
            return;
        }

        $cancelledbydate = [];
        for ($i = 0; $i < $googlemeet->cancelled_repeats; $i++) {
            $cancelleddate = $googlemeet->cancelleddate[$i] ?? null;
            $reason = trim((string)($googlemeet->cancelledreason[$i] ?? ''));

            if (empty($cancelleddate)) {
                continue;
            }

            $datekey = self::session_day((int)$cancelleddate);
            if (isset($cancelledbydate[$datekey]) && $cancelledbydate[$datekey]->reason !== '' && $reason === '') {
                continue;
            }

            $cancelled = new stdClass();
            $cancelled->googlemeetid = $googlemeet->id;
            $cancelled->cancelleddate = (int)$cancelleddate;
            $cancelled->reason = $reason;
            $cancelled->timemodified = time();
            $cancelledbydate[$datekey] = $cancelled;
        }

        ksort($cancelledbydate);
        foreach ($cancelledbydate as $cancelled) {
            $DB->insert_record('googlemeet_cancelled', $cancelled);
        }
    }

    /**
     * Get cancelled dates for a googlemeet instance.
     *
     * @param int $googlemeetid The googlemeet instance ID.
     * @return array Array of cancelled date objects.
     */
    public static function get_cancelled($googlemeetid) {
        global $DB;
        return $DB->get_records('googlemeet_cancelled', ['googlemeetid' => $googlemeetid], 'cancelleddate ASC');
    }

    /**
     * Check if a given date is in the cancelled list.
     *
     * Days are site days ({@see self::session_day()}).
     *
     * @param int $timestamp The timestamp to check.
     * @param array $cancelleddates Array of cancelled date objects.
     * @return object|false The cancelled date object if found, false otherwise.
     */
    public static function is_cancelled($timestamp, $cancelleddates) {
        $datestart = self::session_day((int)$timestamp);

        foreach ($cancelleddates as $cancelled) {
            $cancelledstart = self::session_day((int)$cancelled->cancelleddate);

            if ($datestart === $cancelledstart) {
                return $cancelled;
            }
        }

        return false;
    }

    /**
     * Build the unsaved googlemeet_events rows from the date, time and recurrence settings.
     *
     * Holiday periods are read from the database, so self::save_holidays() must run first.
     * Cancelled dates still produce rows; self::reconcile_cancelled_calendar_events() hides them
     * from the core calendar. The form validation mirrors this loop in
     * {@see \mod_googlemeet_mod_form::has_events_with_period()}; keep them in step.
     *
     * @param stdClass $googlemeet Activity data from the form (id, eventdate, start/end hour and minute,
     *     addmultiply, days, period, eventenddate).
     * @return array list of events
     */
    public static function construct_events_data_for_add($googlemeet) {
        global $CFG;

        $eventstarttime = $googlemeet->starthour * HOURSECS + $googlemeet->startminute * MINSECS;
        $eventendtime = $googlemeet->endhour * HOURSECS + $googlemeet->endminute * MINSECS;
        $eventdate = $googlemeet->eventdate + $eventstarttime;
        $duration = $eventendtime - $eventstarttime;

        // Get holiday/exclusion periods for this instance.
        $holidays = self::get_holidays($googlemeet->id);

        $events = [];

        // Add the first event only if it's not during a holiday period.
        if (!self::is_holiday($eventdate, $holidays)) {
            $event = new stdClass();
            $event->googlemeetid = $googlemeet->id;
            $event->eventdate = $eventdate;
            $event->duration = $duration;
            $event->timemodified = time();
            $events[] = $event;
        }

        if (isset($googlemeet->addmultiply)) {
            $startdate = $eventdate + DAYSECS;
            $enddate = $googlemeet->eventenddate + $eventendtime;

            // Getting first day of week.
            $sdate = $startdate;
            $dayinfo = usergetdate($sdate);
            if ($CFG->calendar_startwday === '0') { // Week start from sunday.
                $startweek = $sdate - $dayinfo['wday'] * DAYSECS;
            } else {
                $wday = $dayinfo['wday'] === 0 ? 7 : $dayinfo['wday'];
                $startweek = $sdate - ($wday - 1) * DAYSECS;
            }

            $wdaydesc = [0 => 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

            while ($sdate < $enddate) {
                if ($sdate < $startweek + WEEKSECS) {
                    $dayinfo = usergetdate($sdate);
                    if (isset($googlemeet->days) && property_exists((object)$googlemeet->days, $wdaydesc[$dayinfo['wday']])) {
                        $eventtime = make_timestamp(
                            $dayinfo['year'],
                            $dayinfo['mon'],
                            $dayinfo['mday'],
                            $googlemeet->starthour,
                            $googlemeet->startminute
                        );

                        // Only add event if it's not during a holiday period.
                        if (!self::is_holiday($eventtime, $holidays)) {
                            $event = new stdClass();
                            $event->googlemeetid = $googlemeet->id;
                            $event->eventdate = $eventtime;
                            $event->duration = $duration;
                            $event->timemodified = time();

                            $events[] = $event;
                        }
                    }
                    $sdate += DAYSECS;
                } else {
                    $startweek += WEEKSECS * $googlemeet->period;
                    $sdate = $startweek;
                }
            }
        }

        return $events;
    }

    /**
     * Delete every session of an instance with its notify_done rows and core calendar events.
     *
     * @param int $googlemeetid
     * @return void
     */
    public static function delete_events($googlemeetid) {
        global $DB;

        $eventids = $DB->get_fieldset_select('googlemeet_events', 'id', 'googlemeetid = ?', [$googlemeetid]);

        if (!empty($eventids)) {
            [$insql, $params] = $DB->get_in_or_equal($eventids);
            $DB->delete_records_select('googlemeet_notify_done', "eventid $insql", $params);
        }

        $DB->delete_records('googlemeet_events', ['googlemeetid' => $googlemeetid]);

        // Delete Calendar Events.
        $DB->delete_records('event', [
            'modulename' => 'googlemeet',
            'instance' => $googlemeetid,
            'eventtype' => helper::GOOGLEMEET_EVENT_START,
        ]);
    }

    /**
     * Replace every session of an instance with $events (wipe and recreate), with calendar mirrors.
     *
     * Used on add; updates go through self::merge_events() to keep existing rows.
     *
     * @param stdClass $googlemeet moodleform
     * @param array $events list of events
     * @return void
     */
    public static function set_events($googlemeet, $events) {
        global $DB;

        self::delete_events($googlemeet->id);

        if (empty($events)) {
            return;
        }

        foreach ($events as $event) {
            $event->id = $DB->insert_record('googlemeet_events', $event);
            helper::create_calendar_event($googlemeet, $event);
        }

        self::reconcile_cancelled_calendar_events($googlemeet);
    }

    /**
     * Reconcile core calendar mirrors with the instance cancelled-date list.
     *
     * Deletes the mirror of each cancelled session and recreates a missing one for a session that is
     * not cancelled. Sessions are identified by googlemeet_events.eventdate; cancellation uses the same
     * day-level self::is_cancelled() as self::get_upcoming_events(). Core calendar mirrors are
     * matched by instance + eventtype + timestart.
     *
     * @param stdClass $googlemeet Activity record.
     * @return void
     */
    public static function reconcile_cancelled_calendar_events(stdClass $googlemeet): void {
        global $DB;

        $googlemeetid = (int)$googlemeet->id;
        $events = $DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $googlemeetid],
            'eventdate ASC',
            'id,eventdate,duration'
        );
        if (empty($events)) {
            return;
        }

        $cancelleddates = self::get_cancelled($googlemeetid);
        foreach ($events as $event) {
            $match = [
                'modulename' => 'googlemeet',
                'instance' => $googlemeetid,
                'eventtype' => helper::GOOGLEMEET_EVENT_START,
                'timestart' => $event->eventdate,
            ];

            if (self::is_cancelled((int)$event->eventdate, $cancelleddates) !== false) {
                $DB->delete_records('event', $match);
                continue;
            }

            if (!$DB->record_exists('event', $match)) {
                helper::create_calendar_event($googlemeet, $event);
            }
        }
    }

    /**
     * Incrementally merge the regenerated events with the existing ones.
     *
     * Unlike self::set_events() (used on add), which wipes and recreates every event row, this
     * function preserves rows whose eventdate still matches one of the regenerated events. Preserving
     * the row keeps its id intact, which in turn preserves:
     *   - the `autosynced` timestamp (so an already auto-synced session is not re-processed), and
     *   - the related googlemeet_notify_done rows (so already-sent notifications are not re-sent).
     *
     * Only new dates are inserted, and only dates that are no longer scheduled are deleted, except past
     * sessions with attendance read from Google Meet, which are kept. Events are matched by eventdate
     * (the start timestamp), which uniquely identifies a session for an instance. When a persisting
     * event's duration changes, the row and its calendar mirror are updated in place without touching
     * its id, autosynced or notify_done.
     *
     * @param stdClass $googlemeet moodleform
     * @param array $events list of regenerated events (output of self::construct_events_data_for_add)
     * @return void
     */
    public static function merge_events($googlemeet, $events) {
        global $DB;

        $googlemeetid = $googlemeet->id;

        // Existing events keyed by eventdate.
        $existingevents = $DB->get_records('googlemeet_events', ['googlemeetid' => $googlemeetid]);
        $existingbydate = [];
        foreach ($existingevents as $existing) {
            // In the unlikely case of duplicate dates, keep the first; extras are treated as stale.
            if (!isset($existingbydate[$existing->eventdate])) {
                $existingbydate[$existing->eventdate] = $existing;
            }
        }

        // Track which existing event ids are still wanted, so the rest can be removed.
        $keptids = [];

        foreach ($events as $event) {
            if (isset($existingbydate[$event->eventdate])) {
                // Same date already exists: preserve the row (id, autosynced, notify_done).
                $existing = $existingbydate[$event->eventdate];
                $keptids[$existing->id] = true;

                // Update duration in place only if it actually changed; keep autosynced untouched.
                if ((int) $existing->duration !== (int) $event->duration) {
                    $update = new stdClass();
                    $update->id = $existing->id;
                    $update->duration = $event->duration;
                    $update->timemodified = time();
                    $DB->update_record('googlemeet_events', $update);

                    // Refresh the calendar mirror for this date so its duration stays in sync.
                    $DB->delete_records('event', [
                        'modulename' => 'googlemeet',
                        'instance' => $googlemeetid,
                        'eventtype' => helper::GOOGLEMEET_EVENT_START,
                        'timestart' => $event->eventdate,
                    ]);
                    $calendarevent = clone $event;
                    $calendarevent->id = $existing->id;
                    helper::create_calendar_event($googlemeet, $calendarevent);
                }
            } else {
                // Brand new date: insert event + calendar mirror.
                $event->id = $DB->insert_record('googlemeet_events', $event);
                helper::create_calendar_event($googlemeet, $event);
            }
        }

        // A past session with attendance read from Google Meet is history, not schedule. Changing the
        // start time or the recurrence must not orphan that attendance (Google keeps it about 30 days
        // only, so it cannot be fetched again later), so such sessions are kept as they are.
        $now = time();
        $withattendance = [];
        $pastids = [];
        foreach ($existingevents as $existing) {
            if (empty($keptids[$existing->id]) && (int)$existing->eventdate < $now) {
                $pastids[] = (int)$existing->id;
            }
        }
        if ($pastids) {
            [$pinsql, $pparams] = $DB->get_in_or_equal($pastids);
            foreach ($DB->get_fieldset_select('googlemeet_attendance', 'DISTINCT eventid', "eventid $pinsql", $pparams) as $eid) {
                $withattendance[(int)$eid] = true;
            }
            foreach (
                $DB->get_fieldset_select(
                    'googlemeet_attendance_sync',
                    'eventid',
                    "eventid $pinsql AND participants > 0",
                    $pparams
                ) as $eid
            ) {
                $withattendance[(int)$eid] = true;
            }
        }
        foreach (array_keys($withattendance) as $eid) {
            $keptids[$eid] = true;
        }

        // Delete existing events whose date is no longer scheduled, along with their dependents.
        $deleteids = [];
        foreach ($existingevents as $existing) {
            if (empty($keptids[$existing->id])) {
                $deleteids[] = $existing->id;
            }
        }

        if (!empty($deleteids)) {
            [$insql, $params] = $DB->get_in_or_equal($deleteids);

            // Remove notify_done rows for removed events.
            $DB->delete_records_select('googlemeet_notify_done', "eventid $insql", $params);
            // And the attendance fetch state of removed sessions (no attendance rows by construction).
            $DB->delete_records_select('googlemeet_attendance_sync', "eventid $insql", $params);

            // Remove the calendar mirrors for the removed dates.
            foreach ($existingevents as $existing) {
                if (empty($keptids[$existing->id])) {
                    $DB->delete_records('event', [
                        'modulename' => 'googlemeet',
                        'instance' => $googlemeetid,
                        'eventtype' => helper::GOOGLEMEET_EVENT_START,
                        'timestart' => $existing->eventdate,
                    ]);
                }
            }

            // Remove the event rows themselves.
            $DB->delete_records_select('googlemeet_events', "id $insql", $params);
        }

        self::reconcile_cancelled_calendar_events($googlemeet);
    }

    /**
     * Move the sessions, holiday periods, cancelled dates and calendar mirrors of activities by a course reset's shift.
     *
     * The plugin-table half of moving a course start date; the googlemeet columns themselves are moved by
     * shift_course_mod_dates() in googlemeet_reset_userdata(). The fields are the schedule dates the
     * restore step offsets ({@see \restore_googlemeet_activity_structure_step}): the two lists must stay
     * in step. Restore also offsets bookkeeping stamps (timecreated, timemodified) and the attendance join
     * and leave times; reset leaves those, as core modules do: they record when something happened.
     *
     * Core moves no activity calendar event on reset, so the mirrors are moved here. As in restore, a
     * session moved into the future has not been synced yet: its auto-sync state is cleared, and an
     * attendance fetch state with no attendance stored is dropped so the new session gets read (a state
     * that still holds attendance is kept, or a fetch would replace that attendance).
     *
     * @param int[] $googlemeetids Activity ids.
     * @param int $timeshift Seconds to add (negative moves back).
     * @param int $now Current time.
     * @return void
     */
    public static function shift_dates(array $googlemeetids, int $timeshift, int $now): void {
        global $DB;

        $googlemeetids = array_map('intval', $googlemeetids);
        if (!$googlemeetids || !$timeshift) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($googlemeetids, SQL_PARAMS_NAMED);
        $columns = [
            'googlemeet_events' => ['eventdate'],
            'googlemeet_holidays' => ['startdate', 'enddate'],
            'googlemeet_cancelled' => ['cancelleddate'],
        ];
        foreach ($columns as $table => $fields) {
            foreach ($fields as $field) {
                $DB->execute(
                    "UPDATE {{$table}} SET $field = $field + :shift WHERE googlemeetid $insql AND $field <> 0",
                    ['shift' => $timeshift] + $params
                );
            }
        }
        $DB->execute(
            "UPDATE {event}
                SET timestart = timestart + :shift, timesort = timesort + :shiftsort
              WHERE modulename = :modulename AND eventtype = :eventtype AND instance $insql",
            ['shift' => $timeshift, 'shiftsort' => $timeshift, 'modulename' => 'googlemeet',
                'eventtype' => helper::GOOGLEMEET_EVENT_START] + $params
        );

        $future = $DB->get_fieldset_select(
            'googlemeet_events',
            'id',
            "googlemeetid $insql AND eventdate + duration > :now",
            ['now' => $now] + $params
        );
        foreach (array_chunk(array_map('intval', $future), 1000) as $chunk) {
            [$esql, $eparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'ev');
            $DB->execute("UPDATE {googlemeet_events}
                             SET autosynced = 0, syncattempts = 0, nextsyncattempt = 0
                           WHERE id $esql", $eparams);
            $read = $DB->get_fieldset_select('googlemeet_attendance', 'DISTINCT eventid', "eventid $esql", $eparams);
            $unread = array_diff($chunk, array_map('intval', $read));
            if ($unread) {
                [$usql, $uparams] = $DB->get_in_or_equal($unread, SQL_PARAMS_NAMED, 'un');
                $DB->delete_records_select('googlemeet_attendance_sync', "eventid $usql", $uparams);
            }
        }
    }

    /**
     * Let auto-sync retry exhausted events after a teacher manually syncs the activity.
     *
     * @param int $googlemeetid Activity instance id.
     * @return int Number of events reset.
     */
    public static function reset_exhausted_autosync_events(int $googlemeetid): int {
        global $DB;

        $max = (int)get_config('googlemeet', 'maxsyncattempts');
        if ($max < 1) {
            $max = 1;
        }

        // The process_autosync::close_event() method stamps autosynced on both success and give-up; a
        // give-up leaves syncattempts >= max (a success on the last attempt does too, and reopening it
        // is harmless: the sync is idempotent and the event closes again on the next run). The due-events
        // query only selects autosynced = 0 and syncattempts < max, hence both are reset.
        $eventids = $DB->get_fieldset_select(
            'googlemeet_events',
            'id',
            'googlemeetid = :googlemeetid AND autosynced <> 0 AND syncattempts >= :maxattempts',
            ['googlemeetid' => $googlemeetid, 'maxattempts' => $max]
        );

        if (empty($eventids)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($eventids, SQL_PARAMS_NAMED);
        $DB->execute("UPDATE {googlemeet_events}
                         SET autosynced = 0,
                             syncattempts = 0,
                             nextsyncattempt = 0
                       WHERE id $insql", $inparams);

        return count($eventids);
    }

    /**
     * Upcoming and in-progress sessions of an activity, with their display status.
     *
     * Each session gets a status: cancelled, live (started, not ended), soon (starts within 30 minutes)
     * or scheduled.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $maxevents Maximum number of events to return (default 3), clamped to 1-10; ignored when $nolimit is set.
     * @param bool $nolimit Whether to skip the display limit.
     * @return array hasupcomingevents and upcomingevents (stdClass[]); with events, also starttime, endtime,
     *     duration and hasliveevent of the first one.
     */
    public static function get_upcoming_events($googlemeetid, $maxevents = 3, bool $nolimit = false) {
        global $DB, $USER;

        $now = time();

        // Get cancelled dates for this instance.
        $cancelleddates = self::get_cancelled($googlemeetid);

        // Ensure maxevents is within bounds unless the full schedule explicitly requests all rows.
        $maxevents = max(1, min(10, (int)$maxevents));

        // Get events that are upcoming or currently in progress (started less than duration ago).
        $sql = "SELECT id, eventdate, duration
                  FROM {googlemeet_events}
                 WHERE googlemeetid = :googlemeetid
                   AND (eventdate + duration) > :now
              ORDER BY eventdate ASC";

        $events = $DB->get_records_sql($sql, ['googlemeetid' => $googlemeetid, 'now' => $now], 0, $nolimit ? 0 : $maxevents);
        $upcomingevents = [];

        if ($events) {
            foreach ($events as $event) {
                $start = $event->eventdate;
                $end = $event->eventdate + $event->duration;
                $duration = $event->duration;

                $upcomingevent = new stdClass();
                $upcomingevent->today = (self::session_day($now) === self::session_day((int)$start));
                $upcomingevent->startdate = userdate($start, get_string('strftimedmy', 'googlemeet'), $USER->timezone);
                $upcomingevent->compactdate = recording_format::format_date_chip(
                    userdate($start, get_string('strftimedmweekday', 'googlemeet'), $USER->timezone)
                );
                $upcomingevent->starttime = userdate($start, get_string('strftimehm', 'googlemeet'), $USER->timezone);
                $upcomingevent->endtime = userdate($end, get_string('strftimehm', 'googlemeet'), $USER->timezone);
                $upcomingevent->timestamp = $start;
                $upcomingevent->duration = $duration;
                $upcomingevent->durationformatted = self::format_time_diff($duration);

                // Check if this event is cancelled.
                $cancelled = self::is_cancelled($start, $cancelleddates);
                if ($cancelled !== false) {
                    $upcomingevent->status = 'cancelled';
                    $upcomingevent->islive = false;
                    $upcomingevent->issoon = false;
                    $upcomingevent->isscheduled = false;
                    $upcomingevent->iscancelled = true;
                    $upcomingevent->cancelledreason = $cancelled->reason ?? '';
                    $upcomingevent->timeinfo = '';
                } else {
                    $upcomingevent->iscancelled = false;
                    $upcomingevent->cancelledreason = '';

                    // Calculate status.
                    $timediff = $start - $now;

                    if ($now >= $start && $now < $end) {
                        // Event is currently in progress.
                        $upcomingevent->status = 'live';
                        $upcomingevent->islive = true;
                        $upcomingevent->issoon = false;
                        $upcomingevent->isscheduled = false;
                        $elapsed = $now - $start;
                        $upcomingevent->timeinfo = get_string('event_started_ago', 'googlemeet', self::format_time_diff($elapsed));
                    } else if ($timediff > 0 && $timediff <= 1800) {
                        // Event starts within 30 minutes.
                        $upcomingevent->status = 'soon';
                        $upcomingevent->islive = false;
                        $upcomingevent->issoon = true;
                        $upcomingevent->isscheduled = false;
                        $upcomingevent->countdownprefix = get_string('event_countdown_starts_in_prefix', 'googlemeet');
                        $upcomingevent->countdownexpired = get_string('event_countdown_started', 'googlemeet');
                        $upcomingevent->timeinfo = get_string('event_starts_in', 'googlemeet', self::format_time_diff($timediff));
                    } else {
                        // Event is scheduled for later.
                        $upcomingevent->status = 'scheduled';
                        $upcomingevent->islive = false;
                        $upcomingevent->issoon = false;
                        $upcomingevent->isscheduled = true;
                        $upcomingevent->countdownprefix = get_string('event_countdown_starts_in_prefix', 'googlemeet');
                        $upcomingevent->countdownexpired = get_string('event_countdown_started', 'googlemeet');
                        $upcomingevent->timeinfo = get_string('event_starts_in', 'googlemeet', self::format_time_diff($timediff));
                    }
                }

                $upcomingevents[] = $upcomingevent;
            }

            // Get first event for backward compatibility.
            $firstevent = reset($upcomingevents);

            return [
                'hasupcomingevents' => true,
                'upcomingevents' => $upcomingevents,
                'starttime' => $firstevent->starttime,
                'endtime' => $firstevent->endtime,
                'duration' => $firstevent->duration,
                'hasliveevent' => $firstevent->islive ?? false,
            ];
        }

        return [
            'hasupcomingevents' => false,
            'upcomingevents' => [],
        ];
    }

    /**
     * Format time difference for display.
     *
     * @param int $seconds Time difference in seconds
     * @return string Formatted time string
     */
    public static function format_time_diff($seconds) {
        $seconds = abs($seconds);

        if ($seconds < 60) {
            return get_string('event_time_minutes', 'googlemeet', 1);
        } else if ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return get_string('event_time_minutes', 'googlemeet', $minutes);
        } else if ($seconds < 86400) {
            $hours = round($seconds / 3600);
            return get_string('event_time_hours', 'googlemeet', $hours);
        } else {
            $days = round($seconds / 86400);
            return get_string('event_time_days', 'googlemeet', $days);
        }
    }
}
