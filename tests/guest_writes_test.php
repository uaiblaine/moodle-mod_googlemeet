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

use core_external\external_api;
use core_question\local\bank\question_version_status;
use mod_googlemeet\external\log_room_entered;
use mod_googlemeet\local\practice_attempts;
use mod_googlemeet\local\room_entry;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * The guest user views the activity but writes nothing under its one shared account.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_external::class)]
#[CoversClass(log_room_entered::class)]
#[CoversClass(room_entry::class)]
final class guest_writes_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \context_module */
    private $context;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass Visible recording. */
    private $recording;
    /** @var int Published practice question of the recording. */
    private $questionid;

    /**
     * Course open to guests, an activity with a recording and a published question, and a student.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $this->course->id, 'enrol' => 'guest']);
        $gm = $gen->create_module('googlemeet', ['course' => $this->course->id]);
        $DB->set_field('googlemeet', 'url', 'https://meet.google.com/abc-defg-hij', ['id' => $gm->id]);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $gm->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $this->recording = $gen->get_plugin_generator('mod_googlemeet')->create_recording([
            'googlemeetid' => $gm->id,
            'duration' => '01:00:00',
        ]);

        $this->questionid = (new question_service())->create_draft_multichoice(
            $this->googlemeet,
            $this->cm,
            $this->context,
            (int)$this->recording->id,
            ['stem' => 'Question', 'options' => ['A', 'B', 'C', 'D'], 'correctindex' => 1,
                'explanation' => 'Because.', 'citation' => '00:01:00']
        );
        $DB->set_field(
            'question_versions',
            'status',
            question_version_status::QUESTION_STATUS_READY,
            ['questionid' => $this->questionid]
        );
    }

    /**
     * Call a web service as the current user.
     *
     * @param string $function Function name.
     * @param array $args Arguments.
     * @return array Result of call_external_function().
     */
    private function call(string $function, array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function($function, $args);
    }

    /**
     * Call every write as the current user and return the errorcode of each ('' when it succeeded).
     *
     * @return string[] function => errorcode
     */
    private function call_writes(): array {
        global $DB;
        $answerid = (int)array_key_first($DB->get_records('question_answers', ['question' => $this->questionid], 'id ASC', 'id'));
        $calls = [
            'mod_googlemeet_mark_recording_progress' => ['recordingid' => $this->recording->id,
                'coursemoduleid' => $this->cm->id, 'watchedsecondsdelta' => 30, 'completed' => true],
            'mod_googlemeet_check_practice_answer' => ['recordingid' => $this->recording->id,
                'coursemoduleid' => $this->cm->id, 'questionid' => $this->questionid, 'answerid' => $answerid],
            'mod_googlemeet_log_room_entered' => ['coursemoduleid' => $this->cm->id, 'via' => 'mobile'],
        ];
        $out = [];
        foreach ($calls as $function => $args) {
            $result = $this->call($function, $args);
            $out[$function] = $result['error'] ? $result['exception']->errorcode : '';
        }
        return $out;
    }

    /**
     * Rows and events written under a user.
     *
     * @param int $userid User id.
     * @param array $events Events triggered meanwhile.
     * @return int[] progress, attempts, entries
     */
    private function writes_of(int $userid, array $events): array {
        global $DB;
        $entries = array_filter($events, static fn($e) => $e instanceof \mod_googlemeet\event\room_entered
            && (int)$e->userid === $userid);
        return [
            $DB->count_records('googlemeet_recording_progress', ['userid' => $userid]),
            $DB->count_records(practice_attempts::TABLE, ['userid' => $userid]),
            count($entries),
        ];
    }

    /**
     * The guest is refused by the three write services and nothing is stored under the guest account;
     * the same calls from a student succeed and write one row or event each.
     */
    public function test_guest_cannot_write_through_web_services(): void {
        $guestid = (int)guest_user()->id;
        $this->setGuestUser();
        // Precondition: the guest may view the activity, so the refusal is not require_login's.
        $this->assertTrue(has_capability('mod/googlemeet:view', $this->context));
        $sink = $this->redirectEvents();
        $codes = $this->call_writes();
        $events = $sink->get_events();
        $sink->close();
        $this->assertSame(array_fill_keys(array_keys($codes), 'guestsarenotallowed'), $codes);
        $this->assertSame([0, 0, 0], $this->writes_of($guestid, $events));

        $this->setUser($this->student);
        $sink = $this->redirectEvents();
        $codes = $this->call_writes();
        $events = $sink->get_events();
        $sink->close();
        $this->assertSame(array_fill_keys(array_keys($codes), ''), $codes);
        $this->assertSame([1, 1, 1], $this->writes_of((int)$this->student->id, $events));
    }

    /**
     * enter.php still sends a guest to the room but logs no entry for them; a student's entry is logged.
     */
    public function test_enter_page_does_not_log_guests(): void {
        $this->setGuestUser();
        $sink = $this->redirectEvents();
        $this->assertSame('redirecterrordetected', $this->run_enter());
        $this->assertSame([0, 0, 0], $this->writes_of((int)guest_user()->id, $sink->get_events()));
        $sink->close();

        $this->setUser($this->student);
        $sink = $this->redirectEvents();
        $this->assertSame('redirecterrordetected', $this->run_enter());
        $this->assertSame([0, 0, 1], $this->writes_of((int)$this->student->id, $sink->get_events()));
        $sink->close();
    }

    /**
     * room_entry::log() itself logs nothing for a guest.
     */
    public function test_room_entry_log_skips_guests(): void {
        $this->setGuestUser();
        $sink = $this->redirectEvents();
        $this->assertSame(0, room_entry::log($this->googlemeet, $this->cm, $this->context, 'web'));
        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    /**
     * Request enter.php with the activity's own sesskey and return the errorcode it ended with.
     *
     * @return string 'redirecterrordetected' when it redirected (to the room).
     */
    private function run_enter(): string {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER, $COURSE;

        $PAGE = new \moodle_page();
        $_GET = ['id' => $this->cm->id, 'sesskey' => sesskey()];
        try {
            require($CFG->dirroot . '/mod/googlemeet/enter.php');
            return '';
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        } finally {
            $_GET = [];
        }
    }
}
