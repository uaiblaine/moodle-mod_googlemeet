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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * Visibility of a recording is enforced by mod_googlemeet_get_ai_analysis.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_external::class)]
final class get_ai_analysis_test extends \advanced_testcase {
    /** @var string Marker text of the analysis summary. */
    private const SUMMARY = 'Secret summary of a hidden class';

    /** @var \stdClass */
    private $cm;
    /** @var \stdClass */
    private $recording;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $student;

    /**
     * Course, activity, a teacher, a student, and a recording with a completed, reviewed analysis.
     *
     * @param int $visible Whether the recording is visible.
     */
    private function build(int $visible): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $gm = $generator->get_plugin_generator('mod_googlemeet');
        $course = $generator->create_course();
        $activity = $generator->create_module('googlemeet', ['course' => $course->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $activity->id, $course->id, false, MUST_EXIST);
        $this->teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->student = $generator->create_and_enrol($course, 'student');
        $this->recording = $gm->create_recording(['googlemeetid' => $activity->id, 'visible' => $visible]);
        $gm->create_ai_analysis([
            'recordingid' => $this->recording->id,
            'summary' => self::SUMMARY,
            'status' => 'completed',
            'reviewed' => 1,
            'reviewedby' => $this->teacher->id,
        ]);
    }

    /**
     * Call the web service as the current user.
     *
     * @return array Result of call_external_function().
     */
    private function call(): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_googlemeet_get_ai_analysis', [
            'recordingid' => $this->recording->id,
            'coursemoduleid' => $this->cm->id,
        ]);
    }

    /**
     * A student cannot read the analysis of a hidden recording.
     */
    public function test_student_cannot_read_hidden_recording(): void {
        $this->build(0);
        $this->setUser($this->student);
        $result = $this->call();
        $this->assertTrue($result['error']);
        $this->assertSame('recordingnotfound', ((object)$result['exception'])->errorcode);
        $this->assertStringNotContainsString(self::SUMMARY, json_encode($result));
    }

    /**
     * Control: the same recording, visible, serves its summary to the student.
     */
    public function test_student_reads_visible_recording(): void {
        $this->build(1);
        $this->setUser($this->student);
        $result = $this->call();
        $this->assertFalse($result['error']);
        $this->assertSame(self::SUMMARY, $result['data']['summary']);
    }

    /**
     * A teacher who can edit recordings still reads a hidden recording's analysis.
     */
    public function test_teacher_reads_hidden_recording(): void {
        $this->build(0);
        $this->setUser($this->teacher);
        $result = $this->call();
        $this->assertFalse($result['error']);
        $this->assertSame(self::SUMMARY, $result['data']['summary']);
    }
}
