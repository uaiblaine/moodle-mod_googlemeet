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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Indexes behind the cron queries that scan every activity, as install.xml creates them.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
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
            // Trash purge: deleted = 1 AND trashreason = ? AND timedeleted range.
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
     * The installed schema has every cron index and no single-column status index, which the
     * composite status_nextretry index makes redundant.
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
}
