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
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/db/upgradelib.php');

/**
 * Stored holiday and cancelled days are normalised to site midnight.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('mod_googlemeet_normalise_session_days')]
final class upgrade_session_days_test extends \advanced_testcase {
    /** @var int Activity id. */
    private $instanceid;

    /**
     * Site timezone UTC and one activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $this->instanceid = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->getDataGenerator()->create_course()->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ])->id;
    }

    /**
     * Midnight of a calendar day in a timezone.
     *
     * @param string $day Y-m-d.
     * @param string $tz Timezone name.
     * @return int Timestamp.
     */
    private function midnight(string $day, string $tz): int {
        return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone($tz)))->getTimestamp();
    }

    /**
     * Store a cancelled date and return its row id.
     *
     * @param int $time Stored value.
     * @return int Row id.
     */
    private function add_cancelled(int $time): int {
        global $DB;
        return $DB->insert_record('googlemeet_cancelled', (object)[
            'googlemeetid' => $this->instanceid, 'cancelleddate' => $time, 'reason' => '', 'timemodified' => 0,
        ]);
    }

    /**
     * A teacher ahead of the site (Sydney) picked 5 March: the day is recovered.
     */
    public function test_cancelled_from_ahead_timezone(): void {
        global $DB;
        $id = $this->add_cancelled($this->midnight('2026-03-05', 'Australia/Sydney'));
        mod_googlemeet_normalise_session_days();
        $stored = (int)$DB->get_field('googlemeet_cancelled', 'cancelleddate', ['id' => $id]);
        $this->assertSame($this->midnight('2026-03-05', 'UTC'), $stored);
        $session = $this->midnight('2026-03-05', 'UTC') + 15 * HOURSECS;
        $this->assertNotFalse(schedule::is_cancelled($session, $DB->get_records('googlemeet_cancelled')));
    }

    /**
     * A teacher behind the site (Sao Paulo) picked 5 March: the day is recovered.
     */
    public function test_cancelled_from_behind_timezone(): void {
        global $DB;
        $id = $this->add_cancelled($this->midnight('2026-03-05', 'America/Sao_Paulo'));
        mod_googlemeet_normalise_session_days();
        $this->assertSame(
            $this->midnight('2026-03-05', 'UTC'),
            (int)$DB->get_field('googlemeet_cancelled', 'cancelleddate', ['id' => $id])
        );
    }

    /**
     * A value that already is a site midnight stays, and a second run changes nothing.
     */
    public function test_site_midnight_unchanged(): void {
        global $DB;
        $time = $this->midnight('2026-03-05', 'UTC');
        $id = $this->add_cancelled($time);
        $this->assertSame(0, mod_googlemeet_normalise_session_days());
        $this->assertSame($time, (int)$DB->get_field('googlemeet_cancelled', 'cancelleddate', ['id' => $id]));
    }

    /**
     * Holiday start and end are normalised alike.
     */
    public function test_holidays(): void {
        global $DB;
        $id = $DB->insert_record('googlemeet_holidays', (object)[
            'googlemeetid' => $this->instanceid,
            'name' => '',
            'startdate' => $this->midnight('2026-03-05', 'Australia/Sydney'),
            'enddate' => $this->midnight('2026-03-08', 'America/Sao_Paulo'),
            'timemodified' => 0,
        ]);
        mod_googlemeet_normalise_session_days();
        $row = $DB->get_record('googlemeet_holidays', ['id' => $id]);
        $this->assertSame($this->midnight('2026-03-05', 'UTC'), (int)$row->startdate);
        $this->assertSame($this->midnight('2026-03-08', 'UTC'), (int)$row->enddate);
        $this->assertSame(0, mod_googlemeet_normalise_session_days());
    }
}
