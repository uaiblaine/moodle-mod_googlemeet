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

namespace mod_googlemeet\event;

use core_external\external_api;
use core_question\local\bank\question_version_status;
use mod_googlemeet\local\ai_review;
use mod_googlemeet\local\sync_manager;
use mod_googlemeet\question_service;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * Every teacher write of the activity logs exactly one event per affected object, and a refused write logs none.
 *
 * Each action goes through its public entry point (the web service a page calls, or the service
 * method the page and the web service share).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_external::class)]
#[CoversClass(\mod_googlemeet\external\review_ai_analysis::class)]
#[CoversClass(\mod_googlemeet\external\request_sync::class)]
#[CoversClass(ai_review::class)]
#[CoversClass(sync_manager::class)]
#[CoversClass(question_service::class)]
#[CoversClass(recording_updated::class)]
#[CoversClass(recording_trashed::class)]
#[CoversClass(recording_restored::class)]
#[CoversClass(recording_deleted::class)]
#[CoversClass(ai_analysis_updated::class)]
#[CoversClass(ai_analysis_reviewed::class)]
#[CoversClass(sync_requested::class)]
#[CoversClass(questions_changed::class)]
final class write_events_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Activity record. */
    private \stdClass $googlemeet;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /** @var \mod_googlemeet_generator Plugin generator. */
    private \mod_googlemeet_generator $generator;

    /**
     * Course, activity, editing teacher and student; the teacher acts.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $this->course->id]);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('googlemeet', $gm->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $this->setUser($this->teacher);
    }

    /**
     * Call a web service the way the AJAX layer does.
     *
     * @param string $function Web service name.
     * @param array $args Arguments.
     * @return array call_external_function() result.
     */
    private function ws(string $function, array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function($function, $args);
    }

    /**
     * Call a web service that must succeed.
     *
     * @param string $function Web service name.
     * @param array $args Arguments.
     * @return array Returned data.
     */
    private function ws_ok(string $function, array $args): array {
        $result = $this->ws($function, $args);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        return (array)$result['data'];
    }

    /**
     * The mod_googlemeet events a sink caught (core events of the question bank are left out).
     *
     * @param \phpunit_event_sink $sink Sink.
     * @return \core\event\base[]
     */
    private function plugin_events(\phpunit_event_sink $sink): array {
        return array_values(array_filter($sink->get_events(), static function ($event): bool {
            return str_starts_with(get_class($event), 'mod_googlemeet\\event\\');
        }));
    }

    /**
     * Assert that the sink caught exactly one plugin event, of the given shape.
     *
     * @param \phpunit_event_sink $sink Sink.
     * @param string $class Expected event class.
     * @param int|null $objectid Expected objectid.
     * @param string $crud Expected crud.
     * @param array $other Expected other (compared as given).
     * @return \core\event\base The event.
     */
    private function assert_one(
        \phpunit_event_sink $sink,
        string $class,
        ?int $objectid,
        string $crud,
        array $other
    ): \core\event\base {
        $events = $this->plugin_events($sink);
        $this->assertCount(1, $events, 'Plugin events: ' . implode(', ', array_map('get_class', $events)));
        $event = reset($events);
        $this->assertInstanceOf($class, $event);
        $this->assert_shape($event, $objectid, $crud, $other);
        return $event;
    }

    /**
     * Assert the common shape of one event.
     *
     * @param \core\event\base $event Event.
     * @param int|null $objectid Expected objectid.
     * @param string $crud Expected crud.
     * @param array $other Expected other.
     * @return void
     */
    private function assert_shape(\core\event\base $event, ?int $objectid, string $crud, array $other): void {
        $this->assertEquals($this->context, $event->get_context());
        $this->assertSame($objectid, $event->objectid === null ? null : (int)$event->objectid);
        $this->assertSame($crud, $event->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertSame((int)$this->teacher->id, (int)$event->userid);
        $this->assertSame($other, (array)($event->other ?? []));
    }

    /**
     * Assert that the sink caught no plugin event.
     *
     * @param \phpunit_event_sink $sink Sink.
     * @return void
     */
    private function assert_none(\phpunit_event_sink $sink): void {
        $events = $this->plugin_events($sink);
        $this->assertSame([], array_map('get_class', $events));
    }

    /**
     * A recording of the activity.
     *
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function recording(array $fields = []): \stdClass {
        return $this->generator->create_recording(['googlemeetid' => $this->googlemeet->id] + $fields);
    }

    /**
     * Rename logs recording_updated (field name); a student's refused rename logs nothing.
     */
    public function test_rename(): void {
        $recording = $this->recording();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_recording_edit_name', [
            'recordingid' => $recording->id, 'name' => 'Renamed', 'coursemoduleid' => $this->cm->id,
        ]);
        $this->assert_one($sink, recording_updated::class, (int)$recording->id, 'u', ['field' => 'name']);
        $sink->close();

        $this->setUser($this->student);
        $sink = $this->redirectEvents();
        $result = $this->ws('mod_googlemeet_recording_edit_name', [
            'recordingid' => $recording->id, 'name' => 'Student', 'coursemoduleid' => $this->cm->id,
        ]);
        $this->assertTrue($result['error']);
        $this->assert_none($sink);
        $sink->close();
    }

    /**
     * Show/hide logs recording_updated (field visible).
     */
    public function test_showhide(): void {
        global $DB;
        $recording = $this->recording();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_showhide_recording', ['recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id]);
        $this->assertEquals(0, $DB->get_field('googlemeet_recordings', 'visible', ['id' => $recording->id]));
        $this->assert_one($sink, recording_updated::class, (int)$recording->id, 'u', ['field' => 'visible']);
        $sink->close();
    }

    /**
     * Trash, restore and purge each log their own event; a refused purge logs nothing.
     */
    public function test_trash_restore_purge(): void {
        global $DB;
        $recording = $this->recording();
        $args = ['recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id];

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_trash_recording', $args);
        $this->assert_one($sink, recording_trashed::class, (int)$recording->id, 'u', ['bulk' => false]);
        $sink->close();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_restore_recording', $args);
        $this->assert_one($sink, recording_restored::class, (int)$recording->id, 'u', []);
        $sink->close();

        $this->ws_ok('mod_googlemeet_trash_recording', $args);

        $this->setUser($this->student);
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws('mod_googlemeet_purge_recording', $args)['error']);
        $this->assert_none($sink);
        $sink->close();
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $recording->id]));

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_purge_recording', $args);
        $this->assertFalse($DB->record_exists('googlemeet_recordings', ['id' => $recording->id]));
        $event = $this->assert_one($sink, recording_deleted::class, (int)$recording->id, 'd', []);
        $this->assertEquals($recording->name, $event->get_record_snapshot('googlemeet_recordings', $recording->id)->name);
        $sink->close();
    }

    /**
     * "Delete all" logs one recording_trashed (bulk) per recording it trashed, none for those already in the trash.
     */
    public function test_delete_all(): void {
        $one = $this->recording();
        $two = $this->recording();
        $this->recording(['deleted' => 1, 'timedeleted' => time()]);

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_delete_all_recordings', [
            'googlemeetid' => $this->googlemeet->id, 'coursemoduleid' => $this->cm->id,
        ]);
        $events = $this->plugin_events($sink);
        $sink->close();
        $this->assertCount(2, $events);
        $ids = [];
        foreach ($events as $event) {
            $this->assertInstanceOf(recording_trashed::class, $event);
            $this->assert_shape($event, (int)$event->objectid, 'u', ['bulk' => true]);
            $ids[] = (int)$event->objectid;
        }
        sort($ids);
        $this->assertSame([(int)$one->id, (int)$two->id], $ids);

        // Nothing left to trash: nothing logged.
        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_delete_all_recordings', [
            'googlemeetid' => $this->googlemeet->id, 'coursemoduleid' => $this->cm->id,
        ]);
        $this->assert_none($sink);
        $sink->close();
    }

    /**
     * Manual save logs ai_analysis_updated (saved), on insert and on update.
     */
    public function test_save_ai_analysis(): void {
        global $DB;
        $recording = $this->recording();
        $args = [
            'recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id,
            'summary' => 'Teacher summary', 'keypoints' => "One\nTwo", 'topics' => 'A, B', 'transcript' => '',
        ];

        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_save_ai_analysis', $args);
        $this->assert_one($sink, ai_analysis_updated::class, (int)$result['id'], 'u', [
            'action' => 'saved', 'recordingid' => (int)$recording->id,
        ]);
        $sink->close();
        $this->assertTrue($DB->record_exists('googlemeet_ai_analysis', ['id' => $result['id']]));

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_save_ai_analysis', ['summary' => 'Second'] + $args);
        $this->assert_one($sink, ai_analysis_updated::class, (int)$result['id'], 'u', [
            'action' => 'saved', 'recordingid' => (int)$recording->id,
        ]);
        $sink->close();
    }

    /**
     * A regeneration request logs ai_analysis_updated (regenerated); returning the completed analysis logs nothing.
     */
    public function test_generate_ai_analysis(): void {
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $recording = $this->recording();
        $analysis = $this->generator->create_ai_analysis(['recordingid' => $recording->id]);
        $args = ['recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id];

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_generate_ai_analysis', $args + ['regenerate' => false]);
        $this->assert_none($sink);
        $sink->close();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_generate_ai_analysis', $args + ['regenerate' => true]);
        $this->assert_one($sink, ai_analysis_updated::class, (int)$analysis->id, 'u', [
            'action' => 'regenerated', 'recordingid' => (int)$recording->id,
        ]);
        $sink->close();

        // The row is now 'processing': a second request returns it and writes nothing.
        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_generate_ai_analysis', $args + ['regenerate' => true]);
        $this->assert_none($sink);
        $sink->close();
        $this->resetDebugging();
    }

    /**
     * A pasted transcript analysed by Gemini logs ai_analysis_updated (analysed); a failed call logs nothing.
     */
    public function test_analyze_transcript(): void {
        global $DB;
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $recording = $this->recording();
        $args = ['transcript' => 'Today we study the cell cycle.', 'recordingid' => $recording->id,
            'coursemoduleid' => $this->cm->id];

        \curl::mock_response('{"error": "not json"');
        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_analyze_transcript', $args);
        $this->assertFalse($result['success']);
        $this->assert_none($sink);
        $sink->close();

        \curl::mock_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'summary' => 'The cell cycle.', 'keypoints' => ['Mitosis'], 'topics' => ['Cell cycle'], 'chapters' => [],
            'language' => 'en',
        ])]]]]]]));
        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_analyze_transcript', $args);
        $this->assertTrue($result['success']);
        $analysisid = (int)$DB->get_field('googlemeet_ai_analysis', 'id', ['recordingid' => $recording->id]);
        $this->assert_one($sink, ai_analysis_updated::class, $analysisid, 'u', [
            'action' => 'analysed', 'recordingid' => (int)$recording->id,
        ]);
        $sink->close();
        $this->resetDebugging();
    }

    /**
     * Publishing one summary or all pending ones logs ai_analysis_reviewed per analysis it published.
     */
    public function test_review_ai_analysis(): void {
        $one = $this->recording();
        $two = $this->recording();
        $three = $this->recording();
        $a1 = $this->generator->create_ai_analysis(['recordingid' => $one->id]);
        $a2 = $this->generator->create_ai_analysis(['recordingid' => $two->id]);
        $a3 = $this->generator->create_ai_analysis(['recordingid' => $three->id]);

        $this->setUser($this->student);
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws('mod_googlemeet_review_ai_analysis', [
            'coursemoduleid' => $this->cm->id, 'recordingid' => $one->id,
        ])['error']);
        $this->assert_none($sink);
        $sink->close();

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_review_ai_analysis', ['coursemoduleid' => $this->cm->id, 'recordingid' => $one->id]);
        $this->assert_one($sink, ai_analysis_reviewed::class, (int)$a1->id, 'u', [
            'all' => false, 'recordingid' => (int)$one->id,
        ]);
        $sink->close();

        // Already published: success, nothing written, nothing logged.
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws_ok('mod_googlemeet_review_ai_analysis', [
            'coursemoduleid' => $this->cm->id, 'recordingid' => $one->id,
        ])['success']);
        $this->assert_none($sink);
        $sink->close();

        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_review_ai_analysis', ['coursemoduleid' => $this->cm->id, 'recordingid' => 0]);
        $this->assertSame(2, $result['count']);
        $events = $this->plugin_events($sink);
        $sink->close();
        $this->assertCount(2, $events);
        $byid = [];
        foreach ($events as $event) {
            $this->assertInstanceOf(ai_analysis_reviewed::class, $event);
            $byid[(int)$event->objectid] = $event;
        }
        ksort($byid);
        $this->assertSame([(int)$a2->id, (int)$a3->id], array_keys($byid));
        $this->assert_shape($byid[(int)$a2->id], (int)$a2->id, 'u', ['all' => true, 'recordingid' => (int)$two->id]);
        $this->assert_shape($byid[(int)$a3->id], (int)$a3->id, 'u', ['all' => true, 'recordingid' => (int)$three->id]);
    }

    /**
     * A queued manual sync logs sync_requested; a request refused because one is pending logs nothing.
     */
    public function test_request_sync(): void {
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws_ok('mod_googlemeet_request_sync', ['coursemoduleid' => $this->cm->id])['queued']);
        $this->assert_one($sink, sync_requested::class, null, 'c', []);
        $sink->close();

        $sink = $this->redirectEvents();
        $this->assertFalse(sync_manager::request($this->googlemeet, (int)$this->teacher->id));
        $this->assert_none($sink);
        $sink->close();

        $this->setUser($this->student);
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws('mod_googlemeet_request_sync', ['coursemoduleid' => $this->cm->id])['error']);
        $this->assert_none($sink);
        $sink->close();
    }

    /**
     * Create draft questions for a recording (as admin, before any sink).
     *
     * @param int $recordingid Recording id.
     * @param int $count Number of drafts.
     * @return int[] Question ids.
     */
    private function drafts(int $recordingid, int $count): array {
        $this->setAdminUser();
        $service = new question_service();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $service->create_draft_multichoice($this->googlemeet, $this->cm, $this->context, $recordingid, [
                'stem' => 'Which law regulates the national health system? ' . $i,
                'options' => ['Law 14/1986', 'Law 16/2003', 'Law 41/2002', 'Law 55/2003'],
                'correctindex' => 0,
                'explanation' => 'It is the general health law.',
                'citation' => '00:01:05',
            ]);
        }
        $this->setUser($this->teacher);
        return $ids;
    }

    /**
     * Publish, unpublish, update and discard each log one questions_changed for the recording, with the count.
     */
    public function test_question_actions(): void {
        $recording = $this->recording();
        $ids = $this->drafts((int)$recording->id, 3);
        $base = ['recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id, 'sesskey' => sesskey()];

        $this->setUser($this->student);
        $base['sesskey'] = sesskey();
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws('mod_googlemeet_publish_questions', $base + ['questionids' => $ids])['error']);
        $this->assert_none($sink);
        $sink->close();

        $this->setUser($this->teacher);
        $base['sesskey'] = sesskey();
        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_publish_questions', $base + ['questionids' => [$ids[0], $ids[1]]]);
        $this->assert_one($sink, questions_changed::class, (int)$recording->id, 'u', ['action' => 'published', 'count' => 2]);
        $sink->close();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_unpublish_questions', $base + ['questionids' => [$ids[1]]]);
        $this->assert_one($sink, questions_changed::class, (int)$recording->id, 'u', ['action' => 'unpublished', 'count' => 1]);
        $sink->close();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_update_question', $base + [
            'questionid' => $ids[2], 'stem' => 'Edited stem', 'options' => ['A', 'B', 'C', 'D'], 'correctindex' => 1,
            'explanation' => 'Because.', 'citation' => '',
        ]);
        $this->assert_one($sink, questions_changed::class, (int)$recording->id, 'u', ['action' => 'updated', 'count' => 1]);
        $sink->close();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_discard_questions', $base + ['questionids' => [$ids[1], $ids[2]]]);
        $this->assert_one($sink, questions_changed::class, (int)$recording->id, 'u', ['action' => 'discarded', 'count' => 2]);
        $sink->close();

        // A discard refused because a question is published logs nothing.
        $sink = $this->redirectEvents();
        $this->assertTrue($this->ws('mod_googlemeet_discard_questions', $base + ['questionids' => [$ids[0]]])['error']);
        $this->assert_none($sink);
        $sink->close();
        $this->resetDebugging();
    }

    /**
     * Publishing every draft of the activity logs one questions_changed per recording batch it published.
     */
    public function test_publish_activity_drafts(): void {
        $one = $this->recording();
        $two = $this->recording();
        $this->drafts((int)$one->id, 2);
        $this->drafts((int)$two->id, 1);

        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_publish_activity_drafts', [
            'coursemoduleid' => $this->cm->id, 'sesskey' => sesskey(),
        ]);
        $this->assertSame(3, $result['published']);
        $events = $this->plugin_events($sink);
        $sink->close();
        $this->assertCount(2, $events);
        $byid = [];
        foreach ($events as $event) {
            $this->assertInstanceOf(questions_changed::class, $event);
            $byid[(int)$event->objectid] = $event;
        }
        $this->assert_shape($byid[(int)$one->id], (int)$one->id, 'u', ['action' => 'published', 'count' => 2]);
        $this->assert_shape($byid[(int)$two->id], (int)$two->id, 'u', ['action' => 'published', 'count' => 1]);

        // No drafts left: nothing logged.
        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_publish_activity_drafts', ['coursemoduleid' => $this->cm->id, 'sesskey' => sesskey()]);
        $this->assert_none($sink);
        $sink->close();
        $this->resetDebugging();
    }

    /**
     * Queueing question generation logs questions_changed (queued) with the clamped count.
     */
    public function test_queue_generate_questions(): void {
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $recording = $this->recording();

        $sink = $this->redirectEvents();
        $this->ws_ok('mod_googlemeet_queue_generate_questions', [
            'recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id, 'count' => 50, 'sesskey' => sesskey(),
        ]);
        $this->assert_one($sink, questions_changed::class, (int)$recording->id, 'u', ['action' => 'queued', 'count' => 20]);
        $sink->close();
    }
}
