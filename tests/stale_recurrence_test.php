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

use mod_googlemeet\local\stale_recurrence;
use mod_googlemeet\task\check_stale_recurrence;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Tests for the abandoned-recurrence detector + alert task.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(stale_recurrence::class)]
#[CoversClass(check_stale_recurrence::class)]
final class stale_recurrence_test extends \advanced_testcase {
    /**
     * Insert a two-hour googlemeet_events row.
     *
     * @param int $gmid Activity instance id.
     * @param int $eventdate Session start timestamp.
     */
    private function add_event(int $gmid, int $eventdate): void {
        global $DB;
        $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $gmid,
            'eventdate' => $eventdate,
            'duration' => 7200,
            'timemodified' => $eventdate,
            'autosynced' => 0,
            'syncattempts' => 0,
        ]);
    }

    /**
     * Insert a googlemeet_recordings row.
     *
     * @param int $gmid Activity instance id.
     * @param int $createdtime Recording creation timestamp.
     * @param int $deleted 1 for a trashed recording.
     */
    private function add_recording(int $gmid, int $createdtime, int $deleted = 0): void {
        global $DB;
        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $gmid,
            'recordingid' => 'rec-' . $gmid . '-' . $createdtime,
            'name' => 'Recording',
            'createdtime' => $createdtime,
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'transcripttext' => '',
            'transcriptfileid' => '',
            'notestext' => '',
            'notesdocid' => '',
            'visible' => 1,
            'deleted' => $deleted,
            'timedeleted' => 0,
            'timemodified' => $createdtime,
        ]);
    }

    /**
     * Create a googlemeet activity in a new course.
     *
     * @return \stdClass Instance record, with ->id and ->cmid.
     */
    private function make_module(): \stdClass {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
    }

    /**
     * A future session with the last recording older than the threshold is reported, with its cmid and session count.
     */
    public function test_detects_abandoned_recurrence(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 30 * DAYSECS);

        $stale = stale_recurrence::get_stale_recurrences(3, $now);

        $this->assertCount(1, $stale);
        $this->assertEquals($gm->id, $stale[0]->id);
        $this->assertEquals($gm->cmid, $stale[0]->cmid);
        $this->assertEquals(1, (int) $stale[0]->futurecount);
    }

    /**
     * A recording within the threshold keeps the activity off the list.
     */
    public function test_ignores_recent_recording(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 5 * DAYSECS);
        $this->assertCount(0, stale_recurrence::get_stale_recurrences(3, $now));
    }

    /**
     * An activity with only past sessions is not an abandoned recurrence.
     */
    public function test_ignores_when_no_future_sessions(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now - 10 * DAYSECS);
        $this->add_recording($gm->id, $now - 60 * DAYSECS);
        $this->assertCount(0, stale_recurrence::get_stale_recurrences(3, $now));
    }

    /**
     * An activity that never had a recording is not reported.
     */
    public function test_ignores_when_never_recorded(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->assertCount(0, stale_recurrence::get_stale_recurrences(3, $now));
    }

    /**
     * Trashed recordings do not count: with only those, the activity counts as never recorded.
     */
    public function test_ignores_when_only_deleted_recordings(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 60 * DAYSECS, 1);
        $this->assertCount(0, stale_recurrence::get_stale_recurrences(3, $now));
    }

    /**
     * Every site admin gets one stalerecurrence notification linking to the activity settings.
     */
    public function test_send_stale_alert_notifies_admins(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();

        $info = (object) [
            'id' => 123,
            'name' => 'Live classes',
            'course' => 7,
            'coursename' => 'Test course',
            'cmid' => 456,
            'lastrecording' => 1795000000,
            'futurecount' => 3,
        ];

        stale_recurrence::send_stale_alert($info);

        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertEquals('mod_googlemeet', $messages[0]->component);
        $this->assertEquals('stalerecurrence', $messages[0]->eventtype);
        $this->assertStringContainsString('Live classes', $messages[0]->subject);
        $this->assertStringContainsString('update=456', $messages[0]->fullmessagehtml);
    }

    /**
     * Run the scheduled task, swallowing its mtrace output.
     */
    private function run_task(): void {
        $task = new \mod_googlemeet\task\check_stale_recurrence();
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * The task alerts once, stays quiet within the re-notify window and alerts again after it.
     */
    public function test_task_notifies_once_then_respects_renotify(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('stalerecurrence_enabled', 1, 'googlemeet');
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        // First run: notifies once and records state.
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
        $this->assertNotEmpty($DB->get_field('googlemeet', 'stalealerttime', ['id' => $gm->id]));
        $sink->close();

        // Second run (within renotify window): no new message.
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
        $sink->close();

        // Age the last-alert timestamp beyond the renotify window: notifies again.
        $DB->set_field('googlemeet', 'stalealerttime', time() - 40 * DAYSECS, ['id' => $gm->id]);
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
        $sink->close();
    }

    /**
     * The stored alert time is reset once the activity is no longer stale, so a later relapse alerts at once.
     */
    public function test_task_clears_state_when_no_longer_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('stalerecurrence_enabled', 1, 'googlemeet');
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $eventid = $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $gm->id, 'eventdate' => time() + DAYSECS,
            'duration' => 7200, 'timemodified' => time(), 'autosynced' => 0, 'syncattempts' => 0,
        ]);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $this->run_task();
        $this->assertNotEmpty($DB->get_field('googlemeet', 'stalealerttime', ['id' => $gm->id]));

        // Remove the future session → no longer stale → state must be cleared.
        $DB->delete_records('googlemeet_events', ['id' => $eventid]);
        $this->run_task();
        $this->assertEquals(0, (int)$DB->get_field('googlemeet', 'stalealerttime', ['id' => $gm->id]));
    }

    /**
     * An explicit stalerecurrence_enabled = 0 turns the task off.
     */
    public function test_task_noop_when_disabled(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('stalerecurrence_enabled', 0, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * A never-saved stalerecurrence_enabled setting counts as enabled.
     */
    public function test_task_enabled_by_default_when_unset(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        // Leave stalerecurrence_enabled unset: get_config() then returns false, which the task reads as on.
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
    }
}
