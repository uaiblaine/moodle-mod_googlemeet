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

use mod_googlemeet\local\schedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Course reset with a new start date moves the schedule of the Google Meet activities.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule::class)]
#[CoversFunction('googlemeet_reset_userdata')]
final class reset_dates_test extends \advanced_testcase {
    /**
     * An activity with its schedule: start and end dates, a session that stays past after the shift,
     * one that moves into the future with nothing read yet, one that moves into the future with
     * attendance stored, a holiday period, a cancelled date and the calendar mirrors.
     *
     * @param int $coursestart Start date of the course.
     * @param int $now Current time.
     * @return array [course, googlemeet id, session ids keyed past, future, read]
     */
    private function build(int $coursestart, int $now): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => $coursestart]);
        $gmid = (int)$gen->create_module('googlemeet', ['course' => $course->id])->id;
        // The sessions the add form built from the generator's recurrence are replaced by known ones.
        \mod_googlemeet\local\schedule::delete_events($gmid);
        $DB->update_record('googlemeet', (object)['id' => $gmid, 'eventdate' => $coursestart + DAYSECS,
            'eventenddate' => $coursestart + 90 * DAYSECS]);

        $sessions = [];
        $starts = ['past' => $coursestart + DAYSECS, 'future' => $now - 10 * DAYSECS, 'read' => $now - 5 * DAYSECS];
        foreach ($starts as $key => $date) {
            $sessions[$key] = (int)$DB->insert_record('googlemeet_events', (object)['googlemeetid' => $gmid,
                'eventdate' => $date, 'duration' => HOURSECS, 'timemodified' => $now, 'autosynced' => $date + 2 * HOURSECS,
                'syncattempts' => 3, 'nextsyncattempt' => $date + 3 * HOURSECS]);
            $DB->insert_record('googlemeet_attendance_sync', (object)['googlemeetid' => $gmid, 'eventid' => $sessions[$key],
                'status' => 'done', 'timemodified' => $now]);
            $DB->insert_record('event', (object)[
                'name' => 'Session',
                'description' => '',
                'uuid' => '',
                'courseid' => $course->id,
                'groupid' => 0,
                'userid' => 0,
                'modulename' => 'googlemeet',
                'instance' => $gmid,
                'eventtype' => helper::GOOGLEMEET_EVENT_START,
                'type' => CALENDAR_EVENT_TYPE_ACTION,
                'timestart' => $date,
                'timeduration' => HOURSECS,
                'timesort' => $date,
                'visible' => 1,
                'timemodified' => $now,
            ]);
        }
        $DB->insert_record('googlemeet_attendance', (object)['googlemeetid' => $gmid, 'eventid' => $sessions['read'],
            'userid' => 0, 'displayname' => 'Guest', 'participanttype' => 'anonymous', 'matchedby' => '',
            'timejoined' => $now - 5 * DAYSECS, 'timeleft' => $now - 5 * DAYSECS + 600, 'durationseconds' => 600,
            'sessions' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('googlemeet_holidays', (object)['googlemeetid' => $gmid, 'name' => 'Break',
            'startdate' => $coursestart + 20 * DAYSECS, 'enddate' => $coursestart + 27 * DAYSECS, 'timemodified' => $now]);
        $DB->insert_record('googlemeet_cancelled', (object)['googlemeetid' => $gmid,
            'cancelleddate' => $coursestart + 8 * DAYSECS, 'timemodified' => $now]);
        return [$course, $gmid, $sessions];
    }

    /**
     * Every schedule date of the activities of a course.
     *
     * @param int $gmid Activity id.
     * @return array Field label => value.
     */
    private function dates(int $gmid): array {
        global $DB;
        $gm = $DB->get_record('googlemeet', ['id' => $gmid]);
        $dates = ['googlemeet.eventdate' => (int)$gm->eventdate, 'googlemeet.eventenddate' => (int)$gm->eventenddate];
        foreach ($DB->get_records('googlemeet_events', ['googlemeetid' => $gmid], 'id') as $e) {
            $dates['events.' . $e->id] = (int)$e->eventdate;
        }
        foreach ($DB->get_records('googlemeet_holidays', ['googlemeetid' => $gmid], 'id') as $h) {
            $dates['holidays.start.' . $h->id] = (int)$h->startdate;
            $dates['holidays.end.' . $h->id] = (int)$h->enddate;
        }
        foreach ($DB->get_records('googlemeet_cancelled', ['googlemeetid' => $gmid], 'id') as $c) {
            $dates['cancelled.' . $c->id] = (int)$c->cancelleddate;
        }
        $events = $DB->get_records('event', ['modulename' => 'googlemeet', 'instance' => $gmid,
            'eventtype' => helper::GOOGLEMEET_EVENT_START], 'id');
        foreach ($events as $ev) {
            $dates['calendar.start.' . $ev->id] = (int)$ev->timestart;
            $dates['calendar.sort.' . $ev->id] = (int)$ev->timesort;
        }
        return $dates;
    }

    /**
     * Resetting a course to a later start date moves every schedule date of its activities by the same
     * shift, clears the sync state of the sessions moved into the future (keeping a fetch state that
     * holds attendance), reports the dates, and leaves the activities of another course alone.
     */
    public function test_reset_start_date_shifts_schedule(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        $oldstart = $now - 400 * DAYSECS;
        $shift = 370 * DAYSECS;
        [$course, $gmid, $sessions] = $this->build($oldstart, $now);
        [, $othergmid] = $this->build($oldstart, $now);
        $before = $this->dates($gmid);
        $otherbefore = $this->dates($othergmid);
        $this->assertCount(14, $before);

        $status = reset_course_userdata((object)[
            'id' => $course->id,
            'reset_start_date' => $oldstart + $shift,
            'reset_start_date_old' => $oldstart,
            'reset_end_date' => 0,
            'reset_end_date_old' => 0,
        ]);

        $expected = array_map(static fn(int $value): int => $value + $shift, $before);
        $this->assertSame($expected, $this->dates($gmid));
        $this->assertSame($otherbefore, $this->dates($othergmid));

        $past = $DB->get_record('googlemeet_events', ['id' => $sessions['past']]);
        $this->assertSame([3, true], [(int)$past->syncattempts, (int)$past->autosynced > 0]);
        foreach (['future', 'read'] as $key) {
            $event = $DB->get_record('googlemeet_events', ['id' => $sessions[$key]]);
            $this->assertSame([0, 0, 0], [(int)$event->autosynced, (int)$event->syncattempts, (int)$event->nextsyncattempt]);
        }
        $this->assertTrue($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $sessions['past']]));
        $this->assertFalse($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $sessions['future']]));
        $this->assertTrue($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $sessions['read']]));

        $component = get_string('modulenameplural', 'googlemeet');
        $items = array_filter($status, static fn(array $row): bool => $row['component'] === $component);
        $this->assertSame([get_string('date')], array_values(array_column($items, 'item')));
        $this->assertSame([false], array_values(array_column($items, 'error')));
    }

    /**
     * A reset that keeps the start date moves nothing (the force: a shift is what moves the dates).
     */
    public function test_reset_without_shift_moves_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        [$course, $gmid] = $this->build($now - 400 * DAYSECS, $now);
        $before = $this->dates($gmid);

        reset_course_userdata((object)['id' => $course->id]);

        $this->assertSame($before, $this->dates($gmid));
    }

    /**
     * A reset with a shift queues one Google Calendar patch per activity mirrored in Google Calendar
     * (Google event and organiser set) and none for an activity without a Google event; the patch runs
     * later in cron, so the reset itself calls nobody.
     */
    public function test_reset_start_date_queues_calendar_patch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        $oldstart = $now - 400 * DAYSECS;
        [$course, $mirroredid] = $this->build($oldstart, $now);
        $DB->update_record('googlemeet', (object)['id' => $mirroredid, 'eventid' => 'abc123',
            'creatoremail' => 'organiser@example.com']);
        $gen = $this->getDataGenerator();
        $plainid = (int)$gen->create_module('googlemeet', ['course' => $course->id])->id;
        $DB->update_record('googlemeet', (object)['id' => $plainid, 'eventdate' => $oldstart + DAYSECS, 'eventid' => null,
            'creatoremail' => 'organiser@example.com']);
        $class = '\mod_googlemeet\task\calendar_update_event';
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks($class));

        reset_course_userdata((object)[
            'id' => $course->id,
            'reset_start_date' => $oldstart + 370 * DAYSECS,
            'reset_start_date_old' => $oldstart,
            'reset_end_date' => 0,
            'reset_end_date_old' => 0,
        ]);

        $tasks = \core\task\manager::get_adhoc_tasks($class);
        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame([$mirroredid, (int)get_admin()->id], [(int)$data->googlemeetid, (int)$data->editorid]);
    }
}
