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
require_once($CFG->dirroot . '/mod/googlemeet/mod_form.php');

/**
 * The Google logout on the activity settings page needs the session key.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_mod_form::class)]
final class mod_form_logout_test extends \advanced_testcase {
    /**
     * Forget the request parameters a test set.
     */
    protected function tearDown(): void {
        unset($_GET['logout'], $_GET['sesskey']);
        parent::tearDown();
    }

    /**
     * A logout link without a valid sesskey (a cross-site link) is ignored; the one
     * client::print_user_info() builds, which carries it, is honoured.
     */
    public function test_logout_requires_sesskey(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(\mod_googlemeet_mod_form::logout_requested());

        $_GET['logout'] = '1';
        $this->assertFalse(\mod_googlemeet_mod_form::logout_requested());

        $_GET['sesskey'] = 'not-the-session-key';
        $this->assertFalse(\mod_googlemeet_mod_form::logout_requested());

        $_GET['sesskey'] = sesskey();
        $this->assertTrue(\mod_googlemeet_mod_form::logout_requested());
    }
}
