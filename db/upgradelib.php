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
