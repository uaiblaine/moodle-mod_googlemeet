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
 * The capability definitions of db/access.php that the access fixes rely on.
 *
 * Read from the file rather than the capabilities table, which only changes on an upgrade.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class access_definitions_test extends \advanced_testcase {
    /**
     * The capabilities declared by the plugin.
     *
     * @return array
     */
    private function definitions(): array {
        global $CFG;
        $capabilities = [];
        include($CFG->dirroot . '/mod/googlemeet/db/access.php');
        return $capabilities;
    }

    /**
     * Attendance writes have their own write capability, for editing teachers and managers only.
     */
    public function test_manageattendance_definition(): void {
        $cap = $this->definitions()['mod/googlemeet:manageattendance'] ?? null;
        $this->assertNotNull($cap, 'mod/googlemeet:manageattendance is not declared.');
        $this->assertSame('write', $cap['captype']);
        $this->assertSame(CONTEXT_MODULE, $cap['contextlevel']);
        $this->assertSame(RISK_PERSONAL, $cap['riskbitmask']);
        $this->assertEqualsCanonicalizing(['editingteacher', 'manager'], array_keys($cap['archetypes']));
        $this->assertTrue(get_string_manager()->string_exists('googlemeet:manageattendance', 'mod_googlemeet'));
    }

    /**
     * Editing recordings (materials, names, transcripts shown to students) is flagged as an XSS risk.
     */
    public function test_editrecording_carries_risk_xss(): void {
        $cap = $this->definitions()['mod/googlemeet:editrecording'];
        $this->assertSame(RISK_XSS, ($cap['riskbitmask'] ?? 0) & RISK_XSS);
    }
}
