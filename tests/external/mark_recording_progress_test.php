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

namespace mod_googlemeet\external;

use core_external\external_api;
use mod_googlemeet\completion\custom_completion;
use mod_googlemeet\local\recording_progress;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Only measured viewing time completes a recording; the "Mark as viewed" button only marks it.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_external::class)]
final class mark_recording_progress_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $cm;
    /** @var \stdClass */
    private $recording;
    /** @var \stdClass */
    private $student;

    /**
     * Course with completion, an activity completed by one watched recording, a student and a recording.
     */
    private function build(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['enablecompletion' => 1]);
        $activity = $generator->create_module('googlemeet', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionrecordings' => 1,
        ]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $activity->id, $this->course->id, false, MUST_EXIST);
        $this->student = $generator->create_and_enrol($this->course, 'student');
        // One hour long: the completion threshold is the 8-minute cap.
        $this->recording = $generator->get_plugin_generator('mod_googlemeet')->create_recording([
            'googlemeetid' => $activity->id,
            'duration' => '01:00:00',
        ]);
        $this->setUser($this->student);
    }

    /**
     * Call the web service as the current user.
     *
     * @param int $delta Heartbeat seconds.
     * @param bool $completed The client "mark as viewed" flag.
     * @return array Result of call_external_function().
     */
    private function call(int $delta, bool $completed): array {
        $_POST['sesskey'] = sesskey();
        $result = external_api::call_external_function('mod_googlemeet_mark_recording_progress', [
            'recordingid' => $this->recording->id,
            'coursemoduleid' => $this->cm->id,
            'watchedsecondsdelta' => $delta,
            'completed' => $completed,
        ]);
        $this->assertFalse($result['error'], json_encode($result));
        return $result['data'];
    }

    /**
     * Send heartbeats until the completion threshold is reached.
     *
     * Each call is capped at 60 seconds and at the time elapsed since the last accepted heartbeat,
     * so the row is aged by a minute before every call. The first call only creates the row and
     * starts the clock, hence the one extra beat.
     *
     * @return array Data of the last response.
     */
    private function heartbeat_to_threshold(): array {
        $threshold = recording_progress::recording_completion_threshold($this->recording->duration);
        $data = [];
        for ($beat = 0; $beat <= intdiv($threshold, 60) + 1 && empty($data['completed']); $beat++) {
            $this->age_progress(MINSECS);
            $data = $this->call(60, false);
        }
        return $data;
    }

    /**
     * Move the last accepted heartbeat of the student's progress row back in time.
     *
     * @param int $seconds How long ago the last heartbeat was accepted.
     */
    private function age_progress(int $seconds): void {
        global $DB;
        $DB->set_field(
            'googlemeet_recording_progress',
            'timemodified',
            time() - $seconds,
            ['recordingid' => $this->recording->id, 'userid' => $this->student->id]
        );
    }

    /**
     * Insert the student's progress row directly, as another tab or an earlier visit would.
     *
     * @param int $timemodified Time of the last accepted heartbeat.
     */
    private function insert_progress(int $timemodified): void {
        global $DB;
        $DB->insert_record('googlemeet_recording_progress', (object)[
            'recordingid' => $this->recording->id,
            'userid' => $this->student->id,
            'watchedseconds' => 0,
            'completed' => 0,
            'markedviewed' => 0,
            'timecreated' => $timemodified,
            'timemodified' => $timemodified,
        ]);
    }

    /**
     * The state of the completionrecordings rule, live and as stored by the completion API.
     *
     * @return int[] [rule state, stored activity completion state]
     */
    private function completion_states(): array {
        $cminfo = get_fast_modinfo($this->course->id, $this->student->id)->get_cm($this->cm->id);
        $rule = (new custom_completion($cminfo, (int)$this->student->id))->get_state('completionrecordings');
        $stored = (new \completion_info($this->course))->get_data($cminfo, false, $this->student->id)->completionstate;
        return [(int)$rule, (int)$stored];
    }

    /**
     * Marking a recording as viewed by hand marks it but never completes it.
     */
    public function test_manual_mark_does_not_complete(): void {
        global $DB;
        $this->build();

        $data = $this->call(0, true);

        $this->assertTrue($data['markedviewed']);
        $this->assertFalse($data['completed']);
        $this->assertSame(0, $data['watchedseconds']);
        $row = $DB->get_record('googlemeet_recording_progress', ['recordingid' => $this->recording->id,
            'userid' => $this->student->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$row->markedviewed);
        $this->assertSame(0, (int)$row->completed);
        $this->assertSame([COMPLETION_INCOMPLETE, COMPLETION_INCOMPLETE], $this->completion_states());

        // The student's own badge shows the recording as viewed.
        $state = recording_progress::recording_progress_state($this->recording, $row);
        $this->assertTrue($state['progresscompleted']);
        $this->assertFalse($state['progressmeasuredcompleted']);
    }

    /**
     * Control: measured heartbeats reaching the threshold complete the recording and the activity.
     */
    public function test_heartbeats_reaching_threshold_complete(): void {
        $this->build();
        $this->assertSame([COMPLETION_INCOMPLETE, COMPLETION_INCOMPLETE], $this->completion_states());

        $data = $this->heartbeat_to_threshold();

        $this->assertGreaterThanOrEqual(
            recording_progress::recording_completion_threshold($this->recording->duration),
            $data['watchedseconds']
        );
        $this->assertTrue($data['completed']);
        $this->assertFalse($data['markedviewed']);
        $this->assertSame([COMPLETION_COMPLETE, COMPLETION_COMPLETE], $this->completion_states());
    }

    /**
     * Heartbeats after a manual mark still count, so measured completion stays reachable.
     */
    public function test_heartbeats_after_manual_mark_still_complete(): void {
        $this->build();
        $this->call(0, true);
        $this->assertSame([COMPLETION_INCOMPLETE, COMPLETION_INCOMPLETE], $this->completion_states());

        $data = $this->heartbeat_to_threshold();

        $this->assertTrue($data['completed']);
        $this->assertTrue($data['markedviewed']);
        $this->assertSame([COMPLETION_COMPLETE, COMPLETION_COMPLETE], $this->completion_states());
    }

    /**
     * The response always carries the markedviewed flag, also before any row exists.
     */
    public function test_response_carries_markedviewed(): void {
        $this->build();

        $data = $this->call(0, false);

        $this->assertArrayHasKey('markedviewed', $data);
        $this->assertFalse($data['markedviewed']);
        $this->assertFalse($data['completed']);
    }

    /**
     * A burst of calls credits no more than the real time elapsed since the last accepted heartbeat.
     */
    public function test_burst_credits_at_most_elapsed_time(): void {
        $this->build();
        $reference = time() - MINSECS;
        $this->insert_progress($reference);

        $data = [];
        for ($i = 0; $i < 20; $i++) {
            $data = $this->call(60, false);
        }

        $this->assertGreaterThan(0, $data['watchedseconds']);
        $this->assertLessThanOrEqual(time() - $reference, $data['watchedseconds']);
        $this->assertFalse($data['completed']);
    }

    /**
     * A row that already exists when the call starts (another tab inserted it) is capped the same way.
     */
    public function test_row_inserted_by_another_tab_is_capped(): void {
        $this->build();
        $reference = time();
        $this->insert_progress($reference);

        $data = $this->call(60, false);

        $this->assertLessThanOrEqual(time() - $reference, $data['watchedseconds']);
    }

    /**
     * The first heartbeat has no earlier heartbeat to measure from: it only starts the clock.
     */
    public function test_first_heartbeat_starts_the_clock(): void {
        global $DB;
        $this->build();

        $data = $this->call(60, false);

        $this->assertSame(0, $data['watchedseconds']);
        $this->assertTrue($DB->record_exists('googlemeet_recording_progress', ['recordingid' => $this->recording->id,
            'userid' => $this->student->id]));
    }
}
