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

use mod_googlemeet\local\attendance\scope;
use mod_googlemeet\local\attendance\service;
use mod_googlemeet\local\calendar_sync;
use mod_googlemeet\local\creator;
use mod_googlemeet\task\calendar_delete_event;
use mod_googlemeet\task\fetch_attendance;
use mod_googlemeet\task\process_autosync;
use mod_googlemeet\tests\attendance_source_double;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/db/upgradelib.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/calendar_sync_test_fake_rest.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/attendance_source_double.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * The activity organiser is the Moodle user recorded in creatoruserid, not whoever has the e-mail.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(creator::class)]
#[CoversClass(scope::class)]
#[CoversClass(calendar_sync::class)]
#[CoversClass(fetch_attendance::class)]
#[CoversClass(process_autosync::class)]
#[CoversClass(\mod_googlemeet\privacy\provider::class)]
#[CoversFunction('googlemeet_add_instance')]
#[CoversFunction('googlemeet_update_instance')]
#[CoversFunction('mod_googlemeet_backfill_creatoruserid')]
final class creator_identity_test extends \advanced_testcase {
    /** @var array Users the fake Calendar service was built for. */
    private array $factoryusers = [];

    /**
     * Admin session, and every Google Calendar call goes to a fake that records its user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->factoryusers = [];
        $rest = new calendar_sync_test_fake_rest();
        calendar_sync::set_service_factory(function (\stdClass $user) use ($rest) {
            $this->factoryusers[] = (int)$user->id;
            return $rest;
        });
    }

    /**
     * Restore the default service factory (static state shared by every test).
     */
    protected function tearDown(): void {
        calendar_sync::set_service_factory(null);
        parent::tearDown();
    }

    /**
     * An activity whose stored record then gets $fields written over it.
     *
     * @param array $fields Columns to set after creation (creatoremail, creatoruserid, ...).
     * @param \stdClass|null $course Course; a new one when null.
     * @return \stdClass The stored googlemeet record.
     */
    private function make_activity(array $fields, ?\stdClass $course = null): \stdClass {
        global $DB;
        static $n = 0;
        $n++;
        $course = $course ?? $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Sala ' . $n,
            'url' => 'https://meet.google.com/q' . chr(97 + intdiv($n, 26) % 26) . chr(97 + $n % 26) . '-defg-hij',
            'eventdate' => make_timestamp(2026, 3, 2, 0, 0, 0, 'Europe/Madrid'),
            'starthour' => 18, 'startminute' => 30, 'endhour' => 20, 'endminute' => 0,
        ]);
        $DB->update_record('googlemeet', (object)(['id' => $gm->id] + $fields));
        return $DB->get_record('googlemeet', ['id' => $gm->id], '*', MUST_EXIST);
    }

    /**
     * The recorded id wins over the e-mail, and a recorded but suspended creator is nobody.
     */
    public function test_resolve_uses_recorded_id(): void {
        global $DB;
        $owner = $this->getDataGenerator()->create_user(['email' => 'personal@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $gm = $this->make_activity(['creatoremail' => 'profe@example.com', 'creatoruserid' => $owner->id]);

        $this->assertEquals($owner->id, creator::resolve($gm)->id);

        // The e-mail fallback never replaces a recorded id, even when that user cannot act.
        $DB->set_field('user', 'suspended', 1, ['id' => $owner->id]);
        $this->assertNull(creator::resolve($gm));
        $this->assertEquals($owner->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * Without a recorded id, the single active owner of the e-mail (any case) is the answer, but
     * resolve() never writes it: a read path must not bind anybody.
     */
    public function test_resolve_fallback_never_persists(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['email' => 'Profe@Example.com']);
        $gm = $this->make_activity(['creatoremail' => 'profe@example.com', 'creatoruserid' => 0]);

        $this->assertEquals($user->id, creator::resolve($gm)->id);
        $this->assertEquals(0, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
        $this->assertEquals(0, $gm->creatoruserid);
        // The attendance read path is the same call.
        $this->assertTrue(scope::is_organiser($gm, $user));
        $this->assertEquals(0, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * The e-mail moves to another user later: an unrecorded row follows the current owner (still
     * writing nothing), a recorded one keeps the recorded user.
     */
    public function test_resolve_follows_moved_email_without_binding(): void {
        global $DB;
        $first = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $unrecorded = $this->make_activity(['creatoremail' => 'profe@example.com', 'creatoruserid' => 0]);
        $recorded = $this->make_activity(['creatoremail' => 'profe@example.com', 'creatoruserid' => $first->id]);
        $this->assertEquals($first->id, creator::resolve($unrecorded)->id);

        // The first user changes e-mail and another account takes the address.
        $DB->set_field('user', 'email', 'moved@example.com', ['id' => $first->id]);
        $second = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);

        $this->assertEquals($second->id, creator::resolve($unrecorded)->id);
        $this->assertEquals(0, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $unrecorded->id]));
        $this->assertEquals($first->id, creator::resolve($recorded)->id);
        $this->assertEquals($first->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $recorded->id]));
        $this->assertFalse(scope::is_organiser($recorded, $second));
    }

    /**
     * Several users with the e-mail, only a suspended one, or no e-mail: nobody, and nothing recorded.
     */
    public function test_resolve_fallback_refuses_ambiguous_or_missing(): void {
        global $DB;
        $this->getDataGenerator()->create_user(['email' => 'twin@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'TWIN@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'gone@example.com', 'suspended' => 1]);

        foreach (['twin@example.com', 'gone@example.com', 'nobody@example.com', ''] as $email) {
            $gm = $this->make_activity(['creatoremail' => $email, 'creatoruserid' => 0]);
            $this->assertNull(creator::resolve($gm), $email);
            $this->assertEquals(0, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]), $email);
        }
        // The control: the same rule finds a user that is alone with the e-mail.
        $single = $this->getDataGenerator()->create_user(['email' => 'single@example.com']);
        $gm = $this->make_activity(['creatoremail' => 'single@example.com', 'creatoruserid' => 0]);
        $this->assertEquals($single->id, creator::resolve($gm)->id);
    }

    /**
     * The organiser is the recorded user; the e-mail decides only while no id is recorded, and only
     * when one user has it.
     */
    public function test_is_organiser_follows_recorded_id(): void {
        $owner = $this->getDataGenerator()->create_user(['email' => 'personal@example.com']);
        $other = $this->getDataGenerator()->create_user(['email' => 'organiser@example.com']);
        $gm = $this->make_activity(['creatoremail' => 'organiser@example.com', 'creatoruserid' => $owner->id]);

        $this->assertTrue(scope::is_organiser($gm, $owner));
        $this->assertFalse(scope::is_organiser($gm, $other));

        $unresolved = $this->make_activity(['creatoremail' => 'ORGANISER@example.com', 'creatoruserid' => 0]);
        $this->assertTrue(scope::is_organiser($unresolved, $other));
        $this->assertFalse(scope::is_organiser($unresolved, $owner));

        $twin = $this->getDataGenerator()->create_user(['email' => 'organiser@example.com']);
        $ambiguous = $this->make_activity(['creatoremail' => 'organiser@example.com', 'creatoruserid' => 0]);
        $this->assertFalse(scope::is_organiser($ambiguous, $other));
        $this->assertFalse(scope::is_organiser($ambiguous, $twin));
    }

    /**
     * The attendance task fetches as the recorded creator although two other users share the e-mail.
     */
    public function test_fetch_attendance_runs_as_recorded_creator(): void {
        global $DB;
        set_config('attendanceenabled', 1, 'googlemeet');
        $owner = $this->getDataGenerator()->create_user(['email' => 'personal@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'organiser@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'organiser@example.com']);
        $gm = $this->make_activity([
            'creatoremail' => 'organiser@example.com', 'creatoruserid' => $owner->id, 'attendanceenabled' => 1,
        ]);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $gm->id, 'eventdate' => time() - 3 * HOURSECS, 'duration' => HOURSECS, 'timemodified' => time(),
        ]);

        $task = new class (new attendance_source_double()) extends fetch_attendance {
            /** @var attendance_source_double */
            public $source;
            /** @var int */
            public $runas = 0;
            /**
             * Constructor.
             * @param attendance_source_double $source Source.
             */
            public function __construct($source) {
                $this->source = $source;
            }
            /**
             * Record which user the task runs as and return the double.
             *
             * @return \mod_googlemeet\local\attendance\source|null
             */
            protected function get_source(): ?\mod_googlemeet\local\attendance\source {
                global $USER;
                $this->runas = (int)$USER->id;
                return $this->source;
            }
        };
        ob_start();
        $task->run(time());
        ob_end_clean();

        $this->assertEquals($owner->id, $task->runas);
        $this->assertNotEmpty($task->source->calls);
        $status = $DB->get_field('googlemeet_attendance_sync', 'status', ['googlemeetid' => $gm->id]);
        $this->assertNotSame(service::STATUS_ERROR, $status);
    }

    /**
     * A Calendar update impersonates the recorded creator, not the user who has the e-mail.
     */
    public function test_calendar_update_impersonates_recorded_creator(): void {
        $owner = $this->getDataGenerator()->create_user(['email' => 'personal@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $gm = $this->make_activity([
            'creatoremail' => 'profe@example.com', 'creatoruserid' => $owner->id, 'eventid' => 'evtowner01',
        ]);

        ob_start();
        $result = calendar_sync::apply_update((int)$gm->id, 0);
        ob_end_clean();

        $this->assertSame('success', $result);
        $this->assertSame([(int)$owner->id], $this->factoryusers);
    }

    /**
     * With no recorded id and two users sharing the e-mail, the Calendar is not touched at all.
     */
    public function test_calendar_update_refuses_ambiguous_email(): void {
        $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $gm = $this->make_activity(['creatoremail' => 'profe@example.com', 'creatoruserid' => 0, 'eventid' => 'evtamb01']);

        ob_start();
        $result = calendar_sync::apply_update((int)$gm->id, 0);
        ob_end_clean();

        $this->assertSame('error', $result);
        $this->assertSame([], $this->factoryusers);
    }

    /**
     * The queued deletion carries the creator id, because the activity record is gone when it runs.
     */
    public function test_queued_delete_carries_creator_id(): void {
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $owner = $this->getDataGenerator()->create_user(['email' => 'personal@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $gm = $this->make_activity([
            'creatoremail' => 'profe@example.com', 'creatoruserid' => $owner->id, 'eventid' => 'evtdel01',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $gm->course, false, MUST_EXIST);

        \core_courseformat\formatactions::cm($gm->course)->delete($cm->id);
        $tasks = \core\task\manager::get_adhoc_tasks(calendar_delete_event::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals($owner->id, ((array)reset($tasks)->get_custom_data())['creatoruserid']);

        ob_start();
        $this->runAdhocTasks(calendar_delete_event::class);
        ob_end_clean();
        $this->assertSame([(int)$owner->id], $this->factoryusers);
    }

    /**
     * Autosync acts as the recorded creator even when the e-mail belongs to several users.
     */
    public function test_autosync_impersonates_recorded_creator(): void {
        global $DB;
        $this->getDataGenerator()->create_user(['email' => 'profe@example.com', 'username' => 'firsttwin']);
        $owner = $this->getDataGenerator()->create_user(['email' => 'profe@example.com', 'username' => 'secondtwin']);
        $gm = $this->make_activity([
            'creatoremail' => 'profe@example.com', 'creatoruserid' => $owner->id, 'autosynchours' => 1,
        ]);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $gm->id, 'eventdate' => time() - 5 * HOURSECS, 'duration' => HOURSECS,
            'timemodified' => time(), 'autosynced' => 0, 'syncattempts' => 0, 'nextsyncattempt' => 0,
        ]);

        ob_start();
        (new process_autosync())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('creator secondtwin is not logged-in', $output);
        $this->assertStringNotContainsString('firsttwin', $output);
    }

    /**
     * An administrator saving the form records the single active owner of the organiser e-mail they
     * name; data cannot name another creator.
     */
    public function test_add_and_update_instance_record_creator(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user(['email' => 'Teacher@example.com']);
        $other = $this->getDataGenerator()->create_user(['email' => 'other@example.com']);
        $course = $this->getDataGenerator()->create_course();

        // Not linked to Google: the typed e-mail is resolved under the single-match rule.
        $gm = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id, 'creatoremail' => 'teacher@example.com', 'creatoruserid' => $other->id,
        ]);
        $this->assertEquals($teacher->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));

        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);
        $shared = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id, 'creatoremail' => 'shared@example.com',
        ]);
        $this->assertEquals(0, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $shared->id]));

        // Keeping the e-mail keeps the recorded creator, whatever the data says.
        $record = $DB->get_record('googlemeet', ['id' => $gm->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        googlemeet_update_instance($this->form_data($record, $cm, [
            'creatoremail' => 'TEACHER@example.com', 'creatoruserid' => $other->id,
        ]));
        $this->assertEquals($teacher->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));

        // A new organiser e-mail is resolved again.
        googlemeet_update_instance($this->form_data($record, $cm, ['creatoremail' => 'other@example.com']));
        $this->assertEquals($other->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * Create an activity through the generator (add_moduleinfo, so googlemeet_add_instance()) as the
     * current user, with no Google login.
     *
     * @param \stdClass $course Course.
     * @param string $email Organiser e-mail typed in the form.
     * @return \stdClass The module record.
     */
    private function add_as_current_user(\stdClass $course, string $email): \stdClass {
        return $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id, 'name' => 'Added', 'creatoremail' => $email,
        ]);
    }

    /**
     * A teacher cannot bind another user's Google account to a new activity: add_instance refuses.
     */
    public function test_add_instance_refuses_other_users_email(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'victim@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $before = $DB->count_records('googlemeet');

        $this->setUser($teacher);
        try {
            $this->add_as_current_user($course, 'Victim@example.com');
            $this->fail('add_instance must refuse an organiser e-mail that is not the editor\'s');
        } catch (\moodle_exception $e) {
            $this->assertSame('creatoremail_notyours', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('googlemeet'));
    }

    /**
     * The form validation reports the same refusal on the organiser e-mail field.
     */
    public function test_form_validation_refuses_other_users_email(): void {
        global $CFG, $COURSE, $PAGE;
        require_once($CFG->dirroot . '/mod/googlemeet/mod_form.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'victim@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $COURSE = $course;
        $PAGE->set_course($course);
        $this->setUser($teacher);

        $current = new \stdClass();
        $current->course = $course->id;
        $current->instance = '';
        $current->coursemodule = '';
        $current->cmidnumber = '';
        $current->availabilityconditionsjson = '{"op":"&","c":[],"showc":[]}';
        $current->visible = 1;
        $current->groupmode = 0;
        $current->groupingid = 0;
        $current->section = 0;
        $current->add = 'googlemeet';
        $current->return = 0;
        $current->sr = 0;
        $form = new \mod_googlemeet_mod_form($current, 0, null, $course);

        $base = [
            'starthour' => 0, 'startminute' => 0, 'endhour' => 0, 'endminute' => 0,
            'eventdate' => $course->startdate + DAYSECS, 'url' => 'https://meet.google.com/abc-defg-hij',
            'name' => 'Added', 'availabilityconditionsjson' => '', 'completionunlocked' => 1,
            'modulename' => 'googlemeet', 'instance' => 0, 'coursemodule' => 0, 'cmidnumber' => '',
        ];
        $errors = $form->validation($base + ['creatoremail' => 'victim@example.com'], []);
        $this->assertArrayHasKey('creatoremail', $errors);
        $errors = $form->validation($base + ['creatoremail' => 'Teacher@example.com'], []);
        $this->assertArrayNotHasKey('creatoremail', $errors);
    }

    /**
     * Control: a teacher naming their own Moodle e-mail is recorded as the creator.
     */
    public function test_add_instance_own_email_records_editor(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.com']);
        // Another account sharing the address must not take the binding from the editor.
        $this->getDataGenerator()->create_user(['email' => 'teacher@example.com']);
        $course = $this->getDataGenerator()->create_course();

        $this->setUser($teacher);
        $gm = $this->add_as_current_user($course, 'TEACHER@example.com');
        $this->assertEquals($teacher->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * Control: an administrator naming someone else's e-mail records that e-mail's single owner.
     */
    public function test_add_instance_admin_may_name_another_user(): void {
        global $DB;
        $owner = $this->create_restorer('owner@example.com');
        $course = $this->getDataGenerator()->create_course();

        $this->setAdminUser();
        $gm = $this->add_as_current_user($course, 'owner@example.com');
        $this->assertEquals($owner->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * Form-like data for googlemeet_update_instance().
     *
     * @param \stdClass $record Stored activity.
     * @param \stdClass $cm Course module.
     * @param array $changes Fields to override.
     * @return \stdClass
     */
    private function form_data(\stdClass $record, \stdClass $cm, array $changes): \stdClass {
        $data = clone $record;
        $data->instance = $record->id;
        $data->coursemodule = $cm->id;
        unset($data->addmultiply, $data->days);
        foreach ($changes as $key => $value) {
            $data->$key = $value;
        }
        return $data;
    }

    /**
     * The upgrade backfill fills only activities whose e-mail names exactly one active user.
     */
    public function test_upgrade_backfill(): void {
        global $DB;
        $single = $this->getDataGenerator()->create_user(['email' => 'Single@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'twin@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'twin@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'gone@example.com', 'suspended' => 1]);
        $this->getDataGenerator()->create_user(['email' => 'deleted@example.com', 'deleted' => 1]);
        $kept = $this->getDataGenerator()->create_user(['email' => 'kept@example.com']);

        $expected = [];
        $cases = [
            'single@example.com' => (int)$single->id,
            'twin@example.com' => 0,
            'gone@example.com' => 0,
            'deleted@example.com' => 0,
            'nobody@example.com' => 0,
            '' => 0,
        ];
        foreach ($cases as $email => $userid) {
            $expected[$this->make_activity(['creatoremail' => $email, 'creatoruserid' => 0])->id] = $userid;
        }
        // An id already recorded is never overwritten, even when the e-mail names someone else.
        $expected[$this->make_activity(['creatoremail' => 'single@example.com', 'creatoruserid' => $kept->id])->id] =
            (int)$kept->id;

        $this->assertSame(1, mod_googlemeet_backfill_creatoruserid());
        foreach ($expected as $gmid => $userid) {
            $this->assertEquals($userid, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gmid]), "activity $gmid");
        }
        $this->assertSame(0, mod_googlemeet_backfill_creatoruserid());
    }

    /**
     * A user who may restore into a new course (manager role) but does not hold moodle/site:config.
     *
     * @param string $email Moodle e-mail of the user.
     * @return \stdClass
     */
    private function create_restorer(string $email): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['email' => $email]);
        $this->getDataGenerator()->role_assign(
            (int)$DB->get_field('role', 'id', ['shortname' => 'manager']),
            $user->id,
            \context_system::instance()->id
        );
        return $user;
    }

    /**
     * Back up a course and restore it into a new one.
     *
     * @param int $courseid Source course.
     * @param bool $users Include user data.
     * @param \stdClass|null $restorer User running the restore; the current user when null.
     * @return \stdClass The restored googlemeet record.
     */
    private function backup_and_restore(int $courseid, bool $users, ?\stdClass $restorer = null): \stdClass {
        global $DB, $USER;
        set_config('backup_general_users', 1, 'backup');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupdir = 'googlemeet-creator-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupdir));
        $category = $DB->get_field('course', 'category', ['id' => $courseid]);
        $newcourseid = \restore_dbops::create_new_course('Copy', 'creatorcopy' . random_string(4), $category);
        $rc = new \restore_controller(
            $backupdir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            (int)($restorer->id ?? $USER->id),
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($users);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
    }

    /**
     * With user data the creator travels as a mapped user; an administrator restoring without it
     * keeps the e-mail and records its single active owner.
     */
    public function test_backup_restore_maps_creator(): void {
        $owner = $this->getDataGenerator()->create_user(['email' => 'personal@example.com']);
        $profe = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $this->make_activity(['creatoremail' => 'profe@example.com', 'creatoruserid' => $owner->id], $course);

        $withusers = $this->backup_and_restore((int)$course->id, true);
        $this->assertEquals($owner->id, $withusers->creatoruserid);
        $this->assertSame('profe@example.com', $withusers->creatoremail);

        $withoutusers = $this->backup_and_restore((int)$course->id, false);
        $this->assertEquals($profe->id, $withoutusers->creatoruserid);
        $this->assertSame('profe@example.com', $withoutusers->creatoremail);
    }

    /**
     * A teacher restoring a backup that names another user's e-mail cannot act as that user: the
     * e-mail is blanked and auto-sync is off, whether the backup carries an id (no users restored)
     * or none (a crafted backup).
     */
    public function test_restore_by_teacher_drops_foreign_creator(): void {
        $teacher = $this->create_restorer('teacher@example.com');
        $victim = $this->getDataGenerator()->create_user(['email' => 'victim@example.com']);
        foreach ([0, (int)$victim->id] as $recordedid) {
            $course = $this->getDataGenerator()->create_course();
            $this->make_activity([
                'creatoremail' => 'Victim@example.com', 'creatoruserid' => $recordedid, 'autosynchours' => 4,
            ], $course);

            $restored = $this->backup_and_restore((int)$course->id, false, $teacher);
            $this->assertSame('', $restored->creatoremail, "recorded id $recordedid");
            $this->assertEquals(0, $restored->creatoruserid);
            $this->assertEquals(0, $restored->autosynchours);
            $this->assertNull(creator::resolve($restored));
        }
    }

    /**
     * Control: restoring as the e-mail's own user keeps the e-mail and records that user.
     */
    public function test_restore_by_email_owner_records_owner(): void {
        $owner = $this->create_restorer('owner@example.com');
        $course = $this->getDataGenerator()->create_course();
        $this->make_activity(['creatoremail' => 'OWNER@example.com', 'creatoruserid' => 0, 'autosynchours' => 4], $course);

        $restored = $this->backup_and_restore((int)$course->id, false, $owner);
        $this->assertSame('OWNER@example.com', $restored->creatoremail);
        $this->assertEquals($owner->id, $restored->creatoruserid);
        $this->assertEquals(4, $restored->autosynchours);
    }

    /**
     * Control: a restore with users maps the original creator, whoever restores.
     */
    public function test_restore_with_users_maps_original_creator_for_teacher(): void {
        $teacher = $this->create_restorer('teacher@example.com');
        $creator = $this->getDataGenerator()->create_user(['email' => 'creator@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $this->make_activity(['creatoremail' => 'creator@example.com', 'creatoruserid' => $creator->id], $course);

        $restored = $this->backup_and_restore((int)$course->id, true, $teacher);
        $this->assertEquals($creator->id, $restored->creatoruserid);
        $this->assertSame('creator@example.com', $restored->creatoremail);
    }

    /**
     * googlemeet_update_instance() refuses a changed organiser e-mail that the editor may not name,
     * without relying on the form, and leaves the activity untouched.
     */
    public function test_update_instance_refuses_foreign_email_for_non_admin(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'victim@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->make_activity(['creatoremail' => 'teacher@example.com', 'creatoruserid' => $teacher->id], $course);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);

        $this->setUser($teacher);
        try {
            googlemeet_update_instance($this->form_data($gm, $cm, ['creatoremail' => 'victim@example.com']));
            $this->fail('update_instance must refuse a foreign organiser e-mail');
        } catch (\moodle_exception $e) {
            $this->assertSame('creatoremail_notyours', $e->errorcode);
        }
        $this->assertSame('teacher@example.com', $DB->get_field('googlemeet', 'creatoremail', ['id' => $gm->id]));
        $this->assertEquals($teacher->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * A teacher may change the organiser to their own Moodle e-mail, which records them as creator.
     */
    public function test_update_instance_accepts_own_moodle_email(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.com']);
        $other = $this->getDataGenerator()->create_user(['email' => 'other@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->make_activity(['creatoremail' => 'other@example.com', 'creatoruserid' => $other->id], $course);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);

        $this->setUser($teacher);
        googlemeet_update_instance($this->form_data($gm, $cm, ['creatoremail' => 'Teacher@example.com']));
        $this->assertSame('Teacher@example.com', $DB->get_field('googlemeet', 'creatoremail', ['id' => $gm->id]));
        $this->assertEquals($teacher->id, $DB->get_field('googlemeet', 'creatoruserid', ['id' => $gm->id]));
    }

    /**
     * The privacy metadata declares the new user reference on the activity table.
     */
    public function test_privacy_metadata_declares_creatoruserid(): void {
        $collection = \mod_googlemeet\privacy\provider::get_metadata(
            new \core_privacy\local\metadata\collection('mod_googlemeet')
        );
        $fields = [];
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'googlemeet') {
                $fields = array_keys($item->get_privacy_fields());
            }
        }
        $this->assertContains('creatoremail', $fields);
        $this->assertContains('creatoruserid', $fields);
    }
}
