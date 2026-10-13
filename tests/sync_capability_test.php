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

use core_external\external_api;
use mod_googlemeet\external\get_sync_status;
use mod_googlemeet\external\request_sync;
use mod_googlemeet\local\sync_log;
use mod_googlemeet\local\sync_manager;
use mod_googlemeet\local\view_page;
use mod_googlemeet\task\sync_recordings_task;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A manual Drive sync needs mod/googlemeet:syncgoogledrive when it is asked for, not only when cron runs it.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_manager::class)]
#[CoversClass(request_sync::class)]
#[CoversClass(get_sync_status::class)]
final class sync_capability_test extends \advanced_testcase {
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \context_module */
    private $context;
    /** @var \stdClass Editing teacher whose role is denied syncgoogledrive in the activity. */
    private $teacher;
    /** @var \stdClass Editing teacher with the default capabilities. */
    private $syncer;

    /**
     * Activity with two editing teachers; syncgoogledrive is prohibited for the first one only.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gm = $gen->create_module('googlemeet', ['course' => $course->id]);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->syncer = $gen->create_and_enrol($course, 'editingteacher');
        $this->teacher = $gen->create_and_enrol($course, 'editingteacher');

        // A role of its own, so the override hits this teacher and not the control.
        $roleid = $gen->create_role(['shortname' => 'nodrivesync']);
        assign_capability('mod/googlemeet:syncgoogledrive', CAP_PROHIBIT, $roleid, $this->context->id, true);
        role_assign($roleid, $this->teacher->id, $this->context->id);
    }

    /**
     * Whether a manual sync was queued for the activity.
     *
     * @return bool
     */
    private function queued(): bool {
        global $DB;
        return $DB->record_exists(sync_log::TABLE, ['googlemeetid' => $this->googlemeet->id, 'kind' => sync_log::KIND_MANUAL])
            || (bool)\core\task\manager::get_adhoc_tasks(sync_recordings_task::class);
    }

    /**
     * Call a web service as the current user.
     *
     * @param string $function Function name.
     * @return array Result of call_external_function().
     */
    private function call(string $function): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function($function, ['coursemoduleid' => $this->cm->id]);
    }

    /**
     * Without syncgoogledrive the request and the status web services fail and nothing is queued; the
     * control teacher queues and polls.
     */
    public function test_web_services_require_syncgoogledrive(): void {
        $this->assertTrue(has_capability('mod/googlemeet:editrecording', $this->context, $this->teacher));

        $this->setUser($this->teacher);
        $result = $this->call('mod_googlemeet_request_sync');
        $this->assertTrue($result['error'], 'A teacher without syncgoogledrive queued a sync.');
        $this->assertSame('nopermissions', $result['exception']->errorcode);
        $this->assertFalse($this->queued());
        $result = $this->call('mod_googlemeet_get_sync_status');
        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);

        $this->setUser($this->syncer);
        $result = $this->call('mod_googlemeet_request_sync');
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertTrue($result['data']['queued']);
        $this->assertTrue($this->queued());
        $this->assertFalse($this->call('mod_googlemeet_get_sync_status')['error']);
    }

    /**
     * The queueing API itself refuses a user without syncgoogledrive, whoever calls it.
     */
    public function test_request_checks_the_requesting_user(): void {
        $this->setUser($this->syncer);
        try {
            sync_manager::request($this->googlemeet, (int)$this->teacher->id);
            $this->fail('A sync was queued for a user without syncgoogledrive.');
        } catch (\required_capability_exception $e) {
            $this->assertFalse($this->queued());
        }
        $this->assertTrue(sync_manager::request($this->googlemeet, (int)$this->syncer->id));
    }

    /**
     * The no-JS POST on view.php refuses before queueing.
     */
    public function test_view_action_requires_syncgoogledrive(): void {
        $this->setUser($this->teacher);
        $_POST = ['sync' => 1, 'sesskey' => sesskey()];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            view_page::handle_view_actions($this->googlemeet, $this->cm, get_course($this->cm->course));
            $this->fail('The view action returned without a redirect.');
        } catch (\required_capability_exception $e) {
            $this->assertFalse($this->queued());
        } catch (\moodle_exception $e) {
            $this->assertNotSame('redirecterrordetected', $e->errorcode, 'A teacher without syncgoogledrive queued a sync.');
            throw $e;
        } finally {
            $_POST = [];
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    /**
     * The sync button and its script are only offered to a user who may sync.
     */
    public function test_button_follows_can_request(): void {
        global $OUTPUT;
        $this->assertFalse(sync_manager::can_request($this->context, (int)$this->teacher->id));
        $this->assertTrue(sync_manager::can_request($this->context, (int)$this->syncer->id));

        $context = [
            'cmid' => $this->cm->id, 'syncactive' => false, 'syncstatusmessage' => '', 'lastsync' => '',
            'creatoremail' => 'a@example.com', 'redordingname' => '', 'login' => '', 'islogged' => true,
            'syncbutton' => '<button>Sync</button>', 'isloggedcreatoremail' => true,
        ];
        $hidden = $OUTPUT->render_from_template('mod_googlemeet/syncbutton', $context + ['cansync' => false]);
        $this->assertStringNotContainsString('data-googlemeet-async-sync', $hidden);
        $shown = $OUTPUT->render_from_template('mod_googlemeet/syncbutton', $context + ['cansync' => true]);
        $this->assertStringContainsString('data-googlemeet-async-sync', $shown);
    }
}
