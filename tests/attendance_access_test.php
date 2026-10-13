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

use mod_googlemeet\local\attendance\report;
use mod_googlemeet\local\attendance\service;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Who may change the attendance of a session (attendance.php) and whose rows a teacher is shown.
 *
 * The page writes are judged by what they wrote: under PHPUnit every outcome of an action ends in a
 * redirect, which throws before its message is stored.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(report::class)]
final class attendance_access_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var int Session id. */
    private $eventid;
    /** @var \stdClass Non-editing teacher, member of group A. */
    private $teacher;
    /** @var \stdClass Editing teacher (holds moodle/site:accessallgroups), in no group. */
    private $editor;
    /** @var \stdClass[] Students: a (group A), b (group B), none (no group). */
    private $students = [];
    /** @var int[] Attendance row ids: a, b (matched) and guest (unmatched). */
    private $rows = [];

    /**
     * Course in $groupmode with two groups, a past session and its attendance: students a and b matched,
     * one participant unmatched; student "none" did not attend.
     *
     * @param int $groupmode Course group mode (the activity has none of its own).
     * @return void
     */
    private function build(int $groupmode): void {
        global $DB;
        $this->resetAfterTest();
        set_config('attendanceenabled', 1, 'googlemeet');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['groupmode' => $groupmode]);
        $gm = $gen->create_module('googlemeet', ['course' => $this->course->id,
            'url' => 'https://meet.google.com/abc-defg-hij']);
        // The organiser is somebody else: the page offers no Google login to these viewers.
        $DB->update_record('googlemeet', (object)['id' => $gm->id, 'attendanceenabled' => 1,
            'creatoremail' => 'organiser@example.com']);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $gm->id, $this->course->id, false, MUST_EXIST);

        $groupa = $gen->create_group(['courseid' => $this->course->id]);
        $groupb = $gen->create_group(['courseid' => $this->course->id]);
        $this->teacher = $gen->create_and_enrol($this->course, 'teacher');
        $this->editor = $gen->create_and_enrol($this->course, 'editingteacher');
        // Names of their own: the tests look for them in the page.
        foreach (['a' => ['Alda', 'Grupoa'], 'b' => ['Bruno', 'Grupob'], 'none' => ['Nadia', 'Semgrupo']] as $key => $name) {
            $this->students[$key] = $gen->create_and_enrol(
                $this->course,
                'student',
                ['firstname' => $name[0], 'lastname' => $name[1]]
            );
        }
        $gen->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teacher->id]);
        $gen->create_group_member(['groupid' => $groupa->id, 'userid' => $this->students['a']->id]);
        $gen->create_group_member(['groupid' => $groupb->id, 'userid' => $this->students['b']->id]);

        $start = time() - 3 * HOURSECS;
        $this->eventid = (int)$DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $gm->id,
            'eventdate' => $start,
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);
        $this->rows['a'] = $this->insert_row((int)$this->students['a']->id, 'Alda G', $start);
        $this->rows['b'] = $this->insert_row((int)$this->students['b']->id, 'Bruno G', $start);
        $this->rows['guest'] = $this->insert_row(0, 'Visitante Desconhecido', $start);
    }

    /**
     * Insert one attendance row of the session.
     *
     * @param int $userid Matched user, 0 for an unmatched participant.
     * @param string $displayname Meet display name.
     * @param int $start Session start.
     * @return int
     */
    private function insert_row(int $userid, string $displayname, int $start): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_attendance', (object)[
            'googlemeetid' => $this->googlemeet->id,
            'eventid' => $this->eventid,
            'userid' => $userid,
            'displayname' => $displayname,
            'googleuserid' => 'users/' . random_string(8),
            'participanttype' => 'signedin',
            'matchedby' => $userid ? service::MATCH_MANUAL : '',
            'timejoined' => $start,
            'timeleft' => $start + 1800,
            'durationseconds' => 1800,
            'sessions' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Let the non-editing teacher role manage attendance in the course.
     *
     * @return void
     */
    private function allow_teacher_to_manage(): void {
        global $DB;
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability(
            'mod/googlemeet:manageattendance',
            CAP_ALLOW,
            $roleid,
            \context_course::instance($this->course->id)->id,
            true
        );
    }

    /**
     * Run attendance.php as a request would.
     *
     * @param array $params Request parameters.
     * @param bool $post Send them as POST (with the sesskey) rather than GET.
     * @return string|null The page HTML, or null when it ended in a redirect.
     */
    private function run_page(array $params, bool $post): ?string {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER, $COURSE;

        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        $params = ['id' => $this->cm->id] + $params;
        if ($post) {
            $_POST = $params + ['sesskey' => sesskey()];
        } else {
            $_GET = $params;
        }
        ob_start();
        try {
            require($CFG->dirroot . '/mod/googlemeet/attendance.php');
            return ob_get_contents();
        } catch (\moodle_exception $e) {
            // Under PHPUnit, redirect() throws instead of sending a Location header.
            if ($e->errorcode !== 'redirecterrordetected') {
                throw $e;
            }
            return null;
        } finally {
            ob_end_clean();
            $_POST = [];
            $_GET = [];
        }
    }

    /**
     * Run a page write and report whether it was refused for a missing capability.
     *
     * @param array $params Request parameters.
     * @return bool True when attendance.php threw required_capability_exception.
     */
    private function denied(array $params): bool {
        try {
            $this->run_page($params, true);
            return false;
        } catch (\required_capability_exception $e) {
            return true;
        }
    }

    /**
     * A non-editing teacher (viewreports only) can neither unlink nor relink a participant; an editing
     * teacher can.
     */
    public function test_link_requires_manageattendance(): void {
        global $DB;
        $this->build(NOGROUPS);
        $unlink = ['session' => $this->eventid, 'action' => 'link', 'attendanceid' => $this->rows['a'], 'userid' => 0];

        $this->setUser($this->teacher);
        $this->assertTrue($this->denied($unlink), 'A viewreports-only teacher changed a match.');
        $this->assertEquals(
            $this->students['a']->id,
            $DB->get_field('googlemeet_attendance', 'userid', ['id' => $this->rows['a']])
        );
        $link = ['userid' => (int)$this->students['none']->id, 'attendanceid' => $this->rows['guest']] + $unlink;
        $this->assertTrue($this->denied($link));
        $this->assertEquals(0, $DB->get_field('googlemeet_attendance', 'userid', ['id' => $this->rows['guest']]));

        // Control: the same POST from an editing teacher unlinks.
        $this->setUser($this->editor);
        $this->assertFalse($this->denied($unlink));
        $row = $DB->get_record('googlemeet_attendance', ['id' => $this->rows['a']]);
        $this->assertEquals(0, $row->userid);
        $this->assertSame(service::MATCH_UNLINKED, $row->matchedby);
    }

    /**
     * Refetching queues a Google API call: a viewreports-only teacher is refused, an editing teacher queues it.
     */
    public function test_refetch_requires_manageattendance(): void {
        global $DB;
        $this->build(NOGROUPS);
        $refetch = ['session' => $this->eventid, 'action' => 'refetch'];

        $this->setUser($this->teacher);
        $this->assertTrue($this->denied($refetch), 'A viewreports-only teacher queued a fetch.');
        $this->assertFalse($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $this->eventid]));

        $this->setUser($this->editor);
        $this->assertFalse($this->denied($refetch));
        $this->assertSame(
            service::STATUS_PENDING,
            $DB->get_field('googlemeet_attendance_sync', 'status', ['eventid' => $this->eventid])
        );
    }

    /**
     * A refetch link with a valid sesskey (a GET) queues nothing; the same request as a POST does.
     */
    public function test_refetch_is_post_only(): void {
        global $DB;
        $this->build(NOGROUPS);
        $this->setUser($this->editor);

        $this->assertNull($this->run_page(['session' => $this->eventid, 'action' => 'refetch', 'sesskey' => sesskey()], false));
        $this->assertFalse($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $this->eventid]));

        $this->assertNull($this->run_page(['session' => $this->eventid, 'action' => 'refetch'], true));
        $this->assertTrue($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $this->eventid]));
    }

    /**
     * The page shows the refetch, link and unlink controls only to a user who can manage attendance, and
     * the refetch control is a POST form, not a link carrying the sesskey.
     */
    public function test_controls_follow_manageattendance(): void {
        $this->build(NOGROUPS);

        $this->setUser($this->teacher);
        $html = $this->run_page(['session' => $this->eventid], false);
        $this->assertStringContainsString('Visitante Desconhecido', $html);
        $this->assertStringNotContainsString('googlemeet-attendance-link', $html);
        $this->assertStringNotContainsString('googlemeet-attendance-unlink', $html);
        $this->assertStringNotContainsString(get_string('attendance_refetch', 'googlemeet'), $html);

        $this->setUser($this->editor);
        $html = $this->run_page(['session' => $this->eventid], false);
        $this->assertStringContainsString('googlemeet-attendance-link', $html);
        $this->assertStringContainsString('googlemeet-attendance-unlink', $html);
        $this->assertStringContainsString(get_string('attendance_refetch', 'googlemeet'), $html);
        $this->assertStringNotContainsString('action=refetch&amp;sesskey', $html);
        $this->assertMatchesRegularExpression('~<form method="post"[^>]*>(?:(?!</form>).)*name="action" value="refetch"~s', $html);
    }

    /**
     * In separate groups a non-editing teacher of group A is shown group A only: no student of group B,
     * no student without a group, and no unmatched participant (they cannot link one). An editing
     * teacher, who can access all groups, is shown everybody.
     */
    public function test_separate_groups_fence_rows(): void {
        $this->build(SEPARATEGROUPS);
        $a = (int)$this->students['a']->id;
        $b = (int)$this->students['b']->id;
        $none = (int)$this->students['none']->id;

        $this->setUser($this->teacher);
        $rows = report::session_rows($this->googlemeet, $this->eventid, null, (int)$this->teacher->id);
        $this->assertSame([$a], array_column($rows, 'userid'));
        $this->assertSame([report::ROW_PRESENT], array_column($rows, 'kind'));

        $html = $this->run_page(['session' => $this->eventid], false);
        $this->assertStringContainsString('Grupoa', $html);
        $this->assertStringNotContainsString('Grupob', $html);
        $this->assertStringNotContainsString('Semgrupo', $html);
        $this->assertStringNotContainsString('Visitante Desconhecido', $html);

        // Control: the editing teacher holds accessallgroups and is not fenced.
        $this->setUser($this->editor);
        $rows = report::session_rows($this->googlemeet, $this->eventid, null, (int)$this->editor->id);
        $this->assertEqualsCanonicalizing([$a, $b, 0, $none], array_column($rows, 'userid'));
    }

    /**
     * A fenced teacher who may manage attendance sees the unmatched participants and may link them, but
     * only to students of their own groups, and cannot unlink a participant of another group.
     */
    public function test_separate_groups_fence_links(): void {
        global $DB;
        $this->build(SEPARATEGROUPS);
        $this->allow_teacher_to_manage();
        $a = (int)$this->students['a']->id;
        $b = (int)$this->students['b']->id;
        $teacherid = (int)$this->teacher->id;
        $this->setUser($this->teacher);

        $rows = report::session_rows($this->googlemeet, $this->eventid, null, $teacherid);
        $this->assertEqualsCanonicalizing([$a, 0], array_column($rows, 'userid'));
        $this->assertSame([$a], array_keys(array_diff_key(
            report::link_candidates($this->googlemeet, $teacherid),
            [$teacherid => true]
        )));
        $this->assertFalse(report::can_link($this->googlemeet, $this->eventid, $this->rows['guest'], $b, $teacherid));
        $this->assertFalse(report::can_link($this->googlemeet, $this->eventid, $this->rows['b'], 0, $teacherid));
        $this->assertTrue(report::can_link($this->googlemeet, $this->eventid, $this->rows['guest'], $a, $teacherid));

        $link = ['session' => $this->eventid, 'action' => 'link'];
        $this->assertFalse($this->denied($link + ['attendanceid' => $this->rows['guest'], 'userid' => $b]));
        $this->assertEquals(0, $DB->get_field('googlemeet_attendance', 'userid', ['id' => $this->rows['guest']]));
        $this->assertFalse($this->denied($link + ['attendanceid' => $this->rows['b'], 'userid' => 0]));
        $this->assertEquals($b, $DB->get_field('googlemeet_attendance', 'userid', ['id' => $this->rows['b']]));

        // Control: unlinking the group A student, then linking the participant to them, both go through.
        $this->assertFalse($this->denied($link + ['attendanceid' => $this->rows['a'], 'userid' => 0]));
        $this->assertFalse($this->denied($link + ['attendanceid' => $this->rows['guest'], 'userid' => $a]));
        $this->assertEquals($a, $DB->get_field('googlemeet_attendance', 'userid', ['id' => $this->rows['guest']]));
    }

    /**
     * Store the fetch outcome of the session with the counts a fetch over everybody records.
     *
     * @param int $participants Participants of the whole session.
     * @param int $matched Of those, matched to a user.
     * @return void
     */
    private function insert_done_sync(int $participants, int $matched): void {
        global $DB;
        $DB->insert_record('googlemeet_attendance_sync', (object)[
            'googlemeetid' => $this->googlemeet->id,
            'eventid' => $this->eventid,
            'status' => service::STATUS_DONE,
            'participants' => $participants,
            'matched' => $matched,
            'timemodified' => time(),
        ]);
    }

    /**
     * The participants cell of the session in the sessions list of a page.
     *
     * @param int $matched Matched participants expected.
     * @param int $total Participants expected.
     * @return string
     */
    private function counts_cell(int $matched, int $total): string {
        return '<td class="cell c2">'
            . get_string('attendance_counts', 'googlemeet', (object)['matched' => $matched, 'total' => $total])
            . '</td>';
    }

    /**
     * In separate groups the session counts a fenced teacher is shown cover the rows they are shown:
     * group A's matched student, plus the unmatched participant once they may manage attendance. The
     * editing teacher, who is not fenced, is shown the stored counts over everybody.
     */
    public function test_separate_groups_fence_counts(): void {
        $this->build(SEPARATEGROUPS);
        // Students a and b matched, the guest unmatched: what a fetch over everybody stores.
        $this->insert_done_sync(3, 2);
        $teacherid = (int)$this->teacher->id;

        $this->setUser($this->teacher);
        $session = report::sessions($this->googlemeet, time(), $teacherid)[$this->eventid];
        $this->assertSame([1, 1], [$session->matched, $session->participants]);
        $html = $this->run_page([], false);
        $this->assertStringContainsString($this->counts_cell(1, 1), $html);
        $this->assertStringNotContainsString($this->counts_cell(2, 3), $html);

        $this->allow_teacher_to_manage();
        $rows = report::session_rows($this->googlemeet, $this->eventid, null, $teacherid);
        $this->assertCount(2, $rows);
        $html = $this->run_page([], false);
        $this->assertStringContainsString($this->counts_cell(1, 2), $html);

        // Control: the unfenced editing teacher keeps the stored counts.
        $this->setUser($this->editor);
        $html = $this->run_page([], false);
        $this->assertStringContainsString($this->counts_cell(2, 3), $html);
    }

    /**
     * Sessions are not fenced: a manager fenced to group A lists the session, is offered its refetch and
     * may queue it, as an unfenced manager may. A refetch keeps the manual decisions of every group
     * ({@see service::store()}), so there is no group whose session they could not ask to read again.
     */
    public function test_separate_groups_fenced_manager_may_refetch(): void {
        global $DB;
        $this->build(SEPARATEGROUPS);
        $this->allow_teacher_to_manage();
        $teacherid = (int)$this->teacher->id;
        $this->setUser($this->teacher);
        $this->assertNotNull(report::fenced_userids($this->googlemeet, $teacherid), 'The teacher is not fenced.');

        $this->assertArrayHasKey($this->eventid, report::sessions($this->googlemeet, time(), $teacherid));
        $html = $this->run_page(['session' => $this->eventid], false);
        $this->assertMatchesRegularExpression('~<form method="post"[^>]*>(?:(?!</form>).)*name="action" value="refetch"~s', $html);

        $this->assertFalse($this->denied(['session' => $this->eventid, 'action' => 'refetch']));
        $this->assertSame(
            service::STATUS_PENDING,
            $DB->get_field('googlemeet_attendance_sync', 'status', ['eventid' => $this->eventid])
        );
    }
}
