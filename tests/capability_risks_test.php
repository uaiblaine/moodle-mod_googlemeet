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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Risk flags of the capabilities that send personal data out or destroy data.
 *
 * Checked in db/access.php and in the installed capabilities table, which is what the role
 * definition pages show to an administrator.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class capability_risks_test extends \advanced_testcase {
    /**
     * Capabilities and the risk each must carry.
     *
     * @return array
     */
    public static function risk_provider(): array {
        return [
            'generateai sends transcripts and video to Gemini' => ['mod/googlemeet:generateai', RISK_PERSONAL],
            'removerecording deletes recordings' => ['mod/googlemeet:removerecording', RISK_DATALOSS],
            'editrecording keeps its XSS flag' => ['mod/googlemeet:editrecording', RISK_XSS],
            'managequestions keeps its spam flag' => ['mod/googlemeet:managequestions', RISK_SPAM],
        ];
    }

    /**
     * The capability declares the risk, in the file and in the installed definition.
     *
     * @param string $capability Capability name.
     * @param int $risk RISK_* bit.
     */
    #[DataProvider('risk_provider')]
    public function test_capability_carries_risk(string $capability, int $risk): void {
        global $CFG;
        $capabilities = [];
        include($CFG->dirroot . '/mod/googlemeet/db/access.php');

        $this->assertArrayHasKey($capability, $capabilities);
        $this->assertSame($risk, ($capabilities[$capability]['riskbitmask'] ?? 0) & $risk);

        $installed = get_capability_info($capability);
        $this->assertNotEmpty($installed);
        $this->assertSame($risk, (int)$installed->riskbitmask & $risk);
    }
}
