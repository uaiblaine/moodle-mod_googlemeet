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

namespace mod_googlemeet\local\attendance;

/**
 * Rows of the teacher attendance view and its export.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var string Row of a matched participant. */
    public const ROW_PRESENT = 'present';
    /** @var string Participant not matched to a student. */
    public const ROW_UNMATCHED = 'unmatched';
    /** @var string Student who did not attend. */
    public const ROW_ABSENT = 'absent';

    /**
     * Users a viewer may see in the attendance of this activity, or null when the viewer is not fenced.
     *
     * Mirrors {@see \mod_googlemeet\local\report_builder::is_fenced()}: the activity does not support
     * group mode (FEATURE_GROUPS is false, so the course module never carries its own mode), hence the
     * COURSE group mode decides, forced or not. In SEPARATEGROUPS a viewer without
     * moodle/site:accessallgroups only sees the members of their own course groups; a viewer in no
     * group sees nobody (an empty array).
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $viewerid User looking at the attendance.
     * @return array|null User id => true, or null when every user may be seen.
     */
    public static function fenced_userids(\stdClass $googlemeet, int $viewerid): ?array {
        global $DB;

        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        if (
            (int)$course->groupmode !== SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', \context_module::instance($cm->id), $viewerid)
        ) {
            return null;
        }
        $groupids = array_keys(groups_get_all_groups($course->id, $viewerid, 0, 'g.id'));
        if (!$groupids) {
            return [];
        }
        // Plain membership, as report_builder::get_students() reads it through get_enrolled_users(): not
        // groups_get_groups_members(), which applies group visibility for the current user, not the viewer.
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $groupids));
        $userids = $DB->get_fieldset_sql("SELECT DISTINCT userid FROM {groups_members} WHERE groupid $insql", $params);
        return array_fill_keys(array_map('intval', $userids), true);
    }

    /**
     * Users a viewer may link a Meet participant to: the candidates of the activity inside the viewer's fence.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $viewerid User doing the linking.
     * @return \stdClass[] id => user, as {@see service::get_candidates()}.
     */
    public static function link_candidates(\stdClass $googlemeet, int $viewerid): array {
        $candidates = service::get_candidates($googlemeet);
        $fence = self::fenced_userids($googlemeet, $viewerid);
        return $fence === null ? $candidates : array_intersect_key($candidates, $fence);
    }

    /**
     * Whether a viewer may set the Moodle user of one attendance row of a session.
     *
     * The row must be one the viewer is shown ({@see self::session_rows()}) and the user, unless 0
     * (unlink), one of the viewer's {@see self::link_candidates()}: a fenced teacher can neither
     * unlink a participant of another group nor link anybody to a student outside their groups.
     * The capability (mod/googlemeet:manageattendance) is checked by the caller.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $eventid Session id.
     * @param int $attendanceid Attendance row id.
     * @param int $userid User to link (0 to unlink).
     * @param int $viewerid User doing the linking.
     * @return bool
     */
    public static function can_link(\stdClass $googlemeet, int $eventid, int $attendanceid, int $userid, int $viewerid): bool {
        $shown = array_column(self::session_rows($googlemeet, $eventid, null, $viewerid), 'attendanceid');
        if (!$attendanceid || !in_array($attendanceid, $shown, true)) {
            return false;
        }
        return $userid === 0 || isset(self::link_candidates($googlemeet, $viewerid)[$userid]);
    }

    /**
     * Build the rows of one session a viewer may see: present students, unmatched participants, absent students.
     *
     * A viewer fenced to their own groups ({@see self::fenced_userids()}) only gets the present and absent
     * rows of those groups' members. Unmatched participants belong to no group yet: such a viewer only
     * gets them with mod/googlemeet:manageattendance, which they need in order to link them; a
     * viewer who can only read the report does not see who else joined.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $eventid Session id.
     * @param \stdClass[]|null $candidates Students (defaults to service::get_candidates()).
     * @param int|null $viewerid User the rows are built for (defaults to the current user).
     * @return array[] Each: kind, userid, attendanceid, name, email, meetname, timejoined, timeleft,
     *                 durationseconds, sessions, matchedby, participanttype.
     */
    public static function session_rows(
        \stdClass $googlemeet,
        int $eventid,
        ?array $candidates = null,
        ?int $viewerid = null
    ): array {
        global $USER;

        $viewerid = $viewerid ?? (int)$USER->id;
        $candidates = $candidates ?? service::get_candidates($googlemeet);
        $present = [];
        $unmatched = [];
        $seen = [];
        foreach (service::get_attendance((int)$googlemeet->id, $eventid) as $row) {
            $item = [
                'kind' => $row->userid ? self::ROW_PRESENT : self::ROW_UNMATCHED,
                'userid' => (int)$row->userid,
                'attendanceid' => (int)$row->id,
                'name' => $row->userid && isset($row->firstname) ? fullname($row) : (string)$row->displayname,
                'email' => $row->userid ? (string)($row->useremail ?? '') : (string)($row->email ?? ''),
                'meetname' => (string)$row->displayname,
                'timejoined' => (int)$row->timejoined,
                'timeleft' => (int)$row->timeleft,
                'durationseconds' => (int)$row->durationseconds,
                'sessions' => (int)$row->sessions,
                'matchedby' => (string)$row->matchedby,
                'participanttype' => (string)$row->participanttype,
            ];
            if ($row->userid) {
                $seen[(int)$row->userid] = true;
                $present[] = $item;
            } else {
                $unmatched[] = $item;
            }
        }

        // Staff (who can see this report) are listed when they attended, never as absent.
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, 0, false, MUST_EXIST);
        $staff = get_enrolled_users(\context_module::instance($cm->id), 'mod/googlemeet:viewreports', 0, 'u.id');

        $absent = [];
        foreach ($candidates as $user) {
            if (isset($seen[(int)$user->id]) || isset($staff[$user->id])) {
                continue;
            }
            $absent[] = [
                'kind' => self::ROW_ABSENT,
                'userid' => (int)$user->id,
                'attendanceid' => 0,
                'name' => fullname($user),
                'email' => (string)$user->email,
                'meetname' => '',
                'timejoined' => 0,
                'timeleft' => 0,
                'durationseconds' => 0,
                'sessions' => 0,
                'matchedby' => '',
                'participanttype' => '',
            ];
        }
        \core_collator::asort_array_of_arrays_by_key($absent, 'name');

        $rows = array_merge($present, $unmatched, array_values($absent));
        $fence = self::fenced_userids($googlemeet, $viewerid);
        if ($fence === null) {
            return $rows;
        }
        $seeunmatched = has_capability('mod/googlemeet:manageattendance', \context_module::instance($cm->id), $viewerid);
        return array_values(array_filter($rows, static function (array $row) use ($fence, $seeunmatched): bool {
            return $row['kind'] === self::ROW_UNMATCHED ? $seeunmatched : isset($fence[$row['userid']]);
        }));
    }

    /**
     * Label of a fetch status ('none' when the session was never queued).
     *
     * @param string|null $status Status.
     * @return string
     */
    public static function status_label(?string $status): string {
        $status = in_array($status, ['pending', 'done', 'nodata', 'scope', 'error'], true) ? $status : 'none';
        return get_string('attendance_status_' . $status, 'googlemeet');
    }

    /**
     * Neutralise spreadsheet formulas in exported text (a Meet display name is chosen by the participant).
     *
     * @param string $value
     * @return string
     */
    public static function safe_cell(string $value): string {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Export columns and rows.
     *
     * The e-mail column is only included when the site shows e-mail as an identity field to the
     * current user in this context (showuseridentity + moodle/site:viewuseridentity).
     *
     * @param array[] $rows Rows from session_rows().
     * @param \context|null $context Module context (null: no e-mail column).
     * @return array ['columns' => [...], 'rows' => [[...], ...]]
     */
    public static function export_table(array $rows, ?\context $context = null): array {
        $showemail = $context && in_array('email', \core_user\fields::get_identity_fields($context), true);
        $columns = [
            'name' => get_string('attendance_col_student', 'googlemeet'),
            'email' => get_string('email'),
            'status' => get_string('attendance_col_status', 'googlemeet'),
            'meetname' => get_string('attendance_col_meetname', 'googlemeet'),
            'joined' => get_string('attendance_col_joined', 'googlemeet'),
            'left' => get_string('attendance_col_left', 'googlemeet'),
            'minutes' => get_string('attendance_col_minutes', 'googlemeet'),
            'sessions' => get_string('attendance_col_sessions', 'googlemeet'),
        ];
        if (!$showemail) {
            unset($columns['email']);
        }
        $out = [];
        foreach ($rows as $row) {
            $line = [
                self::safe_cell((string)$row['name']),
                self::safe_cell((string)$row['email']),
                get_string('attendance_kind_' . $row['kind'], 'googlemeet'),
                self::safe_cell((string)$row['meetname']),
                $row['timejoined'] ? userdate($row['timejoined'], '%Y-%m-%d %H:%M') : '',
                $row['timeleft'] ? userdate($row['timeleft'], '%Y-%m-%d %H:%M') : '',
                (int)round($row['durationseconds'] / 60),
                $row['sessions'],
            ];
            if (!$showemail) {
                unset($line[1]);
            }
            $out[] = array_values($line);
        }
        return ['columns' => $columns, 'rows' => $out];
    }
}
