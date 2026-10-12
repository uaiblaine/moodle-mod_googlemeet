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

use core_question\local\bank\question_version_status;
use mod_googlemeet\local\list_aggregates;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Practice questions count once per question bank entry, and queued generation is matched per recording.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(question_service::class)]
#[CoversClass(list_aggregates::class)]
final class question_versions_test extends \advanced_testcase {
    /** @var \stdClass Activity record. */
    private $googlemeet;
    /** @var \stdClass Course module. */
    private $cm;
    /** @var \context_module Module context. */
    private $context;
    /** @var int Recording id. */
    private $recordingid;
    /** @var question_service */
    private $service;

    /**
     * One activity with one recording.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->recordingid = (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id,
            'recordingid' => 'drive-' . random_string(6),
            'name' => 'Recording',
            'createdtime' => time(),
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'transcripttext' => 'Transcript text.',
            'visible' => 1,
            'timemodified' => time(),
        ]);
        $this->service = new question_service();
    }

    /**
     * Create one draft question of the recording.
     *
     * @return int Question id (version 1).
     */
    private function create_draft(): int {
        return $this->service->create_draft_multichoice($this->googlemeet, $this->cm, $this->context, $this->recordingid, [
            'stem' => 'What was explained?',
            'options' => ['First', 'Second', 'Third', 'Fourth'],
            'correctindex' => 1,
            'explanation' => 'Because.',
            'citation' => '00:01:05',
        ]);
    }

    /**
     * Set the status of the version that holds one question id.
     *
     * @param int $questionid Question id.
     * @param string $status Version status.
     * @return void
     */
    private function set_version_status(int $questionid, string $status): void {
        global $DB;
        $DB->set_field('question_versions', 'status', $status, ['questionid' => $questionid]);
    }

    /**
     * Add a new version to the bank entry of a question, as core's question editor does.
     *
     * Core's save path always creates a new question row and version, re-applies the form's tags
     * to the new question id and leaves the old version with its tag instance.
     *
     * @param int $questionid Question id of the current version.
     * @param string $status Status of the new version.
     * @return int New question id.
     */
    private function add_version(int $questionid, string $status): int {
        global $DB;

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $version = $DB->get_record('question_versions', ['questionid' => $questionid], '*', MUST_EXIST);
        unset($question->id);
        $question->name .= ' (edited)';
        $newid = (int)$DB->insert_record('question', $question);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $version->questionbankentryid,
            'questionid' => $newid,
            'version' => (int)$version->version + 1,
            'status' => $status,
        ]);
        foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id ASC') as $answer) {
            unset($answer->id);
            $answer->question = $newid;
            $DB->insert_record('question_answers', $answer);
        }
        $tags = \core_tag_tag::get_item_tags_array('core_question', 'question', $questionid);
        \core_tag_tag::set_item_tags('core_question', 'question', $newid, $this->context, array_values($tags));
        return $newid;
    }

    /**
     * Ids returned by get_questions().
     *
     * @param bool $readyonly Only published questions.
     * @return int[]
     */
    private function listed_ids(bool $readyonly): array {
        $questions = $this->service->get_questions($this->googlemeet, $this->cm, $this->context, $this->recordingid, $readyonly);
        return array_column($questions, 'id');
    }

    /**
     * Published count of the recording from list_aggregates.
     *
     * @return int
     */
    private function published_count(): int {
        $counts = list_aggregates::published_question_counts($this->googlemeet, $this->cm, $this->context, [$this->recordingid]);
        return $counts[$this->recordingid] ?? 0;
    }

    /**
     * An edited published question is listed and counted once, as its latest version.
     */
    public function test_two_ready_versions_listed_and_counted_once(): void {
        $v1 = $this->create_draft();
        $this->set_version_status($v1, question_version_status::QUESTION_STATUS_READY);
        // Control: before the edit the single version is listed and counted.
        $this->assertSame([$v1], $this->listed_ids(true));
        $this->assertSame(1, $this->published_count());

        $v2 = $this->add_version($v1, question_version_status::QUESTION_STATUS_READY);
        // Both versions carry the recording tag, so the old query returned both.
        $this->assertNotEmpty(\core_tag_tag::is_item_tagged_with(
            'core_question',
            'question',
            $v1,
            question_service::tag_for_recording($this->recordingid)
        ));

        $this->assertSame([$v2], $this->listed_ids(false));
        $this->assertSame([$v2], $this->listed_ids(true));
        $this->assertSame(1, $this->published_count());
        $practice = $this->service->get_ready_practice_questions($this->googlemeet, $this->cm, $this->context, $this->recordingid);
        $this->assertSame([$v2], array_column($practice, 'questionid'));
    }

    /**
     * A newer draft hides the older published version: only the latest version of an entry counts.
     */
    public function test_newer_draft_supersedes_published_version(): void {
        $v1 = $this->create_draft();
        $this->set_version_status($v1, question_version_status::QUESTION_STATUS_READY);
        $v2 = $this->add_version($v1, question_version_status::QUESTION_STATUS_DRAFT);

        $this->assertSame([$v2], $this->listed_ids(false));
        $this->assertSame([], $this->listed_ids(true));
        $this->assertSame(0, $this->published_count());
        $this->assertSame(1, $this->service->count_activity_drafts($this->googlemeet, $this->cm, $this->context));
    }

    /**
     * Two draft versions of one entry are one draft to publish.
     */
    public function test_two_draft_versions_are_one_activity_draft(): void {
        $v1 = $this->create_draft();
        $v2 = $this->add_version($v1, question_version_status::QUESTION_STATUS_DRAFT);

        $this->assertSame(1, $this->service->count_activity_drafts($this->googlemeet, $this->cm, $this->context));
        $drafts = $this->service->get_activity_drafts($this->googlemeet, $this->cm, $this->context);
        $this->assertSame([$v2], array_keys($drafts[$this->recordingid]['rows']));
    }

    /**
     * A superseded version id is no longer a question of the recording; the latest one is.
     */
    public function test_require_questions_rejects_superseded_version(): void {
        $v1 = $this->create_draft();
        $this->set_version_status($v1, question_version_status::QUESTION_STATUS_READY);
        $v2 = $this->add_version($v1, question_version_status::QUESTION_STATUS_READY);

        $rows = $this->service->require_questions_for_recording(
            $this->googlemeet,
            $this->cm,
            $this->context,
            $this->recordingid,
            [$v2],
            true
        );
        $this->assertSame([$v2], array_map('intval', array_keys($rows)));

        $this->expectException(\moodle_exception::class);
        $this->service->require_questions_for_recording(
            $this->googlemeet,
            $this->cm,
            $this->context,
            $this->recordingid,
            [$v1],
            true
        );
    }

    /**
     * A queued generation of recording 12 does not mark recordings 1 or 123 as queued.
     */
    public function test_generation_queued_matches_the_exact_recording(): void {
        $this->assertFalse($this->service->is_generation_queued(12));

        $task = new task\generate_questions();
        $task->set_custom_data(['recordingid' => 12, 'cmid' => (int)$this->cm->id, 'count' => 5, 'userid' => 2]);
        \core\task\manager::queue_adhoc_task($task);

        $this->assertTrue($this->service->is_generation_queued(12));
        $this->assertFalse($this->service->is_generation_queued(1));
        $this->assertFalse($this->service->is_generation_queued(123));
    }
}
