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

namespace mod_googlemeet\output;

use mod_googlemeet\local\materials;
use PHPUnit\Framework\Attributes\CoversClass;


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Markup of the page fragments rendered through templates instead of html_writer.
 *
 * Pins the attachment list, the activity index table, the "Event details" button on view.php
 * and the "Back" link on material.php, which must keep the HTML html_writer used to print:
 * element types, classes, URLs and text escaped exactly once.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(materials::class)]
final class page_markup_test extends \advanced_testcase {
    /**
     * Run a plugin page script as the current user and return its output.
     *
     * @param string $script Script name under mod/googlemeet.
     * @param array $get Request parameters.
     * @return string The page HTML.
     */
    private function render_page(string $script, array $get): string {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER, $COURSE;

        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        $_GET = $get;
        ob_start();
        try {
            require($CFG->dirroot . '/mod/googlemeet/' . $script);
        } finally {
            $html = ob_get_clean();
            $_GET = [];
        }
        return $html;
    }

    /**
     * Render the attachment list of an activity with the web renderer.
     *
     * @param \cm_info|\stdClass $cm Course module.
     * @return string HTML.
     */
    private function render_attachments($cm): string {
        global $OUTPUT, $PAGE;
        $context = \context_module::instance($cm->id);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
        $PAGE->set_cm($cm);
        $PAGE->set_context($context);
        $OUTPUT = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
        ob_start();
        materials::print_attachments($context);
        return ob_get_clean();
    }

    /**
     * Nothing is printed without attachments; with them, a download list with the file icon,
     * the plain file name and the size.
     */
    public function test_attachments_list(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $this->setUser($student);

        $this->assertSame('', $this->render_attachments($cm));

        $record = ['contextid' => $context->id, 'component' => 'mod_googlemeet', 'filearea' => 'attachment',
            'itemid' => 0, 'filepath' => '/'];
        $fs = get_file_storage();
        $fs->create_file_from_string($record + ['filename' => 'report.pdf'], str_repeat('x', 2048));
        $fs->create_file_from_string($record + ['filename' => 'Aula 1 – notas.txt'], 'hello');
        $html = $this->render_attachments($cm);

        $this->assertStringStartsWith('<div class="googlemeet-attachments mt-4">', $html);
        $this->assertStringContainsString(
            '<h4>' . get_string('attachmentsheader', 'googlemeet') . '</h4>',
            $html
        );
        $this->assertStringContainsString('<ul class="list-unstyled mb-0">', $html);
        $this->assertSame(2, substr_count($html, '<li class="mb-1">'));
        $pdfurl = \moodle_url::make_pluginfile_url($context->id, 'mod_googlemeet', 'attachment', 0, '/', 'report.pdf', true);
        $this->assertStringContainsString('<a href="' . $pdfurl->out() . '"><img class="icon me-2"', $html);
        $this->assertStringContainsString('f/pdf', $html);
        $this->assertStringContainsString(
            '/>report.pdf<span class="text-muted small"> (' . display_size(2048) . ')</span></a>',
            $html
        );
        $this->assertStringContainsString(
            '/>Aula 1 – notas.txt<span class="text-muted small"> (' . display_size(5) . ')</span></a>',
            $html
        );
    }

    /**
     * The index table keeps the html_table markup: header and cell classes, the dimmed link of
     * a hidden activity, the last-row class and, for students only, the progress column.
     */
    public function test_index_table(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $live = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id, 'name' => 'Live & class']);
        $hidden = $this->getDataGenerator()->create_module(
            'googlemeet',
            ['course' => $course->id, 'name' => 'Second', 'visible' => 0]
        );
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();
        $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $live->id, 'recordingid' => 'drive-1', 'name' => 'Clase 1',
            'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1, 'timemodified' => $now,
        ]);
        $liveurl = (new \moodle_url('/mod/googlemeet/view.php', ['id' => $live->cmid]))->out();
        $hiddenurl = (new \moodle_url('/mod/googlemeet/view.php', ['id' => $hidden->cmid]))->out();

        $this->setUser($teacher);
        $html = $this->render_page('index.php', ['id' => $course->id]);
        $this->assertStringContainsString('<div class="table-responsive">', $html);
        $this->assertStringContainsString('<table class="generaltable mod_index">', $html);
        $this->assertStringContainsString(
            '<th class="header c0" scope="col">' . get_string('sectionname', 'format_topics') . '</th>',
            $html
        );
        $this->assertStringContainsString('<th class="header c1" scope="col">' . get_string('name') . '</th>', $html);
        $this->assertStringContainsString(
            '<th class="header c3 lastcol" scope="col">' . get_string('index_recordings', 'googlemeet') . '</th>',
            $html
        );
        $this->assertStringNotContainsString(get_string('index_myprogress', 'googlemeet'), $html);
        $this->assertStringContainsString('<td class="cell c1"><a href="' . $liveurl . '">Live &amp; class</a></td>', $html);
        $this->assertStringContainsString('<td class="cell c1"><a class="dimmed" href="' . $hiddenurl . '">Second</a></td>', $html);
        $this->assertStringContainsString('<td class="cell c3 lastcol">1</td>', $html);
        $this->assertSame(1, substr_count($html, '<tr class="lastrow">'));
        $this->assertSame(3, substr_count($html, '<tr'));

        $this->setUser($student);
        $html = $this->render_page('index.php', ['id' => $course->id]);
        $this->assertStringContainsString('<th class="header c3" scope="col">', $html);
        $this->assertStringContainsString(
            '<th class="header c4 lastcol" scope="col">' . get_string('index_myprogress', 'googlemeet') . '</th>',
            $html
        );
        $progress = get_string('index_myprogress_value', 'googlemeet', (object)['watched' => 0, 'total' => 1, 'pct' => 0]);
        $this->assertStringContainsString('<td class="cell c4 lastcol">' . s($progress) . '</td>', $html);
        $this->assertStringNotContainsString($hiddenurl, $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Only a teacher of an activity with a Google Calendar event sees the "Event details" button.
     */
    public function test_view_event_details_link(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $label = get_string('eventdetails', 'googlemeet');
        $link = '<a class="btn btn-outline-primary ms-2" target="_blank" title="' . $label . '"'
            . ' href="https://calendar.google.com/calendar/u/0/r/eventedit/evt123abc">' . $label . '</a>';

        // The control: without an event id, the teacher sees no button either.
        $DB->set_field('googlemeet', 'eventid', null, ['id' => $googlemeet->id]);
        $this->setUser($teacher);
        $this->assertStringNotContainsString('eventedit', $this->render_page('view.php', ['id' => $googlemeet->cmid]));

        $DB->set_field('googlemeet', 'eventid', 'evt123abc', ['id' => $googlemeet->id]);
        $this->assertStringContainsString($link, $this->render_page('view.php', ['id' => $googlemeet->cmid]));

        $this->setUser($student);
        $this->assertStringNotContainsString('eventedit', $this->render_page('view.php', ['id' => $googlemeet->cmid]));
    }

    /**
     * The materials form starts with a "Back" link to the recording's Materials tab.
     */
    public function test_material_back_link(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $now = time();
        $recordingid = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeet->id, 'recordingid' => 'drive-1', 'name' => 'Clase 1',
            'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1, 'timemodified' => $now,
        ]);
        $this->setUser($teacher);

        $html = $this->render_page('material.php', ['id' => $googlemeet->cmid, 'recording' => $recordingid]);

        $backurl = new \moodle_url(
            '/mod/googlemeet/view.php',
            ['id' => $googlemeet->cmid, 'recording' => $recordingid, 'tab' => 'materials']
        );
        $this->assertStringContainsString(
            '<a class="btn btn-link px-0" href="' . $backurl->out() . '">&lsaquo; ' . get_string('back') . '</a>',
            $html
        );
    }
}
