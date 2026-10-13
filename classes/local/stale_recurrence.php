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

use moodle_url;
use stdClass;

/**
 * Abandoned recurrences: activities that still schedule sessions but stopped recording, and the
 * alert sent to the site admins about them.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stale_recurrence {
    /**
     * Find googlemeet instances whose recurrence looks abandoned: they still schedule future
     * sessions and have recorded at least once, but have had no recording in the last $weeks weeks.
     *
     * Read-only. Uses a recordset (not get_records_sql) to avoid first-column indexing pitfalls.
     *
     * @param int $weeks Staleness threshold in weeks.
     * @param int|null $now Reference timestamp (defaults to time()); injectable for tests.
     * @return array Array of stdClass {id, name, course, coursename, cmid, lastrecording, futurecount}.
     */
    public static function get_stale_recurrences(int $weeks, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'googlemeet'], MUST_EXIST);

        $sql = "SELECT g.id, g.name, g.course, c.fullname AS coursename, cm.id AS cmid,
                       (SELECT MAX(r.createdtime)
                          FROM {googlemeet_recordings} r
                         WHERE r.googlemeetid = g.id AND r.deleted = 0) AS lastrecording,
                       (SELECT COUNT(1)
                          FROM {googlemeet_events} e
                         WHERE e.googlemeetid = g.id AND e.eventdate > :now1) AS futurecount
                  FROM {googlemeet} g
                  JOIN {course_modules} cm ON cm.instance = g.id AND cm.module = :moduleid
                  JOIN {course} c ON c.id = g.course
                 WHERE EXISTS (SELECT 1 FROM {googlemeet_events} e2
                                WHERE e2.googlemeetid = g.id AND e2.eventdate > :now2)
                   AND EXISTS (SELECT 1 FROM {googlemeet_recordings} r2
                                WHERE r2.googlemeetid = g.id AND r2.deleted = 0)";
        $params = ['now1' => $now, 'now2' => $now, 'moduleid' => $moduleid];

        $threshold = $weeks * 7 * DAYSECS;
        $stale = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            if ($row->lastrecording !== null && ($now - (int) $row->lastrecording) > $threshold) {
                $stale[] = $row;
            }
        }
        $rs->close();

        return $stale;
    }

    /**
     * Send the abandoned-recurrence alert to every site admin.
     *
     * @param stdClass $info A row returned by self::get_stale_recurrences().
     * @return void
     */
    public static function send_stale_alert(stdClass $info): void {
        $editurl = new moodle_url('/course/modedit.php', ['update' => $info->cmid]);
        $lastrec = $info->lastrecording
            ? userdate((int) $info->lastrecording, get_string('strftimedate', 'langconfig'))
            : '-';

        $a = (object) [
            'activity' => format_string($info->name),
            'course' => format_string($info->coursename),
            'lastrecording' => $lastrec,
            'futurecount' => (int) $info->futurecount,
            'editurl' => $editurl->out(false),
        ];

        $subject = get_string('stalerecurrence_subject', 'mod_googlemeet', $a);

        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'mod_googlemeet';
            $message->name = 'stalerecurrence';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = $subject;
            $message->fullmessage = get_string('stalerecurrence_body', 'mod_googlemeet', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = get_string('stalerecurrence_body_html', 'mod_googlemeet', $a);
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $editurl->out(false);
            $message->contexturlname = get_string('stalerecurrence_editlink', 'mod_googlemeet');
            message_send($message);
        }
    }
}
