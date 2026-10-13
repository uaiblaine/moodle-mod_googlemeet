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

use mod_googlemeet\local\recording_format;
use mod_googlemeet\output\recording_hub;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Recording hub UI rules: which tabs are shown to whom.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_hub::class)]
final class hub_ui_test extends \advanced_testcase {
    /**
     * Render the hub for the current user.
     *
     * @param \stdClass $googlemeet Activity row.
     * @param \stdClass $recording Recording row.
     * @return string HTML.
     */
    private function render_hub(\stdClass $googlemeet, \stdClass $recording): string {
        global $PAGE;
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $googlemeet->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'recording' => $recording->id]);
        $PAGE->set_cm($cm);
        $PAGE->set_context($context);
        ob_start();
        recording_hub::print_recording_hub($googlemeet, $cm, $context, $recording);
        return ob_get_clean();
    }

    /**
     * Students only see the Questions tab when there are published questions; teachers always do.
     * The Meet notes tab only appears when the recording has notes.
     */
    public function test_questions_and_notes_tabs(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();
        $insert = function (string $notes) use ($DB, $googlemeet, $now) {
            $id = $DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $googlemeet->id, 'recordingid' => uniqid('drive-', true), 'name' => 'Clase',
                'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
                'visible' => 1, 'timemodified' => $now, 'notestext' => $notes,
            ]);
            return $DB->get_record('googlemeet_recordings', ['id' => $id]);
        };
        $plain = $insert('');
        $withnotes = $insert('<p>Notas de Gemini</p>');

        $this->setUser($student);
        $html = $this->render_hub($googlemeet, $plain);
        $this->assertStringNotContainsString('id="googlemeet-questions-tab"', $html);
        $this->assertStringNotContainsString('id="googlemeet-questions-panel"', $html);
        $this->assertStringNotContainsString('id="googlemeet-notes-tab"', $html);
        $html = $this->render_hub($googlemeet, $withnotes);
        $this->assertStringContainsString('id="googlemeet-notes-tab"', $html);
        $this->assertStringContainsString(get_string('hub_notes_origin', 'googlemeet'), $html);

        $this->setUser($teacher);
        $html = $this->render_hub($googlemeet, $plain);
        $this->assertStringContainsString('id="googlemeet-questions-tab"', $html);
    }

    /**
     * The "Generate questions" button follows the web service's gate: managequestions alone, which
     * non-editing teachers hold, does not show it; generateai does.
     */
    public function test_generate_questions_button_needs_generateai(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $editor = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $now = time();
        $id = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeet->id, 'recordingid' => uniqid('drive-', true), 'name' => 'Clase',
            'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1, 'timemodified' => $now,
        ]);
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $id]);

        $this->setUser($teacher);
        $html = $this->render_hub($googlemeet, $recording);
        $this->assertStringContainsString('id="googlemeet-questions-tab"', $html);
        $this->assertStringNotContainsString('googlemeet-question-generate', $html);

        // Control: the editing teacher holds generateai and gets the button.
        $this->setUser($editor);
        $html = $this->render_hub($googlemeet, $recording);
        $this->assertStringContainsString('googlemeet-question-generate', $html);
    }

    /**
     * The continue label names the chapter when the remembered second is a chapter start.
     */
    public function test_resume_label(): void {
        $chapters = recording_format::normalise_chapters(json_encode([
            ['title' => 'Introducción', 'start' => '4:01'],
            ['title' => 'Derechos de los ciudadanos', 'start' => '14:02'],
        ]));
        $this->assertSame(get_string('hub_resume_chapter', 'googlemeet', (object)[
            'title' => 'Derechos de los ciudadanos', 'time' => recording_format::format_seconds_timestamp(842),
        ]), recording_hub::hub_resume_label(842, $chapters));
        $this->assertSame(
            get_string('hub_resume_time', 'googlemeet', recording_format::format_seconds_timestamp(900)),
            recording_hub::hub_resume_label(900, $chapters)
        );
        $this->assertSame(
            get_string('hub_resume_time', 'googlemeet', recording_format::format_seconds_timestamp(842)),
            recording_hub::hub_resume_label(842, [])
        );
    }
}
