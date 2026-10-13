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

use core_external\external_api;
use core_question\local\bank\question_version_status;
use mod_googlemeet\question_service;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * The practice session returns the recording name in its plain spelling.
 *
 * practice_session.js writes it as textContent, which escapes on its own: an already escaped name
 * shows as "A &amp; B".
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(practice_attempts::class)]
#[CoversClass(\mod_googlemeet\external\get_practice_session::class)]
final class practice_session_name_test extends \advanced_testcase {
    /**
     * The WS returns "A & B < C", not "A &amp; B &lt; C"; tags are still stripped.
     */
    public function test_recordingname_is_plain_text(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $recordingid = (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeet->id,
            'recordingid' => 'drive-' . random_string(6),
            'name' => 'A & B < C <b>bold</b>',
            'createdtime' => time(),
            'duration' => '00:10:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1,
            'timemodified' => time(),
        ]);
        $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid,
            'summary' => 'Summary',
            'keypoints' => json_encode(['k']),
            'topics' => json_encode(['Tema']),
            'status' => 'completed',
            'reviewed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $questionid = (new question_service())->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, [
            'stem' => 'Question',
            'options' => ['A', 'B', 'C', 'D'],
            'correctindex' => 1,
            'explanation' => 'Because.',
            'citation' => '00:01:00',
        ]);
        $DB->set_field(
            'question_versions',
            'status',
            question_version_status::QUESTION_STATUS_READY,
            ['questionid' => $questionid]
        );

        $this->setUser($student);
        $_POST['sesskey'] = sesskey();
        $result = external_api::call_external_function('mod_googlemeet_get_practice_session', [
            'coursemoduleid' => $cm->id,
            'mode' => 'topic',
            'topic' => 'tema',
        ]);
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertCount(1, $result['data']['questions']);
        $this->assertSame('A & B < C bold', $result['data']['questions'][0]['recordingname']);
    }
}
