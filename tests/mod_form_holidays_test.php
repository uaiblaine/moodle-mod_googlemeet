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
 * Every holiday row of the activity form follows the recurrence switch.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_mod_form::class)]
final class mod_form_holidays_test extends \advanced_testcase {
    /**
     * Forget the request parameters a test set.
     */
    protected function tearDown(): void {
        unset($_POST['holiday_repeats'], $_POST['holiday_add_fields']);
        parent::tearDown();
    }

    /**
     * Build the "add activity" form and return its QuickForm.
     *
     * @return \MoodleQuickForm
     */
    private function build_form(): \MoodleQuickForm {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $current = (object)[
            'instance' => '',
            'course' => $course->id,
            'section' => 0,
            'coursemodule' => '',
            'add' => 'googlemeet',
            'modulename' => 'googlemeet',
        ];
        $form = new \mod_googlemeet_mod_form($current, 0, null, $course);
        return (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
    }

    /**
     * Rows added with the "Add" button (more rows than the database holds) are disabled with the recurrence off.
     */
    public function test_added_holiday_rows_follow_recurrence(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // The form was shown with two rows and the teacher pressed "Add" once more: three rows.
        $_POST['holiday_repeats'] = 2;
        $_POST['holiday_add_fields'] = 'Add';

        $mform = $this->build_form();

        $this->assertTrue($mform->elementExists('holidayname[2]'));
        $disabled = $mform->_dependencies['addmultiply']['notchecked']['1'];
        foreach (['holidayname', 'holidaystartdate', 'holidayenddate'] as $field) {
            for ($i = 0; $i < 3; $i++) {
                $this->assertContains("{$field}[{$i}]", $disabled);
            }
        }
    }
}
