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

use core_question\local\bank\question_version_status;
use mod_googlemeet\question_service;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Practice counts read only the latest version of each question, as the session does.
 *
 * Core's question editor saves an edit as a new question and version and leaves the old version
 * tagged with the recording, see {@see question_service::latest_version_sql()}.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(practice_attempts::class)]
final class practice_latest_version_test extends \advanced_testcase {
    /**
     * A published question edited once (two ready versions) is counted and offered once.
     */
    public function test_edited_question_is_counted_once(): void {
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
        $recording = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_recording([
            'googlemeetid' => $googlemeet->id,
            'visible' => 1,
        ]);
        $recordingid = (int)$recording->id;
        $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_ai_analysis([
            'recordingid' => $recordingid,
            'topics' => json_encode(['Tema']),
            'reviewed' => 1,
        ]);
        $questionid = (new question_service())->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, [
            'stem' => 'Question',
            'options' => ['A', 'B', 'C', 'D'],
            'correctindex' => 1,
            'explanation' => 'Because.',
            'citation' => '00:01:00',
        ]);
        $ready = question_version_status::QUESTION_STATUS_READY;
        $DB->set_field('question_versions', 'status', $ready, ['questionid' => $questionid]);

        // Control: one version, one question.
        $this->assertSame([$recordingid => 1], practice_attempts::count_ready_questions_by_recording($googlemeet, $cm, $context));

        // Edit it as core's question editor does: new question row and version, tags copied to it.
        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $version = $DB->get_record('question_versions', ['questionid' => $questionid], '*', MUST_EXIST);
        unset($question->id);
        $newid = (int)$DB->insert_record('question', $question);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $version->questionbankentryid,
            'questionid' => $newid,
            'version' => (int)$version->version + 1,
            'status' => $ready,
        ]);
        foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id ASC') as $answer) {
            unset($answer->id);
            $answer->question = $newid;
            $DB->insert_record('question_answers', $answer);
        }
        $tags = \core_tag_tag::get_item_tags_array('core_question', 'question', $questionid);
        \core_tag_tag::set_item_tags('core_question', 'question', $newid, $context, array_values($tags));

        $this->assertSame([$recordingid => 1], practice_attempts::count_ready_questions_by_recording($googlemeet, $cm, $context));
        $topics = practice_attempts::get_practice_topics($googlemeet, $cm, $context);
        $this->assertSame(1, $topics[0]['questions']);
        $session = practice_attempts::get_session_questions(
            $googlemeet,
            $cm,
            $context,
            (int)get_admin()->id,
            practice_attempts::MODE_TOPIC,
            'Tema'
        );
        $this->assertSame([$newid], array_column($session, 'questionid'));
    }
}
