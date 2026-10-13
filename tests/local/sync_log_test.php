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

/**
 * Tests for the sync log.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The sync log never stores an e-mail address in clear.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_log::class)]
final class sync_log_test extends \advanced_testcase {
    /**
     * The organiser e-mail of a calendar error is stored masked; text without one is stored as given.
     *
     * The first row is the control: it shows finish() stores the message at all, so the masked
     * second row is the masking's doing and not an empty column.
     *
     * @return void
     */
    public function test_finish_masks_email_addresses(): void {
        global $DB;

        $this->resetAfterTest();
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $this->getDataGenerator()->create_course()->id]);

        $plain = sync_log::start((int)$gm->id, sync_log::KIND_CALENDAR_UPDATE, sync_log::STATUS_RUNNING);
        sync_log::finish($plain, sync_log::STATUS_ERROR, null, '403: Forbidden');
        $this->assertSame('403: Forbidden', $DB->get_field(sync_log::TABLE, 'message', ['id' => $plain]));

        $reason = get_string('calsync_reason_notlinked', 'googlemeet', 'Organiser.Name+meet@school.example.org');
        $masked = sync_log::start((int)$gm->id, sync_log::KIND_CALENDAR_UPDATE, sync_log::STATUS_RUNNING);
        sync_log::finish($masked, sync_log::STATUS_ERROR, null, $reason . ' (also cc: x@y.example)');
        $stored = (string)$DB->get_field(sync_log::TABLE, 'message', ['id' => $masked]);

        $this->assertStringNotContainsString('Organiser.Name', $stored);
        $this->assertStringContainsString('(***@school.example.org)', $stored);
        $this->assertStringEndsWith('(also cc: ***@y.example)', $stored);
    }
}
