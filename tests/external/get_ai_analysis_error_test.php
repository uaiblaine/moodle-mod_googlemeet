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
 * mod_googlemeet_get_ai_analysis gives the stored failure text only to users who can edit recordings.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_external::class)]
final class get_ai_analysis_error_test extends \advanced_testcase {
    /** @var string Raw provider error stored on the analysis row. */
    private const RAW = 'Error generating analysis: API key not valid. Please pass a valid API key. [project 1234]';

    /** @var \stdClass */
    private $cm;
    /** @var \stdClass */
    private $recording;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $student;

    /**
     * Course, activity, a teacher, a student and a visible recording with an analysis row.
     *
     * @param string $status Analysis status.
     * @param string $error Stored error text.
     */
    private function build(string $status, string $error): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $gm = $generator->get_plugin_generator('mod_googlemeet');
        $course = $generator->create_course();
        $activity = $generator->create_module('googlemeet', ['course' => $course->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $activity->id, $course->id, false, MUST_EXIST);
        $this->teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->student = $generator->create_and_enrol($course, 'student');
        $this->recording = $gm->create_recording(['googlemeetid' => $activity->id, 'visible' => 1]);
        $gm->create_ai_analysis([
            'recordingid' => $this->recording->id,
            'status' => $status,
            'error' => $error,
            'reviewed' => 1,
            'reviewedby' => $this->teacher->id,
        ]);
    }

    /**
     * Call the web service as the current user and return its data.
     *
     * @return array
     */
    private function call(): array {
        $_POST['sesskey'] = sesskey();
        $result = external_api::call_external_function('mod_googlemeet_get_ai_analysis', [
            'recordingid' => $this->recording->id,
            'coursemoduleid' => $this->cm->id,
        ]);
        $this->assertFalse($result['error'], json_encode($result));
        return $result['data'];
    }

    /**
     * A student sees the generic failure message, never the provider's text.
     */
    public function test_student_gets_generic_error(): void {
        $this->build('failed', self::RAW);
        $this->setUser($this->student);
        $data = $this->call();
        $this->assertSame('failed', $data['status']);
        $this->assertSame(get_string('ai_error_generic', 'googlemeet'), $data['error']);
        $this->assertStringNotContainsString('API key', json_encode($data));
    }

    /**
     * Control: the teacher who acts on the failure still reads the stored text.
     */
    public function test_teacher_gets_stored_error(): void {
        $this->build('failed', self::RAW);
        $this->setUser($this->teacher);
        $this->assertSame(self::RAW, $this->call()['error']);
    }

    /**
     * No stored error stays empty for a student: the generic text is not invented.
     */
    public function test_student_without_error_gets_empty(): void {
        $this->build('processing', '');
        $this->setUser($this->student);
        $this->assertSame('', $this->call()['error']);
    }
}
