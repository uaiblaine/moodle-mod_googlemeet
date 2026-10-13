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

use context_module;
use stdClass;

/**
 * Pre-session reminders.
 *
 * Two independent reminders per session, each deduplicated in googlemeet_notify_done by its kind:
 *  - KIND_MINUTES: "minutesbefore" minutes before the start (0 = off);
 *  - KIND_HOURS: "notifyhoursbefore" hours before the start (0 = off).
 *
 * The send window is tolerant to cron gaps: a reminder is due from its lead time until the
 * session starts, so a run that comes back late still sends it as long as the session has not
 * started. The hours reminder is skipped once the minutes reminder is already due, so a late
 * run never sends both at once.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminders {
    /** @var int Reminder "minutesbefore" minutes before the session. */
    const KIND_MINUTES = 0;

    /** @var int Reminder "notifyhoursbefore" hours before the session. */
    const KIND_HOURS = 1;

    /**
     * Options for the hours-before reminder select (site default and activity form).
     *
     * @return array hours => label
     */
    public static function hours_options(): array {
        $options = [0 => get_string('notifyhoursbefore_off', 'googlemeet')];
        foreach ([1, 2, 3, 6, 12, 24, 48, 72] as $hours) {
            $options[$hours] = get_string('numhours', 'moodle', $hours);
        }
        return $options;
    }

    /**
     * Sessions with a reminder due at $now, one entry per (session, reminder kind).
     *
     * Each entry carries: id (event id), kind, eventdate, duration, googlemeetid, googlemeetname,
     * url, cmid, courseid, coursename. Cancelled sessions are left out.
     *
     * @param int $now Reference timestamp.
     * @return stdClass[] Due reminders.
     */
    public static function get_due_events(int $now): array {
        global $DB;

        $select = "SELECT me.id,
                          me.eventdate,
                          me.duration,
                          m.id AS googlemeetid,
                          m.name AS googlemeetname,
                          m.url,
                          cm.id AS cmid,
                          c.id AS courseid,
                          c.fullname AS coursename
                     FROM {googlemeet_events} me
               INNER JOIN {googlemeet} m ON m.id = me.googlemeetid
               INNER JOIN {course_modules} cm
                       ON (cm.instance = m.id AND cm.visible = 1 AND cm.deletioninprogress = 0)
               INNER JOIN {modules} md ON (md.id = cm.module AND md.name = :modname)
               INNER JOIN {course} c ON (c.id = cm.course AND c.visible = 1)
                    WHERE m.notify = 1
                      AND me.eventdate > :now1";

        // Minutes reminder: due from (start - minutesbefore) until the session starts.
        $minutessql = $select . "
                      AND m.minutesbefore > 0
                      AND me.eventdate - m.minutesbefore * 60 <= :now2
                 ORDER BY me.eventdate, me.id";
        // Hours reminder: due from (start - notifyhoursbefore) until the minutes reminder is due.
        $hourssql = $select . "
                      AND m.notifyhoursbefore > 0
                      AND me.eventdate - m.notifyhoursbefore * 3600 <= :now2
                      AND me.eventdate - m.minutesbefore * 60 > :now3
                 ORDER BY me.eventdate, me.id";
        $params = ['modname' => 'googlemeet', 'now1' => $now, 'now2' => $now];
        $queries = [
            self::KIND_MINUTES => [$minutessql, $params],
            self::KIND_HOURS => [$hourssql, $params + ['now3' => $now]],
        ];

        $due = [];
        $cancelled = [];
        foreach ($queries as $kind => [$sql, $kindparams]) {
            $rs = $DB->get_recordset_sql($sql, $kindparams);
            foreach ($rs as $event) {
                $gid = (int)$event->googlemeetid;
                if (!array_key_exists($gid, $cancelled)) {
                    $cancelled[$gid] = schedule::get_cancelled($gid);
                }
                if ($cancelled[$gid] && schedule::is_cancelled((int)$event->eventdate, $cancelled[$gid]) !== false) {
                    continue;
                }
                $event->kind = $kind;
                $due[] = $event;
            }
            $rs->close();
        }
        return $due;
    }

    /**
     * Send every due reminder once per user and kind.
     *
     * @param int $now Reference timestamp.
     * @return int Number of reminders sent.
     */
    public static function run(int $now): int {
        $sent = 0;
        foreach (self::get_due_events($now) as $event) {
            $users = self::get_users_to_notify((int)$event->id, (int)$event->kind);
            foreach ($users as $user) {
                // Send first, then record: a crash between the two re-sends next run (a rare
                // duplicate), preferable to recording first and losing the reminder.
                try {
                    self::send($user, $event);
                } catch (\Throwable $e) {
                    mtrace('mod_googlemeet reminder failed for user ' . $user->id . ': ' . $e->getMessage());
                    continue;
                }
                try {
                    self::notify_done($user->id, $event->id, (int)$event->kind);
                } catch (\dml_write_exception $e) {
                    // Already recorded by an overlapping run: keep going with the other users.
                    mtrace('mod_googlemeet reminder already recorded for user ' . $user->id . ': ' . $e->getMessage());
                }
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Build the reminder message for a user (in the user's language).
     *
     * @param stdClass $user Recipient.
     * @param stdClass $event Due reminder row (see get_due_events()).
     * @return \core\message\message
     */
    public static function build_message(stdClass $user, stdClass $event): \core\message\message {
        $previouslang = null;
        if (!empty($user->lang) && get_string_manager()->translation_exists($user->lang, false)) {
            $previouslang = force_current_language($user->lang);
        }
        try {
            $kind = (int)($event->kind ?? self::KIND_MINUTES);
            $a = (object)[
                // Plain-text subject: no HTML escaping (would show "Q&amp;A").
                'name' => format_string(
                    $event->googlemeetname,
                    true,
                    ['context' => \context_module::instance($event->cmid), 'escape' => false]
                ),
                'date' => userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone),
                'start' => userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone),
                'end' => userdate(
                    $event->eventdate + $event->duration,
                    get_string('strftimehm', 'googlemeet'),
                    $user->timezone
                ),
                'timezone' => usertimezone($user->timezone),
            ];
            $subjectkey = $kind === self::KIND_HOURS ? 'reminder_subject_hours' : 'reminder_subject_minutes';
            $subject = get_string($subjectkey, 'googlemeet', $a);
            $url = new \moodle_url('/mod/googlemeet/view.php', ['id' => $event->cmid]);
            $html = self::get_messagehtml($user, $event);

            $message = new \core\message\message();
            $message->component = 'mod_googlemeet';
            $message->name = 'notification';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = html_to_text($html);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $html;
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $a->name;
            $message->courseid = $event->courseid;
            // Lets the Moodle app open the activity from the push notification.
            $message->customdata = self::app_customdata((int)$event->cmid, (int)$event->courseid, $url);
            return $message;
        } finally {
            if ($previouslang !== null) {
                force_current_language($previouslang);
            }
        }
    }

    /**
     * Send one reminder.
     *
     * @param stdClass $user Recipient.
     * @param stdClass $event Due reminder row.
     * @return void
     */
    public static function send(stdClass $user, stdClass $event): void {
        message_send(self::build_message($user, $event));
    }

    /**
     * Custom data the Moodle app uses to open the activity from a push notification.
     *
     * @param int $cmid Course module id.
     * @param int $courseid Course id.
     * @param \moodle_url $url Activity URL.
     * @return array
     */
    public static function app_customdata(int $cmid, int $courseid, \moodle_url $url): array {
        return [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'appurl' => $url->out(false),
        ];
    }

    /**
     * Generates the list of users who should receive the reminder for an event and have not yet been notified.
     *
     * Reminders are for students only. Recipients are users who hold a role with the 'student'
     * archetype in the module context or any parent context (course, category, system), have an
     * active enrolment in the course, can view the activity (mod/googlemeet:view), are not suspended,
     * satisfy the activity's access restrictions and do not manage activities in the course.
     * Users already recorded in googlemeet_notify_done for the event and reminder kind are excluded.
     *
     * @param int $eventid the event ID
     * @param int $kind reminder kind (self::KIND_*), deduplicated separately
     * @return stdClass[] users keyed by id (full user records)
     */
    public static function get_users_to_notify($eventid, int $kind = 0) {
        global $DB;

        $event = $DB->get_record('googlemeet_events', ['id' => $eventid], 'id, googlemeetid');
        if (!$event) {
            return [];
        }

        $cm = get_coursemodule_from_instance('googlemeet', $event->googlemeetid, 0, false, IGNORE_MISSING);
        if (!$cm || empty($cm->visible) || !empty($cm->deletioninprogress)) {
            return [];
        }

        $course = $DB->get_record('course', ['id' => $cm->course], 'id, visible');
        if (!$course || empty($course->visible)) {
            return [];
        }

        $context = context_module::instance($cm->id, IGNORE_MISSING);
        if (!$context) {
            return [];
        }

        // Active enrolments only (enrolment status, enrol instance status and time window).
        $candidates = get_enrolled_users($context, 'mod/googlemeet:view', 0, 'u.*', null, 0, 0, true);
        if (!$candidates) {
            return [];
        }

        // Students only: users with a 'student'-archetype role in the module context or its parents.
        $studentroleids = array_keys(get_archetype_roles('student'));
        if (!$studentroleids) {
            return [];
        }
        [$rolesql, $roleparams] = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, 'role');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        $students = array_flip($DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
              WHERE ra.roleid $rolesql AND ra.contextid $ctxsql",
            $roleparams + $ctxparams
        ));

        $notified = array_flip($DB->get_fieldset_select(
            'googlemeet_notify_done',
            'userid',
            'eventid = ? AND kind = ?',
            [$eventid, $kind]
        ));

        $users = [];
        foreach ($candidates as $user) {
            if (
                !isset($students[$user->id]) || isset($notified[$user->id])
                    || !empty($user->suspended) || !empty($user->deleted)
            ) {
                continue;
            }
            // A student who also manages activities here (e.g. also editing teacher) is not reminded.
            if (has_capability('moodle/course:manageactivities', $context, $user)) {
                continue;
            }
            // Respect visibility and access restrictions (availability conditions) for this user.
            if (!\core_availability\info_module::is_user_visible($cm, $user->id, false)) {
                continue;
            }
            $users[$user->id] = $user;
        }

        return $users;
    }

    /**
     * Record that a reminder kind was sent to a user for a session, so it is not sent again.
     *
     * @param int $userid
     * @param int $eventid
     * @param int $kind reminder kind (self::KIND_*)
     * @return int New googlemeet_notify_done id.
     */
    public static function notify_done($userid, $eventid, int $kind = 0) {
        global $DB;

        $notifydone = new stdClass();
        $notifydone->userid = $userid;
        $notifydone->eventid = $eventid;
        $notifydone->kind = $kind;
        $notifydone->timesent = time();

        return $DB->insert_record('googlemeet_notify_done', $notifydone);
    }

    /**
     * Delete the notify_done rows of sessions that have already started (no reminder is due for them).
     */
    public static function remove_notify_done_from_old_events() {
        global $DB;

        $now = time();

        $sql = "DELETE FROM {googlemeet_notify_done}
                 WHERE eventid IN (SELECT id FROM {googlemeet_events} WHERE eventdate < :now)";

        $DB->execute($sql, ['now' => $now]);
    }

    /**
     * Build the HTML body of a reminder from the site's emailcontent template (or the default string).
     *
     * @param object $user db record of user
     * @param object $event Due reminder row (see self::get_due_events()).
     * @return string - the content of the notification after assembly.
     */
    public static function get_messagehtml($user, $event) {
        global $CFG;

        $config = get_config('googlemeet');

        $startdate = userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone);
        $starttime = userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone);
        $endtime = userdate($event->eventdate + $event->duration, get_string('strftimehm', 'googlemeet'), $user->timezone);
        $url = "<a href=\"{$CFG->wwwroot}/mod/googlemeet/view.php?id={$event->cmid}\">
            {$CFG->wwwroot}/mod/googlemeet/view.php?id={$event->cmid}</a>";

        // User- and teacher-controlled values (names, course/activity titles) are escaped with s()
        // before being spliced into the admin's HTML email template, otherwise a crafted value
        // injects HTML/script into the notification body. $url is built by us (cmid is an int) and is
        // deliberately HTML; the date/time/timezone values come from Moodle formatters and are safe.
        $templatevars = [
            '%userfirstname%' => s($user->firstname),
            '%userlastname%' => s($user->lastname),
            '%coursename%' => s($event->coursename),
            '%googlemeetname%' => s($event->googlemeetname),
            '%eventdate%' => $startdate,
            '%duration%' => $starttime . ' – ' . $endtime,
            '%timezone%' => usertimezone($user->timezone),
            '%url%' => $url,
            '%cmid%' => (int) $event->cmid,
        ];

        // Use str_replace (literal), not preg_replace: replacement values can legitimately contain
        // regex backreference sequences such as $0 or \1 (e.g. in a user's name), which preg_replace
        // would interpret and corrupt.
        $template = !empty($config->emailcontent) ? $config->emailcontent : get_string('emailcontent_default', 'googlemeet');
        $emailcontent = str_replace(array_keys($templatevars), array_values($templatevars), $template);

        return $emailcontent;
    }
}
