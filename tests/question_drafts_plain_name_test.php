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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * The activity-wide publish reports each recording name in its plain spelling.
 *
 * question_review.js escapes the name itself, so an escaped name would show "A &amp; B".
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(question_service::class)]
final class question_drafts_plain_name_test extends \advanced_testcase {
    /**
     * A recording named "A & B < C" comes back as that text, not as "A &amp; B &lt; C".
     */
    public function test_publish_result_name_is_plain(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $now = time();
        $recordingid = (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeet->id,
            'recordingid' => 'drive-plain-name',
            'name' => 'A & B < C',
            'createdtime' => $now,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'visible' => 1,
            'timemodified' => $now,
        ]);

        $service = new question_service();
        $service->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, [
            'stem' => 'Which law regulates the national health system?',
            'options' => ['Law 14/1986', 'Law 16/2003', 'Law 41/2002', 'Law 55/2003'],
            'correctindex' => 0,
            'explanation' => 'It is the general health law.',
            'citation' => '00:01:05',
        ]);

        $outcome = $service->publish_activity_drafts($googlemeet, $cm, $context);
        // The leak gate may report through debugging() that it was skipped (it depends on the site's
        // optional plugins); this test is about the name only.
        $this->resetDebugging();

        $this->assertCount(1, $outcome['results']);
        $this->assertSame('A & B < C', $outcome['results'][0]['name']);
    }
}
