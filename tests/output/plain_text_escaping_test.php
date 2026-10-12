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

use mod_googlemeet\local\practice_attempts;
use mod_googlemeet\local\report_builder;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Names reach the report and the Moodle App templates in their plain spelling and are escaped once.
 *
 * A Mustache double stash escapes its value, so the context must hold the plain text: a context built
 * with an escaping format_string() shows "A &amp; B" on the page.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(report_page::class)]
#[CoversClass(mobile::class)]
final class plain_text_escaping_test extends \advanced_testcase {
    /** @var string A name with every character HTML escaping changes; "< " is not a tag, so it survives strip_tags(). */
    private const NAME = 'A & B < C';

    /** @var string The same name escaped once, as the browser must receive it. */
    private const ONCE = 'A &amp; B &lt; C';

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \context_module */
    private $context;

    /**
     * Course and activity; the admin is the current user.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('googlemeet', $gm->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Insert a visible recording named self::NAME.
     *
     * @return int Recording id.
     */
    private function create_recording(): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id,
            'recordingid' => 'drive-' . random_string(6),
            'name' => self::NAME,
            'createdtime' => 1767225600,
            'duration' => '00:10:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Assert that $html carries no doubly escaped entity.
     *
     * @param string $html Rendered template.
     * @return void
     */
    private function assert_not_double_escaped(string $html): void {
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;#', $html);
    }

    /**
     * Report: recording names (column header and most-failed section) and identity values.
     */
    public function test_report_page_names_are_escaped_once(): void {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        set_config('showuseridentity', 'department');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['department' => 'R&D']);
        $recordingid = $this->create_recording();
        // A wrong answer puts the recording into the most-failed section.
        practice_attempts::record((int)$this->googlemeet->id, $recordingid, 999, (int)$student->id, false);

        $builder = new report_builder($this->googlemeet, $this->cm, $this->context);
        $page = new report_page(
            $builder->build(0, false, time()),
            $this->googlemeet,
            $this->cm,
            $this->context,
            ['groupid' => 0, 'groups' => [], 'inactive' => 0, 'page' => 0, 'perpage' => 50],
            $builder->get_identity_fields()
        );
        $ctx = $page->export_for_template($output);

        // The context holds the plain spelling.
        $this->assertSame(self::NAME, $ctx['headers'][0]['name']);
        $this->assertSame(self::NAME, $ctx['failedsections'][0]['name']);
        $this->assertSame('R&D', $ctx['rows'][0]['identity'][0]['value']);

        // The page shows it escaped exactly once.
        $html = $output->render_from_template('mod_googlemeet/report_page', $ctx);
        $this->assertStringContainsString('1. ' . self::ONCE . '</a>', $html);
        $this->assertStringContainsString('">' . self::ONCE . '</a></h4>', $html);
        $this->assertStringContainsString('<td>R&amp;D</td>', $html);
        $this->assert_not_double_escaped($html);
    }

    /**
     * Moodle App: class title in the list and on the class page, key points and chapter titles.
     */
    public function test_mobile_names_are_escaped_once(): void {
        global $DB;
        $recordingid = $this->create_recording();
        $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid,
            'summary' => 'Summary.',
            'keypoints' => json_encode([self::NAME]),
            'topics' => json_encode([]),
            'chapters' => json_encode([['title' => self::NAME, 'start' => '00:00']]),
            'status' => 'completed',
            'reviewed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $args = ['cmid' => $this->cm->id, 'courseid' => $this->course->id];

        // The list: plain in the context, once escaped in the app template.
        $list = mobile::recordings_page($this->googlemeet, $this->context, 0);
        $this->assertSame(self::NAME, $list['recordings'][0]['title']);
        $html = mobile::mobile_course_view($args)['templates'][0]['html'];
        $this->assertStringContainsString('<h2>' . self::ONCE . '</h2>', $html);
        $this->assert_not_double_escaped($html);

        // The class page.
        $html = mobile::mobile_recording_view($args + ['recordingid' => $recordingid])['templates'][0]['html'];
        $this->assertStringContainsString('<h1>' . self::ONCE . '</h1>', $html);
        $this->assertStringContainsString('<ion-label class="ion-text-wrap">' . self::ONCE . '</ion-label>', $html);
        $this->assertEquals(2, substr_count($html, '<ion-label class="ion-text-wrap">' . self::ONCE . '</ion-label>'));
        $this->assert_not_double_escaped($html);
    }
}
