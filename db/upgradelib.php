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
 * Helpers for the upgrade steps of mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @category    upgrade
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Fill googlemeet.creatoruserid from creatoremail where the e-mail names exactly one active user.
 *
 * The match ignores case and surrounding spaces and only counts users that are neither deleted nor
 * suspended. An activity whose e-mail matches nobody, or several users (allowaccountssameemail),
 * keeps 0: guessing would bind the room to the wrong person's Google token. The runtime fallback
 * in {@see \mod_googlemeet\local\creator::resolve()} applies the same rule (keep the two in step)
 * and records the id once a single user owns the e-mail. Rows that already have an id are skipped,
 * so running it again is harmless.
 *
 * Self-contained on purpose: upgrade code must not depend on classes that later versions change.
 *
 * @return int Number of activities that received a creator user id.
 */
function mod_googlemeet_backfill_creatoruserid(): int {
    global $DB;

    $activities = $DB->get_records_select('googlemeet', 'creatoruserid = 0', null, 'id', 'id, creatoremail');
    $select = $DB->sql_equal('email', ':email', false) . ' AND deleted = 0 AND suspended = 0';
    $filled = 0;
    foreach ($activities as $activity) {
        $email = trim((string)$activity->creatoremail);
        if ($email === '') {
            continue;
        }
        $users = $DB->get_records_select('user', $select, ['email' => $email], 'id', 'id', 0, 2);
        if (count($users) !== 1) {
            continue;
        }
        $DB->set_field('googlemeet', 'creatoruserid', (int)reset($users)->id, ['id' => $activity->id]);
        $filled++;
    }
    return $filled;
}

/**
 * Round the stored holiday and cancelled-session days to the nearest site midnight.
 *
 * Those days were saved as midnight in the editing teacher's timezone and are read as site days
 * now. A teacher's midnight lies within 12 hours of the site midnight of the day they picked
 * (the offset between two timezones is at most that, ignoring the rare 12 to 14 hour cases), so
 * the closer of the site midnights around the stored value is the picked day. A value already at
 * a site midnight is its own nearest one, so a rerun changes nothing.
 *
 * @return int Number of values changed.
 */
function mod_googlemeet_normalise_session_days(): int {
    global $DB;

    $changed = 0;
    $tables = ['googlemeet_holidays' => ['startdate', 'enddate'], 'googlemeet_cancelled' => ['cancelleddate']];
    foreach ($tables as $table => $fields) {
        $rs = $DB->get_recordset($table, null, 'id', 'id, ' . implode(', ', $fields));
        foreach ($rs as $row) {
            $update = [];
            foreach ($fields as $field) {
                $nearest = mod_googlemeet_nearest_site_midnight((int)$row->{$field});
                if ($nearest !== (int)$row->{$field}) {
                    $update[$field] = $nearest;
                    $changed++;
                }
            }
            if ($update) {
                $update['id'] = $row->id;
                $DB->update_record($table, (object)$update);
            }
        }
        $rs->close();
    }
    return $changed;
}

/**
 * The site midnight nearest to a timestamp; a tie goes to the later one.
 *
 * @param int $time Unix timestamp.
 * @return int Site-timezone midnight, as a timestamp.
 */
function mod_googlemeet_nearest_site_midnight(int $time): int {
    $day = (new DateTimeImmutable('@' . $time))
        ->setTimezone(core_date::get_server_timezone_object())
        ->setTime(0, 0);
    $before = $day->getTimestamp();
    $after = $day->modify('+1 day')->getTimestamp();
    return ($time - $before) < ($after - $time) ? $before : $after;
}
