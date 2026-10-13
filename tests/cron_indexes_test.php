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
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/mod/googlemeet/db/upgrade.php');

/**
 * Indexes behind the cron queries, on a fresh install and after the upgrade step that adds them.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('xmldb_googlemeet_upgrade')]
final class cron_indexes_test extends \advanced_testcase {
    /**
     * The cron indexes, keyed by table.
     *
     * @return \xmldb_index[] Indexes keyed by table name.
     */
    private function cron_indexes(): array {
        return [
            // Old-event cleanup and reminders: eventdate < now / eventdate > now.
            'googlemeet_events' => new \xmldb_index('eventdate', XMLDB_INDEX_NOTUNIQUE, ['eventdate']),
            // Trash purge: deleted = 1 AND trashreason = ? AND timedeleted (= 0 | range).
            'googlemeet_recordings' => new \xmldb_index(
                'deleted_trashreason_timedeleted',
                XMLDB_INDEX_NOTUNIQUE,
                ['deleted', 'trashreason', 'timedeleted']
            ),
            // Due analyses: status = pending, or status = failed AND nextretry range.
            'googlemeet_ai_analysis' => new \xmldb_index('status_nextretry', XMLDB_INDEX_NOTUNIQUE, ['status', 'nextretry']),
        ];
    }

    /**
     * A fresh install (install.xml) has every cron index, and no longer the single-column status
     * index the composite one starts with.
     */
    public function test_install_declares_the_cron_indexes(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach ($this->cron_indexes() as $tablename => $index) {
            $this->assertTrue($dbman->index_exists(new \xmldb_table($tablename), $index), $tablename);
        }
        $status = new \xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $this->assertFalse($dbman->index_exists(new \xmldb_table('googlemeet_ai_analysis'), $status));
    }

    /**
     * From the schema before the step (no cron index, the single status index), the upgrade step
     * adds the three indexes and drops the redundant one.
     */
    public function test_upgrade_step_adds_the_cron_indexes(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        foreach ($this->cron_indexes() as $tablename => $index) {
            $table = new \xmldb_table($tablename);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $this->assertFalse($dbman->index_exists($table, $index));
        }
        $aitable = new \xmldb_table('googlemeet_ai_analysis');
        $status = new \xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->index_exists($aitable, $status)) {
            $dbman->add_index($aitable, $status);
        }

        set_config('version', 2026101399, 'mod_googlemeet');
        $this->assertTrue(xmldb_googlemeet_upgrade(2026101399));

        foreach ($this->cron_indexes() as $tablename => $index) {
            $this->assertTrue($dbman->index_exists(new \xmldb_table($tablename), $index), $tablename);
        }
        $this->assertFalse($dbman->index_exists($aitable, $status));
        $this->assertEquals(2026101400, get_config('mod_googlemeet', 'version'));
    }
}
