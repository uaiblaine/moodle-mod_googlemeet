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

use mod_googlemeet\local\schedule;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * The site timezone decides the day of a session for every viewer and for cron.
 *
 * The site runs on Europe/London; the viewer lives in Asia/Tokyo, where a session at 23:30 London
 * time already falls on the next day.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule::class)]
final class session_day_test extends \advanced_testcase {
    /** @var int Site midnight of today. */
    private $midnight;
    /** @var int Today 23:30 in the site timezone. */
    private $lateevening;
    /** @var \stdClass Viewer in Asia/Tokyo. */
    private $tokyouser;

    /**
     * Site timezone, the far-east viewer and the two timestamps around the site's midnight.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('Europe/London', 'Europe/London');

        $today = new \DateTimeImmutable('today', \core_date::get_server_timezone_object());
        $this->midnight = $today->getTimestamp();
        $this->lateevening = $today->setTime(23, 30)->getTimestamp();
        $this->tokyouser = $this->getDataGenerator()->create_user(['timezone' => 'Asia/Tokyo']);
    }

    /**
     * Run a callable as the Tokyo viewer and as the cron user, and return both results.
     *
     * @param callable $fn Code to run.
     * @return array [viewer result, cron result]
     */
    private function as_viewer_and_cron(callable $fn): array {
        $this->setUser($this->tokyouser);
        $viewer = $fn();
        \core\cron::setup_user();
        $cron = $fn();
        return [$viewer, $cron];
    }

    /**
     * Control: the viewer's own calendar day really differs from the site's for the late session.
     */
    public function test_viewer_day_differs_from_site_day(): void {
        $this->assertNotSame(
            usergetmidnight($this->lateevening, 'Asia/Tokyo'),
            usergetmidnight($this->midnight, 'Asia/Tokyo')
        );
        $this->assertSame(
            usergetmidnight($this->lateevening, 'Europe/London'),
            usergetmidnight($this->midnight, 'Europe/London')
        );
        $this->assertSame(schedule::session_day($this->midnight), schedule::session_day($this->lateevening));
        $this->assertNotSame(schedule::session_day($this->lateevening), schedule::session_day($this->lateevening + HOURSECS));
    }

    /**
     * A session late on a cancelled day is cancelled for the far-east viewer and for cron.
     */
    public function test_cancelled_day_is_the_site_day(): void {
        $cancelled = [(object)['id' => 1, 'cancelleddate' => $this->midnight, 'reason' => 'Strike']];

        [$viewer, $cron] = $this->as_viewer_and_cron(fn() => schedule::is_cancelled($this->lateevening, $cancelled));
        $this->assertNotFalse($viewer);
        $this->assertNotFalse($cron);

        // Control: the session one hour later is on the next site day, not cancelled for either.
        [$viewer, $cron] = $this->as_viewer_and_cron(
            fn() => schedule::is_cancelled($this->lateevening + HOURSECS, $cancelled)
        );
        $this->assertFalse($viewer);
        $this->assertFalse($cron);
    }

    /**
     * A session late on a one-day holiday is a holiday for the far-east viewer and for cron.
     */
    public function test_holiday_day_is_the_site_day(): void {
        $holidays = [(object)['startdate' => $this->midnight, 'enddate' => $this->midnight]];

        [$viewer, $cron] = $this->as_viewer_and_cron(fn() => schedule::is_holiday($this->lateevening, $holidays));
        $this->assertTrue($viewer);
        $this->assertTrue($cron);

        [$viewer, $cron] = $this->as_viewer_and_cron(fn() => schedule::is_holiday($this->lateevening + HOURSECS, $holidays));
        $this->assertFalse($viewer);
        $this->assertFalse($cron);
    }

    /**
     * Two cancelled entries on the same site day collapse into one, whoever saves the form.
     */
    public function test_save_cancelled_deduplicates_by_site_day(): void {
        global $DB;
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->getDataGenerator()->create_course()->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $this->setUser($this->tokyouser);
        schedule::save_cancelled((object)[
            'id' => $googlemeet->id,
            'cancelled_repeats' => 2,
            'cancelleddate' => [$this->midnight, $this->lateevening],
            'cancelledreason' => ['', 'Strike'],
        ]);
        $rows = $DB->get_records('googlemeet_cancelled', ['googlemeetid' => $googlemeet->id]);
        $this->assertCount(1, $rows);
        $this->assertSame('Strike', reset($rows)->reason);
    }

    /**
     * The upcoming-session list gives the far-east viewer and cron the same cancelled and today flags.
     */
    public function test_upcoming_events_flags_follow_the_site_day(): void {
        global $DB;
        $this->setAdminUser();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->getDataGenerator()->create_course()->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $DB->delete_records('googlemeet_events', ['googlemeetid' => $googlemeet->id]);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventdate' => $this->lateevening,
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);
        $DB->insert_record('googlemeet_cancelled', (object)[
            'googlemeetid' => $googlemeet->id,
            'cancelleddate' => $this->midnight,
            'reason' => 'Strike',
            'timemodified' => time(),
        ]);

        [$viewer, $cron] = $this->as_viewer_and_cron(function () use ($googlemeet) {
            $events = schedule::get_upcoming_events($googlemeet->id, 10, true)['upcomingevents'];
            $this->assertCount(1, $events);
            return [(bool)$events[0]->iscancelled, (bool)$events[0]->today];
        });
        $this->assertSame([true, true], $viewer);
        $this->assertSame($viewer, $cron);
    }
}
