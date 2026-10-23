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

namespace mod_googlemeet\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\user_preference;
use core_privacy\local\request\writer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The recordings layout preference (cards or list) under the Privacy API.
 *
 * view.php writes it from the rview parameter and recordings_list reads it, both through
 * {@see provider::RECORDINGSVIEW_PREFERENCE}; a data request must declare and export it.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class recordings_view_preference_test extends \advanced_testcase {
    /**
     * The metadata names the preference.
     *
     * @return void
     */
    public function test_metadata_declares_the_recordings_view_preference(): void {
        $names = [];
        foreach (provider::get_metadata(new collection('mod_googlemeet'))->get_collection() as $item) {
            if ($item instanceof user_preference) {
                $names[] = $item->get_name();
            }
        }
        $this->assertContains('mod_googlemeet_recordings_view', $names);
        $this->assertSame('mod_googlemeet_recordings_view', provider::RECORDINGSVIEW_PREFERENCE);
    }

    /**
     * Each user's export carries that user's own layout, never another user's.
     *
     * @return void
     */
    public function test_export_carries_the_users_own_layout(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        set_user_preference(provider::RECORDINGSVIEW_PREFERENCE, 'cards', $one);
        set_user_preference(provider::RECORDINGSVIEW_PREFERENCE, 'list', $two);

        provider::export_user_preferences((int)$one->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('mod_googlemeet');
        $this->assertSame('cards', $prefs->mod_googlemeet_recordings_view->value);
        $this->assertSame(
            get_string('privacy:metadata:preference:recordingsview', 'mod_googlemeet'),
            $prefs->mod_googlemeet_recordings_view->description
        );

        writer::reset();
        provider::export_user_preferences((int)$two->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('mod_googlemeet');
        $this->assertSame('list', $prefs->mod_googlemeet_recordings_view->value);
    }

    /**
     * A user who never chose a layout exports no layout, while one who did (the control) exports it.
     *
     * @return void
     */
    public function test_export_without_a_layout_writes_none(): void {
        $this->resetAfterTest();
        $chose = $this->getDataGenerator()->create_user();
        $never = $this->getDataGenerator()->create_user();
        set_user_preference(provider::RECORDINGSVIEW_PREFERENCE, 'cards', $chose);

        provider::export_user_preferences((int)$chose->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('mod_googlemeet');
        $this->assertTrue(isset($prefs->mod_googlemeet_recordings_view));

        writer::reset();
        provider::export_user_preferences((int)$never->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('mod_googlemeet');
        $this->assertFalse(isset($prefs->mod_googlemeet_recordings_view));
    }
}
