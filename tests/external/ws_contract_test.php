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
 * Contracts of the legacy web services: no sesskey argument, typed returns, the paid-AI capability.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_external::class)]
final class ws_contract_test extends \advanced_testcase {
    /** @var \stdClass Course module. */
    private $cm;
    /** @var \context_module Module context. */
    private $context;
    /** @var \stdClass Editing teacher. */
    private $teacher;
    /** @var \mod_googlemeet_generator Plugin generator. */
    private $generator;

    /**
     * Course, activity and an editing teacher, who is the current user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $activity->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $this->setUser($this->teacher);
    }

    /**
     * Call a web service the way the AJAX layer does: the session key travels in the request.
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
     * A question action works without a sesskey argument (core checks the request), and one sent as an argument is refused.
     */
    public function test_question_action_takes_no_sesskey_argument(): void {
        $args = ['coursemoduleid' => $this->cm->id];

        $result = $this->ws('mod_googlemeet_publish_activity_drafts', $args);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        $this->assertTrue($result['data']['success']);
        $this->assertSame(0, $result['data']['published']);

        $result = $this->ws('mod_googlemeet_publish_activity_drafts', $args + ['sesskey' => sesskey()]);
        $this->assertTrue($result['error']);
        $this->assertSame('invalidparameter', $result['exception']->errorcode);
    }

    /**
     * The functions that no longer take a sesskey argument rely on core's AJAX session-key check, so
     * they must stay AJAX-only: never in the mobile service, where a token call has no session.
     */
    public function test_sesskey_free_functions_stay_ajax_only(): void {
        global $CFG;
        $functions = [];
        include($CFG->dirroot . '/mod/googlemeet/db/services.php');
        $names = [
            'publish_questions', 'unpublish_questions', 'discard_questions', 'publish_activity_drafts',
            'update_question', 'queue_generate_questions',
        ];
        foreach ($names as $name) {
            $definition = $functions['mod_googlemeet_' . $name];
            $this->assertTrue($definition['ajax'], $name);
            $this->assertNotContains(MOODLE_OFFICIAL_MOBILE_SERVICE, $definition['services'] ?? [], $name);
        }
    }

    /**
     * Renaming returns PARAM_TEXT values even when deriving the display name leaves a bare '<'.
     */
    public function test_recording_edit_name_returns_plain_text(): void {
        $recording = $this->generator->create_recording(['googlemeetid' => $this->cm->instance]);

        $result = $this->ws('mod_googlemeet_recording_edit_name', [
            'recordingid' => $recording->id,
            'name' => 'Clase < .mp4',
            'coursemoduleid' => $this->cm->id,
        ]);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        $this->assertSame('Clase < .mp4', $result['data']['name']);
        $this->assertSame(
            clean_param($result['data']['displayname'], PARAM_TEXT),
            $result['data']['displayname']
        );
        $this->assertStringStartsWith('Clase', $result['data']['displayname']);
    }

    /**
     * Toggling visibility returns a boolean, both ways.
     */
    public function test_showhide_recording_returns_bool(): void {
        $recording = $this->generator->create_recording(['googlemeetid' => $this->cm->instance, 'visible' => 1]);
        $args = ['recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id];

        $result = $this->ws('mod_googlemeet_showhide_recording', $args);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        $this->assertFalse($result['data']['visible']);

        $result = $this->ws('mod_googlemeet_showhide_recording', $args);
        $this->assertTrue($result['data']['visible']);
    }

    /**
     * Analysing a pasted transcript calls Gemini, so it needs generateai on top of editrecording.
     */
    public function test_analyze_transcript_requires_generateai(): void {
        global $DB;
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $recording = $this->generator->create_recording(['googlemeetid' => $this->cm->instance]);
        $args = ['transcript' => 'Today we study the cell cycle.', 'recordingid' => $recording->id,
            'coursemoduleid' => $this->cm->id];
        \curl::mock_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'summary' => 'The cell cycle.', 'keypoints' => ['Mitosis'], 'topics' => ['Cell cycle'], 'chapters' => [],
            'language' => 'en',
        ])]]]]]]));

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/googlemeet:generateai', CAP_PROHIBIT, $roleid, $this->context->id, true);
        $this->assertTrue(has_capability('mod/googlemeet:editrecording', $this->context));

        $result = $this->ws('mod_googlemeet_analyze_transcript', $args);
        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
        $this->assertFalse($DB->record_exists('googlemeet_ai_analysis', ['recordingid' => $recording->id]));

        // Control: with the capability back, the same call reaches Gemini and stores the analysis.
        unassign_capability('mod/googlemeet:generateai', $roleid, $this->context->id);
        $result = $this->ws('mod_googlemeet_analyze_transcript', $args);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        $this->assertTrue($result['data']['success']);
        $this->assertTrue($DB->record_exists('googlemeet_ai_analysis', ['recordingid' => $recording->id]));
        // The Gemini client reports a completed analysis through debugging().
        $this->resetDebugging();
    }

    /**
     * Queueing question generation is a paid Gemini call: it needs generateai on top of
     * managequestions, which non-editing teachers hold by default.
     */
    public function test_queue_generate_questions_requires_generateai(): void {
        global $DB;
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $recording = $this->generator->create_recording(['googlemeetid' => $this->cm->instance]);
        $args = ['recordingid' => $recording->id, 'coursemoduleid' => $this->cm->id, 'count' => 5];

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/googlemeet:generateai', CAP_PROHIBIT, $roleid, $this->context->id, true);
        $this->assertTrue(has_capability('mod/googlemeet:managequestions', $this->context));

        $result = $this->ws('mod_googlemeet_queue_generate_questions', $args);
        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks('\\mod_googlemeet\\task\\generate_questions'));

        // Control: with the capability back, the same call queues the generation.
        unassign_capability('mod/googlemeet:generateai', $roleid, $this->context->id);
        $result = $this->ws('mod_googlemeet_queue_generate_questions', $args);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\mod_googlemeet\\task\\generate_questions'));
    }
}
