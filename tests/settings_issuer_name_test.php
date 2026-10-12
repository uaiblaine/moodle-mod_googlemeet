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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * The OAuth issuer select of the plugin settings shows issuer names escaped once.
 *
 * admin_setting_configselect escapes its options itself (core_admin/setting_configselect prints them
 * with a double stash), so settings.php must hand it the plain name.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class settings_issuer_name_test extends \advanced_testcase {
    /**
     * An issuer named "A & B < C" is offered as that text and rendered as "A &amp; B &lt; C".
     */
    public function test_issuer_option_is_escaped_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Persisted directly: api::create_issuer() would run endpoint discovery over HTTP.
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'A & B < C',
            'image' => '',
            'baseurl' => 'https://accounts.google.com',
            'clientid' => 'client',
            'clientsecret' => 'secret',
            'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email',
            'loginparams' => '',
            'loginparamsoffline' => '',
            'showonloginpage' => 0,
        ]);
        $issuer->create();

        $page = admin_get_root(true, true)->locate('modsettinggooglemeet');
        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $setting = $page->settings->googlemeetissuerid;
        $setting->load_choices();
        $this->assertSame('A & B < C', $setting->choices[$issuer->get('id')]);

        $html = $setting->output_html((string)$issuer->get('id'));
        $this->assertStringContainsString('>A &amp; B &lt; C</option>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
