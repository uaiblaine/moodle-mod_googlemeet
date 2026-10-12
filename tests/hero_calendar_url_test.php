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

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * The hero's course calendar link is built from wwwroot, so a site under a path keeps it.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_get_classroom_hero_context')]
final class hero_calendar_url_test extends \advanced_testcase {
    /**
     * On a site served from /moodle the rendered schedule link points into /moodle/calendar.
     */
    public function test_calendar_link_follows_wwwroot_path(): void {
        global $CFG, $DB, $OUTPUT;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->wwwroot = 'https://www.example.com/moodle';

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventdate' => time() + DAYSECS,
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);

        $upcoming = googlemeet_get_upcoming_events($googlemeet->id, 3);
        $schedule = googlemeet_get_upcoming_events($googlemeet->id, 0, true);
        $hero = googlemeet_get_classroom_hero_context($googlemeet, $cm, $context, $upcoming, true, $schedule);
        // Control: the schedule section that carries the link is rendered.
        $this->assertTrue($hero['hasscheduleevents']);

        $html = $OUTPUT->render_from_template('mod_googlemeet/hero', $hero);
        $expected = (new \moodle_url('/calendar/view.php', ['view' => 'upcoming', 'course' => $course->id]))->out();
        $this->assertStringStartsWith('https://www.example.com/moodle/calendar/view.php', $expected);
        $this->assertStringContainsString('href="' . $expected . '"', $html);
        $this->assertStringNotContainsString('href="/calendar/view.php', $html);
    }
}
