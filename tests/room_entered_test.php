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

use core_external\external_api;
use mod_googlemeet\external\log_room_entered;
use mod_googlemeet\local\room_entry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the room_entered event and its entry points.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\event\room_entered::class)]
#[CoversClass(room_entry::class)]
#[CoversClass(log_room_entered::class)]
final class room_entered_test extends \advanced_testcase {
    /**
     * Call a web service the way the AJAX layer does (session key in the request, returns cleaned).
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
     * Call a web service that must succeed and return its cleaned data.
     *
     * @param string $function Web service name.
     * @param array $args Arguments.
     * @return array Returned data.
     */
    private function ws_ok(string $function, array $args): array {
        $result = $this->ws($function, $args);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        return $result['data'];
    }

    /**
     * Activity + student.
     *
     * @param string $url Meet URL.
     * @return array [googlemeet, cm, student]
     */
    private function setup_activity(string $url = 'https://meet.google.com/abc-defg-hij'): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $DB->set_field('googlemeet', 'url', $url, ['id' => $googlemeet->id]);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeet->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$googlemeet, $cm, $student];
    }

    /**
     * Insert a session.
     *
     * @param int $googlemeetid Instance id.
     * @param int $start Start timestamp.
     * @param int $duration Duration in seconds.
     * @return int
     */
    private function create_session(int $googlemeetid, int $start, int $duration = HOURSECS): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeetid,
            'eventdate' => $start,
            'duration' => $duration,
            'timemodified' => time(),
        ]);
    }

    /**
     * A session is current while live or within EARLY_WINDOW of its start; one two hours away is not.
     */
    public function test_current_session_window(): void {
        [$googlemeet] = $this->setup_activity();
        $now = time();
        $this->create_session($googlemeet->id, $now + 2 * HOURSECS);
        $this->assertNull(room_entry::current_session($googlemeet->id, $now));

        $soon = $this->create_session($googlemeet->id, $now + 10 * MINSECS);
        $this->assertSame($soon, (int)room_entry::current_session($googlemeet->id, $now)->id);

        $live = $this->create_session($googlemeet->id, $now - 10 * MINSECS);
        $this->assertSame($live, (int)room_entry::current_session($googlemeet->id, $now)->id);
    }

    /**
     * Logging an entry triggers one room_entered event carrying the live session id and the channel.
     */
    public function test_log_triggers_event_with_session(): void {
        [$googlemeet, $cm, $student] = $this->setup_activity();
        $sessionid = $this->create_session($googlemeet->id, time() - 5 * MINSECS);
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $logged = room_entry::log($googlemeet, $cm, \context_module::instance($cm->id), 'web');
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame($sessionid, $logged);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\mod_googlemeet\event\room_entered::class, $event);
        $this->assertEquals($student->id, $event->userid);
        $this->assertEquals($cm->id, $event->contextinstanceid);
        $this->assertEquals($googlemeet->id, $event->objectid);
        $this->assertSame($sessionid, $event->other['sessionid']);
        $this->assertSame('web', $event->other['via']);
        $this->assertStringContainsString((string)$sessionid, $event->get_description());
        $this->assertEquals(new \moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]), $event->get_url());
    }

    /**
     * The web service logs the entry (sessionid 0 when no session is current) and returns the room URL.
     */
    public function test_ws_logs_and_returns_url(): void {
        [$googlemeet, $cm, $student] = $this->setup_activity();
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_log_room_entered', ['coursemoduleid' => $cm->id, 'via' => 'mobile']);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['logged']);
        $this->assertSame(0, $result['sessionid']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['url']);
        $this->assertCount(1, $events);
        $this->assertSame('mobile', reset($events)->other['via']);
    }

    /**
     * A stored URL that is not a Meet room is neither logged nor returned.
     */
    public function test_ws_does_not_log_invalid_room(): void {
        [, $cm, $student] = $this->setup_activity('https://example.com/not-a-room');
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $result = $this->ws_ok('mod_googlemeet_log_room_entered', ['coursemoduleid' => $cm->id, 'via' => 'mobile']);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($result['logged']);
        $this->assertSame('', $result['url']);
        $this->assertCount(0, $events);
    }

    /**
     * The hero room button links to enter.php with a sesskey, not straight to Meet, so the entry is logged.
     */
    public function test_hero_room_cta_goes_through_entry_endpoint(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
        [$googlemeet, $cm] = $this->setup_activity();
        $this->setAdminUser();

        $context = \context_module::instance($cm->id);
        $cta = googlemeet_get_room_cta_context($googlemeet, $context, null, true);
        $this->assertTrue($cta['hasroomcta']);
        $this->assertStringContainsString('/mod/googlemeet/enter.php?id=' . $cm->id, $cta['roomurl']);
        $this->assertStringContainsString('sesskey=' . sesskey(), $cta['roomurl']);
    }
}
