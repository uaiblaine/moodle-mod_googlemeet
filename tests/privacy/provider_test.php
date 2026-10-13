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
 * Tests for the privacy provider of mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_googlemeet\local\practice_attempts;
use mod_googlemeet\local\reminders;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Every user-linked table of the plugin, for two users in two activities, through the whole provider.
 *
 * The fixture: user one has data in both activities, user two only in the first. Each activity
 * also holds an unmatched Meet participant (userid 0); the one in the first activity carries user
 * one's e-mail, which pins the choice that per-user requests decide on the recorded userid only.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass[] Activities, keyed 1 and 2. */
    private array $gm = [];

    /** @var \context_module[] Contexts, keyed 1 and 2. */
    private array $ctx = [];

    /** @var \stdClass[] Recordings, keyed 1 and 2 (one per activity). */
    private array $rec = [];

    /** @var \stdClass[] Users, keyed 1 and 2. */
    private array $user = [];

    /** @var \stdClass A user without any data, the control of the lookups. */
    private \stdClass $nobody;

    /**
     * Build the fixture.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('mod_googlemeet');
        $course = $gen->create_course();
        $this->user[1] = $gen->create_and_enrol($course, 'student', ['email' => 'one@example.com']);
        $this->user[2] = $gen->create_and_enrol($course, 'student', ['email' => 'two@example.com']);
        $this->nobody = $gen->create_and_enrol($course, 'student');
        $now = time();

        foreach ([1, 2] as $i) {
            $this->gm[$i] = $gen->create_module('googlemeet', ['course' => $course->id]);
            $this->ctx[$i] = \context_module::instance($this->gm[$i]->cmid);
            $this->rec[$i] = $plugingen->create_recording([
                'googlemeetid' => $this->gm[$i]->id,
                'name' => 'Class ' . $i,
                'transcripttext' => 'Speaker ' . $i . ': hello',
            ]);
        }
        $users = [1 => [1, 2], 2 => [1]];

        foreach ($users as $i => $userkeys) {
            $gmid = (int)$this->gm[$i]->id;
            $events = [];
            foreach ([1, 2] as $n) {
                $events[$n] = (int)$DB->insert_record('googlemeet_events', (object)[
                    'googlemeetid' => $gmid,
                    'eventdate' => $now + $n * DAYSECS,
                    'duration' => HOURSECS,
                    'timemodified' => $now,
                ]);
            }
            foreach ($userkeys as $u) {
                $userid = (int)$this->user[$u]->id;
                // User one gets every reminder of the first activity: three rows in one context.
                $reminders = ($u === 1 && $i === 1)
                    ? [[$events[1], reminders::KIND_MINUTES], [$events[1], reminders::KIND_HOURS],
                        [$events[2], reminders::KIND_MINUTES]]
                    : [[$events[1], reminders::KIND_MINUTES]];
                foreach ($reminders as [$eventid, $kind]) {
                    $DB->insert_record('googlemeet_notify_done', (object)[
                        'eventid' => $eventid, 'userid' => $userid, 'kind' => $kind, 'timesent' => $now,
                    ]);
                }
                $DB->insert_record('googlemeet_recording_subs', (object)[
                    'googlemeetid' => $gmid, 'userid' => $userid, 'timecreated' => $now,
                ]);
                $DB->insert_record('googlemeet_recording_progress', (object)[
                    'recordingid' => $this->rec[$i]->id, 'userid' => $userid, 'watchedseconds' => 60,
                    'completed' => 0, 'markedviewed' => 1, 'timecreated' => $now, 'timemodified' => $now,
                ]);
                practice_attempts::record($gmid, (int)$this->rec[$i]->id, 100 + $i, $userid, true);
                $this->insert_attendance($gmid, $events[1], $userid, 'Student ' . $u, null, $now);
            }
            // An unmatched participant; in the first activity it carries user one's e-mail.
            $this->insert_attendance($gmid, $events[1], 0, 'Guest ' . $i, $i === 1 ? 'one@example.com' : null, $now);
            $DB->insert_record('googlemeet_attendance_sync', (object)[
                'googlemeetid' => $gmid, 'eventid' => $events[2], 'status' => 'error', 'attempts' => 1,
                'nextattempt' => $now + HOURSECS, 'conferences' => 1, 'participants' => 2, 'matched' => 1,
                'message' => 'Google said no', 'timemodified' => $now,
            ]);
            $DB->insert_record('googlemeet_sync_log', (object)[
                'googlemeetid' => $gmid, 'kind' => 'calupdate', 'status' => 'error', 'timequeued' => $now,
                'timestarted' => $now, 'timefinished' => $now, 'message' => 'Activity ' . $i . ' failed',
            ]);
            // Published by user two in the first activity, by user one in the second.
            $plugingen->create_ai_analysis([
                'recordingid' => $this->rec[$i]->id,
                'summary' => 'Summary ' . $i,
                'chapters' => json_encode([['title' => 'Chapter ' . $i]]),
                'transcript' => 'Speaker ' . $i . ' said something personal',
                'error' => 'An earlier error ' . $i,
                'reviewed' => 1,
                'timereviewed' => $now,
                'reviewedby' => (int)$this->user[$i === 1 ? 2 : 1]->id,
            ]);
        }
    }

    /**
     * Insert one attendance row.
     *
     * @param int $gmid Activity id.
     * @param int $eventid Session id.
     * @param int $userid Matched user, or 0.
     * @param string $name Meet display name.
     * @param string|null $email Participant e-mail.
     * @param int $now Time.
     * @return void
     */
    private function insert_attendance(int $gmid, int $eventid, int $userid, string $name, ?string $email, int $now): void {
        global $DB;

        $DB->insert_record('googlemeet_attendance', (object)[
            'googlemeetid' => $gmid, 'eventid' => $eventid, 'userid' => $userid, 'email' => $email,
            'displayname' => $name, 'googleuserid' => 'g-' . $name, 'participanttype' => 'signedin',
            'matchedby' => $userid ? 'name' : '', 'timejoined' => $now, 'timeleft' => $now + 600,
            'durationseconds' => 600, 'sessions' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Rows of the user-linked tables in an activity, per table (the AI rows count reviewers only).
     *
     * @param int $i Activity key.
     * @param int|null $userid Only this user's rows, or all.
     * @return array Table name => count.
     */
    private function counts(int $i, ?int $userid = null): array {
        global $DB;

        $gmid = (int)$this->gm[$i]->id;
        $u = $userid === null ? '' : ' AND userid = ' . $userid;
        return [
            'notify_done' => $DB->count_records_select(
                'googlemeet_notify_done',
                "eventid IN (SELECT id FROM {googlemeet_events} WHERE googlemeetid = ?)" . $u,
                [$gmid]
            ),
            'subs' => $DB->count_records_select('googlemeet_recording_subs', 'googlemeetid = ?' . $u, [$gmid]),
            'progress' => $DB->count_records_select(
                'googlemeet_recording_progress',
                'recordingid = ?' . $u,
                [$this->rec[$i]->id]
            ),
            'attempts' => $DB->count_records_select('googlemeet_practice_attempts', 'googlemeetid = ?' . $u, [$gmid]),
            'attendance' => $DB->count_records_select('googlemeet_attendance', 'googlemeetid = ?' . $u, [$gmid]),
            'reviewer' => $DB->count_records_select(
                'googlemeet_ai_analysis',
                'recordingid = ? AND reviewedby ' . ($userid === null ? '> 0' : '= ' . $userid),
                [$this->rec[$i]->id]
            ),
        ];
    }

    /**
     * The plugin counts as compliant: metadata provider plus a data provider.
     *
     * Core's compliance test sweeps every component but is not in the plugin's testsuite, which is
     * all moodle-plugin-ci runs ({@see \core_privacy\manager::component_is_compliant()}).
     *
     * @return void
     */
    public function test_the_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('mod_googlemeet'));
    }

    /**
     * Every personal column is declared, including the unmatched participants' and the sync log's.
     *
     * @return void
     */
    public function test_metadata_declares_every_personal_field(): void {
        $fields = [];
        foreach (provider::get_metadata(new collection('mod_googlemeet'))->get_collection() as $item) {
            if ($item instanceof database_table) {
                $fields[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        $attendancefields = ['userid', 'email', 'displayname', 'googleuserid', 'participanttype', 'matchedby', 'eventid',
            'sessions', 'timejoined', 'timeleft', 'durationseconds', 'timecreated', 'timemodified'];
        foreach ($attendancefields as $field) {
            $this->assertContains($field, $fields['googlemeet_attendance'], $field);
        }
        $this->assertContains('googlemeetid', $fields['googlemeet_practice_attempts']);
        $this->assertContains('message', $fields['googlemeet_sync_log'] ?? []);
        $this->assertContains('error', $fields['googlemeet_ai_analysis']);
        $this->assertContains('transcript', $fields['googlemeet_ai_analysis']);
        $this->assertContains('reviewedby', $fields['googlemeet_ai_analysis']);
        $this->assertEqualsCanonicalizing(['creatoremail', 'creatoruserid'], $fields['googlemeet']);
    }

    /**
     * Contexts follow the recorded userid: the unmatched row with user one's e-mail adds nothing.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->assertEqualsCanonicalizing(
            [$this->ctx[1]->id, $this->ctx[2]->id],
            array_map('intval', provider::get_contexts_for_userid((int)$this->user[1]->id)->get_contextids())
        );
        $this->assertEqualsCanonicalizing(
            [$this->ctx[1]->id],
            array_map('intval', provider::get_contexts_for_userid((int)$this->user[2]->id)->get_contextids())
        );
        $this->assertSame([], provider::get_contexts_for_userid((int)$this->nobody->id)->get_contextids());
    }

    /**
     * Users in a context: the matched ones and the reviewer, never the unmatched participant (0).
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $list = new userlist($this->ctx[1], 'mod_googlemeet');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing(
            [(int)$this->user[1]->id, (int)$this->user[2]->id],
            array_map('intval', $list->get_userids())
        );

        $list = new userlist($this->ctx[2], 'mod_googlemeet');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing([(int)$this->user[1]->id], array_map('intval', $list->get_userids()));
    }

    /**
     * Every reminder of a context is exported, with its kind, alongside the rest of the user's data.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $user = $this->user[1];
        $this->export_context_data_for_user((int)$user->id, $this->ctx[1], 'mod_googlemeet');

        $writer = writer::with_context($this->ctx[1]);
        $root = $writer->get_data([]);
        $this->assertSame($this->gm[1]->name, $root->name);
        $this->assertCount(3, $root->reminders);
        $kinds = array_count_values(array_column($root->reminders, 'kind'));
        $this->assertSame(
            [get_string('minutesbefore', 'googlemeet') => 2, get_string('notifyhoursbefore', 'googlemeet') => 1],
            $kinds
        );

        $this->assertNotEmpty($writer->get_data([get_string('recordingnotificationsubscription', 'googlemeet')]));
        $progress = $writer->get_related_data(
            [get_string('recordingprogress', 'googlemeet')],
            'recording_' . $this->rec[1]->id
        );
        $this->assertSame(60, $progress->watchedseconds);
        $attempts = $writer->get_related_data(
            [get_string('privacy:practiceattempts', 'googlemeet')],
            'recording_' . $this->rec[1]->id
        );
        $this->assertCount(1, $attempts->attempts);

        // Only the user's own attendance row: the unmatched row sharing the e-mail is not theirs.
        $attendance = $writer->get_data([get_string('privacy:attendance', 'googlemeet')]);
        $this->assertSame(['Student 1'], array_column($attendance->sessions, 'meetname'));

        // User one reviewed nothing in the first activity, and user two's review is not exported.
        $this->assertSame([], (array)$writer->get_related_data(
            [get_string('privacy:aireviews', 'googlemeet')],
            'recording_' . $this->rec[1]->id
        ));
        $this->assertFalse(writer::with_context($this->ctx[2])->has_any_data());

        writer::reset();
        $this->export_context_data_for_user((int)$user->id, $this->ctx[2], 'mod_googlemeet');
        $writer = writer::with_context($this->ctx[2]);
        $this->assertCount(1, $writer->get_data([])->reminders);
        $review = $writer->get_related_data(
            [get_string('privacy:aireviews', 'googlemeet')],
            'recording_' . $this->rec[2]->id
        );
        $this->assertSame((int)$this->rec[2]->id, $review->recordingid);
    }

    /**
     * Deleting one user in one context leaves the other user, the other context and the unmatched rows.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        $u1 = (int)$this->user[1]->id;
        $u2 = (int)$this->user[2]->id;
        $before2 = $this->counts(1, $u2);
        $beforeother = $this->counts(2);
        $this->assertSame(3, $this->counts(1, $u1)['notify_done']);

        provider::delete_data_for_user(new approved_contextlist($this->user[1], 'mod_googlemeet', [$this->ctx[1]->id]));

        $this->assertSame(
            ['notify_done' => 0, 'subs' => 0, 'progress' => 0, 'attempts' => 0, 'attendance' => 0, 'reviewer' => 0],
            $this->counts(1, $u1)
        );
        $this->assertSame($before2, $this->counts(1, $u2));
        $this->assertSame($beforeother, $this->counts(2));
        // The unmatched row with user one's e-mail stays: the request is about the recorded userid.
        $this->assertSame(1, $this->counts(1, 0)['attendance']);
    }

    /**
     * Deleting a list of users clears their rows and forgets them as reviewer, keeping the content.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $u1 = (int)$this->user[1]->id;
        $u2 = (int)$this->user[2]->id;
        $before1 = $this->counts(1, $u1);
        $this->assertSame(1, $this->counts(1, $u2)['reviewer']);

        provider::delete_data_for_users(new approved_userlist($this->ctx[1], 'mod_googlemeet', [$u2]));

        $this->assertSame(
            ['notify_done' => 0, 'subs' => 0, 'progress' => 0, 'attempts' => 0, 'attendance' => 0, 'reviewer' => 0],
            $this->counts(1, $u2)
        );
        $this->assertSame($before1, $this->counts(1, $u1));
        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $this->rec[1]->id]);
        $this->assertSame('Summary 1', $analysis->summary);
        $this->assertSame(1, (int)$analysis->reviewed);
    }

    /**
     * Deleting a context removes every personal row there, including the AI analyses generated from
     * the participants' speech, and leaves the other activity alone.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $gm1 = (int)$this->gm[1]->id;
        $gm2 = (int)$this->gm[2]->id;
        $beforeother = $this->counts(2);
        $analysis2 = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $this->rec[2]->id]);

        provider::delete_data_for_all_users_in_context($this->ctx[1]);

        $this->assertSame(
            ['notify_done' => 0, 'subs' => 0, 'progress' => 0, 'attempts' => 0, 'attendance' => 0, 'reviewer' => 0],
            $this->counts(1)
        );
        // Unmatched participants go too, and the fetch state does not read those sessions again.
        $this->assertSame(0, $DB->count_records('googlemeet_attendance', ['googlemeetid' => $gm1]));
        $sync = $DB->get_record('googlemeet_attendance_sync', ['googlemeetid' => $gm1]);
        $this->assertSame('done', $sync->status);
        $this->assertNull($sync->message);
        $this->assertSame(0, $DB->count_records('googlemeet_sync_log', ['googlemeetid' => $gm1]));

        // The analyses are generated from the participants' speech: an erasure removes them whole.
        $this->assertFalse($DB->record_exists('googlemeet_ai_analysis', ['recordingid' => $this->rec[1]->id]));
        $this->assertEmpty($DB->get_field('googlemeet_recordings', 'transcripttext', ['id' => $this->rec[1]->id]));

        // The other activity is untouched.
        $this->assertSame($beforeother, $this->counts(2));
        $this->assertSame(1, $DB->count_records('googlemeet_attendance', ['googlemeetid' => $gm2, 'userid' => 0]));
        $this->assertSame(1, $DB->count_records('googlemeet_sync_log', ['googlemeetid' => $gm2]));
        $this->assertSame('Google said no', $DB->get_field('googlemeet_attendance_sync', 'message', ['googlemeetid' => $gm2]));
        $this->assertEquals($analysis2, $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $this->rec[2]->id]));
        $this->assertSame(
            'Speaker 2: hello',
            $DB->get_field('googlemeet_recordings', 'transcripttext', ['id' => $this->rec[2]->id])
        );
    }
}
