<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_googlemeet;

use mod_googlemeet\local\recordings;
use mod_googlemeet\output\recording_hub;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/mod_form.php');

/**
 * Nullable JSON columns (googlemeet.days, the analysis keypoints and topics) decode without a PHP deprecation.
 *
 * PHPUnit runs with failOnDeprecation, so each test fails on the "Passing null to parameter #1 ($json)"
 * notice the unguarded json_decode() raises (the form test collects it itself); each one first asserts
 * the column really is NULL.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_mod_form::class)]
#[CoversClass(ai_service::class)]
#[CoversClass(recordings::class)]
#[CoversClass(recording_hub::class)]
final class nullable_json_columns_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \context_module */
    private $context;

    /**
     * Course and an activity without recurrence, as admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Insert a recording, with an analysis row when $analysis is given.
     *
     * @param array|null $analysis Analysis overrides; null for none.
     * @return \stdClass Recording row.
     */
    private function create_recording(?array $analysis): \stdClass {
        global $DB;
        $now = time();
        $id = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id, 'recordingid' => uniqid('drive-', true), 'name' => 'Clase',
            'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1, 'timemodified' => $now,
        ]);
        if ($analysis !== null) {
            $DB->insert_record('googlemeet_ai_analysis', (object)array_merge([
                'recordingid' => $id, 'summary' => 'Resumen sin puntos clave', 'keypoints' => null, 'topics' => null,
                'status' => 'completed', 'reviewed' => 1, 'retrycount' => 0, 'nextretry' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ], $analysis));
        }
        return $DB->get_record('googlemeet_recordings', ['id' => $id]);
    }

    /**
     * Run the edit form's data_preprocessing() over the stored activity row.
     *
     * @return array The form defaults.
     */
    private function form_defaults(): array {
        global $DB, $PAGE;
        // Each form sets the theme up, after which the page refuses another course.
        $PAGE = new \moodle_page();
        $PAGE->set_course($this->course);
        $current = $DB->get_record('googlemeet', ['id' => $this->googlemeet->id]);
        $current->instance = $current->id;
        $current->coursemodule = $this->cm->id;
        $current->section = 0;
        $current->modulename = 'googlemeet';
        $current->update = $this->cm->id;
        $form = new \mod_googlemeet_mod_form($current, 0, $this->cm, $this->course);
        $defaults = (array)$DB->get_record('googlemeet', ['id' => $this->googlemeet->id]);
        // The file is outside the PHPUnit source list, whose deprecations PHPUnit does not report: collect them here.
        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return true;
        }, E_DEPRECATED);
        try {
            $form->data_preprocessing($defaults);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $deprecations);
        return $defaults;
    }

    /**
     * Editing an activity saved without recurrence (days stored as NULL) loads no weekdays.
     */
    public function test_edit_form_without_recurrence(): void {
        global $DB;
        $this->assertNull($DB->get_field('googlemeet', 'days', ['id' => $this->googlemeet->id]));
        $this->assertNull($this->form_defaults()['days']);

        // Control: stored weekdays still reach the form.
        $DB->set_field('googlemeet', 'days', '{"Mon":"1"}', ['id' => $this->googlemeet->id]);
        $this->assertSame(['Mon' => '1'], $this->form_defaults()['days']);
    }

    /**
     * The row generate_analysis() inserts while the task runs holds NULL keypoints and topics;
     * reading it back (the get_ai_analysis web service does) yields empty lists.
     */
    public function test_get_analysis_of_a_processing_row(): void {
        global $DB;
        $recording = $this->create_recording(null);
        (new ai_service())->generate_analysis((int)$recording->id);
        $row = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id], '*', MUST_EXIST);
        $this->assertSame('processing', $row->status);
        $this->assertNull($row->keypoints);
        $this->assertNull($row->topics);

        $analysis = (new ai_service())->get_analysis((int)$recording->id);
        $this->assertSame([], $analysis->keypoints);
        $this->assertSame([], $analysis->topics);
    }

    /**
     * A completed analysis with NULL keypoints and topics is returned as is by generate_analysis().
     */
    public function test_generate_analysis_returns_completed_row_with_null_lists(): void {
        $recording = $this->create_recording([]);
        $analysis = (new ai_service())->generate_analysis((int)$recording->id);
        $this->assertSame('completed', $analysis->status);
        $this->assertSame([], $analysis->keypoints);
        $this->assertSame([], $analysis->topics);
    }

    /**
     * The recordings list shows a summary-only analysis with empty key points and topics.
     */
    public function test_list_with_summary_only_analysis(): void {
        $this->create_recording([]);
        $list = recordings::list_recordings(['googlemeetid' => $this->googlemeet->id], true);
        $this->assertCount(1, $list);
        $item = reset($list);
        $this->assertTrue($item->hasai);
        $this->assertSame([], $item->aikeypoints);
        $this->assertSame([], $item->aitopics);
    }

    /**
     * The recording hub renders a summary-only analysis.
     */
    public function test_hub_with_summary_only_analysis(): void {
        global $PAGE;
        $recording = $this->create_recording([]);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $this->cm->id, 'recording' => $recording->id]);
        $PAGE->set_cm($this->cm);
        $PAGE->set_context($this->context);
        ob_start();
        recording_hub::print_recording_hub($this->googlemeet, $this->cm, $this->context, $recording);
        $html = ob_get_clean();
        $this->assertStringContainsString('Resumen sin puntos clave', $html);
    }
}
