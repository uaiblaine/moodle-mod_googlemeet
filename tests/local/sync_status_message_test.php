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

namespace mod_googlemeet\local;

use core_external\external_api;
use mod_googlemeet\task\sync_recordings_task;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A failed manual sync reports a stable message through the polling web service.
 *
 * The exception text of an unexpected failure (a Google error body, an HTML page) never reaches the
 * PARAM_TEXT 'message' field: tag-like text there makes clean_returnvalue() fail the whole call.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_manager::class)]
#[CoversClass(\mod_googlemeet\external\get_sync_status::class)]
final class sync_status_message_test extends \advanced_testcase {
    /**
     * Restore the real Drive sync: the runner is static and would leak into later tests.
     */
    protected function tearDown(): void {
        sync_manager::set_runner(null);
        parent::tearDown();
    }

    /**
     * Queue a manual sync whose runner throws $e, run the task and poll the status as the teacher.
     *
     * @param \Throwable $e Exception the fake Drive sync throws.
     * @return array call_external_function() result of mod_googlemeet_get_sync_status.
     */
    private function run_failing_sync(\Throwable $e): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        sync_manager::set_runner(function () use ($e) {
            throw $e;
        });
        sync_manager::request($DB->get_record('googlemeet', ['id' => $gm->id]), (int)$teacher->id);

        ob_start();
        $this->runAdhocTasks(sync_recordings_task::class);
        ob_end_clean();

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_googlemeet_get_sync_status', ['coursemoduleid' => $cm->id]);
    }

    /**
     * An unexpected failure returns the generic message and logs the detail for developers.
     */
    public function test_unexpected_error_returns_generic_message(): void {
        $result = $this->run_failing_sync(new \Exception('<html><body>Backend Error 503 for client@example.com</body></html>'));
        $this->assertDebuggingCalled();
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertSame('error', $result['data']['status']);
        $this->assertSame(get_string('sync_status_failed', 'googlemeet'), $result['data']['message']);
        $this->assertStringNotContainsString('Backend Error', json_encode($result));
    }

    /**
     * Control: a failure the plugin reports in its own words keeps that message.
     */
    public function test_known_error_keeps_its_message(): void {
        $result = $this->run_failing_sync(new \moodle_exception('isnotcreatoremail', 'googlemeet'));
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertSame(get_string('isnotcreatoremail', 'googlemeet'), $result['data']['message']);
    }
}
