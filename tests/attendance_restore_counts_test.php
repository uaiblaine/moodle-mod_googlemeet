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

namespace mod_googlemeet;

use mod_googlemeet\local\attendance\service;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * A restored session's attendance fetch state carries the counts of the restored attendance rows.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\restore_googlemeet_activity_structure_step::class)]
final class attendance_restore_counts_test extends \advanced_testcase {
    /**
     * Insert one attendance row.
     *
     * @param \stdClass $googlemeet Activity.
     * @param int $eventid Session id.
     * @param int $userid Matched user, 0 when unmatched.
     * @param string $name Display name.
     * @return void
     */
    private function add_attendance(\stdClass $googlemeet, int $eventid, int $userid, string $name): void {
        global $DB;
        $start = time() - 3 * HOURSECS;
        $DB->insert_record('googlemeet_attendance', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventid' => $eventid,
            'userid' => $userid,
            'displayname' => $name,
            'participanttype' => $userid ? 'signedin' : 'anonymous',
            'matchedby' => $userid ? 'name' : '',
            'timejoined' => $start,
            'timeleft' => $start + 600,
            'durationseconds' => 600,
            'sessions' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Back up a course with user data and restore it into a new course.
     *
     * @param \stdClass $course Source course.
     * @return int New course id.
     */
    private function backup_and_restore(\stdClass $course): int {
        global $USER;
        set_config('backup_general_users', 1, 'backup');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupdir = 'googlemeet-attcounts-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupdir));
        $newcourseid = \restore_dbops::create_new_course('Copy', 'attcountscopy', $course->category);
        $rc = new \restore_controller(
            $backupdir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return (int)$newcourseid;
    }

    /**
     * Two sessions with attendance (2 of 3 and 1 of 1 matched) keep their matched/participant counts.
     */
    public function test_restored_sync_rows_carry_attendance_counts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $ana = $gen->create_and_enrol($course, 'student');
        $luis = $gen->create_and_enrol($course, 'student');
        $googlemeet = $gen->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $DB->delete_records('googlemeet_events', ['googlemeetid' => $googlemeet->id]);

        $sessions = [
            ['start' => time() - 2 * DAYSECS, 'rows' => [[$ana->id, 'Ana'], [$luis->id, 'Luis'], [0, 'Guest']], 'matched' => 2],
            ['start' => time() - DAYSECS, 'rows' => [[$ana->id, 'Ana']], 'matched' => 1],
        ];
        foreach ($sessions as $session) {
            $eventid = (int)$DB->insert_record('googlemeet_events', (object)[
                'googlemeetid' => $googlemeet->id,
                'eventdate' => $session['start'],
                'duration' => HOURSECS,
                'timemodified' => time(),
            ]);
            foreach ($session['rows'] as [$userid, $name]) {
                $this->add_attendance($googlemeet, $eventid, (int)$userid, $name);
            }
            $DB->insert_record('googlemeet_attendance_sync', (object)[
                'googlemeetid' => $googlemeet->id,
                'eventid' => $eventid,
                'status' => service::STATUS_DONE,
                'attempts' => 1,
                'conferences' => 1,
                'participants' => count($session['rows']),
                'matched' => $session['matched'],
                'timemodified' => time(),
            ]);
        }

        $newcourseid = $this->backup_and_restore($course);
        $new = $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
        $rows = $DB->get_records_sql(
            "SELECT ge.eventdate, s.status, s.participants, s.matched
               FROM {googlemeet_events} ge
               JOIN {googlemeet_attendance_sync} s ON s.eventid = ge.id
              WHERE ge.googlemeetid = :id
           ORDER BY ge.eventdate ASC",
            ['id' => $new->id]
        );
        $this->assertCount(2, $rows);
        $got = array_map(
            static fn($r) => [$r->status, (int)$r->participants, (int)$r->matched],
            array_values($rows)
        );
        $this->assertSame([[service::STATUS_DONE, 3, 2], [service::STATUS_DONE, 1, 1]], $got);
        // Every restored sync row belongs to the copy and to one of its sessions.
        $this->assertSame(2, $DB->count_records('googlemeet_attendance_sync', ['googlemeetid' => $new->id]));
    }
}
