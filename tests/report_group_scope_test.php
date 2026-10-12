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

use mod_googlemeet\local\report_builder;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Tests for the group fence of the teacher viewing report.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\local\report_builder::class)]
final class report_group_scope_test extends \advanced_testcase {
    /**
     * Create a course with an activity, two groups of one student each and one student in no group.
     *
     * The activity does not support group mode, so the mode is set on the course only, as in production.
     *
     * @param int $coursemode Group mode of the course.
     * @param int $force Whether the course forces its group mode.
     * @return array [course, cm, googlemeet, context, groupA, groupB, studentA, studentB, studentNone]
     */
    private function setup_site(int $coursemode, int $force = 0): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['groupmode' => $coursemode, 'groupmodeforce' => $force]);
        $googlemeet = $gen->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $groupa = $gen->create_group(['courseid' => $course->id]);
        $groupb = $gen->create_group(['courseid' => $course->id]);
        $studenta = $gen->create_and_enrol($course, 'student');
        $studentb = $gen->create_and_enrol($course, 'student');
        $studentnone = $gen->create_and_enrol($course, 'student');
        $gen->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $gen->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);
        return [$course, $cm, $googlemeet, $context, $groupa, $groupb, $studenta, $studentb, $studentnone];
    }

    /**
     * A non-editing teacher in no group sees no student in a separate-groups activity.
     */
    public function test_teacher_without_group_sees_nobody(): void {
        $this->resetAfterTest();
        [$course, $cm, $googlemeet, $context, $groupa, $groupb] = $this->setup_site(SEPARATEGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);

        $builder = new report_builder($googlemeet, $cm, $context);
        $this->assertSame([], $builder->get_allowed_groups($course, (int)$teacher->id));
        $this->assertSame(0, $builder->build(0)['totalstudents']);
        $this->assertSame(0, $builder->build((int)$groupa->id)['totalstudents']);
        $this->assertSame([], report_builder::export_table($builder->build(0))['rows']);
    }

    /**
     * A teacher in group A sees only group A; asking for group B widens nothing.
     */
    public function test_teacher_in_group_sees_only_own_group(): void {
        $this->resetAfterTest();
        [$course, $cm, $googlemeet, $context, $groupa, $groupb, $studenta, $studentb] =
            $this->setup_site(SEPARATEGROUPS);
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_and_enrol($course, 'teacher');
        $gen->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->setUser($teacher);

        $builder = new report_builder($googlemeet, $cm, $context);
        $this->assertSame([(int)$groupa->id], array_keys($builder->get_allowed_groups($course, (int)$teacher->id)));
        $this->assertSame([(int)$studenta->id], array_keys($builder->build(0)['rows']));
        $this->assertSame([(int)$studenta->id], array_keys($builder->build((int)$groupa->id)['rows']));
        $this->assertArrayNotHasKey((int)$studentb->id, $builder->build((int)$groupb->id)['rows']);
        $this->assertSame(0, $builder->build((int)$groupb->id)['totalstudents']);
    }

    /**
     * A course in separate groups (not forced) fences the report although the activity's own mode is 0.
     */
    public function test_unforced_course_mode_fences_activity_without_mode(): void {
        $this->resetAfterTest();
        [$course, $cm, $googlemeet, $context, $groupa, $groupb, $studenta] = $this->setup_site(SEPARATEGROUPS, 0);
        $this->assertEquals(0, $cm->groupmode);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);

        $builder = new report_builder($googlemeet, $cm, $context);
        $this->assertSame([], $builder->get_allowed_groups($course, (int)$teacher->id));
        $this->assertSame(0, $builder->build(0)['totalstudents']);

        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->assertSame([(int)$studenta->id], array_keys($builder->build(0)['rows']));
        $this->assertSame(0, $builder->build((int)$groupb->id)['totalstudents']);
    }

    /**
     * A forced course group mode is fenced too.
     */
    public function test_forced_course_mode_fences(): void {
        $this->resetAfterTest();
        [$course, $cm, $googlemeet, $context] = $this->setup_site(SEPARATEGROUPS, 1);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);

        $builder = new report_builder($googlemeet, $cm, $context);
        $this->assertSame(0, $builder->build(0)['totalstudents']);
    }

    /**
     * Control: no group mode at all leaves the report unrestricted for a non-editing teacher.
     */
    public function test_no_group_mode_is_unrestricted(): void {
        $this->resetAfterTest();
        [$course, $cm, $googlemeet, $context] = $this->setup_site(NOGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);

        $builder = new report_builder($googlemeet, $cm, $context);
        $this->assertSame(3, $builder->build(0)['totalstudents']);
    }
}
