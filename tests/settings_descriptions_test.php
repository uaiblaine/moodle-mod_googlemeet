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

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Plugin settings take their descriptions and option labels from lang strings.
 *
 * settings.php is not a class or function, so there is nothing to name as covered.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class settings_descriptions_test extends \advanced_testcase {
    /**
     * The plugin's settings page.
     *
     * @return \admin_settingpage
     */
    private function settings_page(): \admin_settingpage {
        $page = admin_get_root(true, true)->locate('modsettinggooglemeet');
        $this->assertInstanceOf(\admin_settingpage::class, $page);
        return $page;
    }

    /**
     * Each setting is described by its own <name>_desc string.
     */
    public function test_settings_use_desc_strings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $settings = $this->settings_page()->settings;

        $expected = [
            'notificationexpanded' => 'notificationexpanded_desc',
            'notify' => 'notify_desc',
            'minutesbefore' => 'minutesbefore_desc',
            'emailcontent' => 'emailcontent_desc',
        ];
        foreach ($expected as $name => $key) {
            $this->assertSame(get_string($key, 'googlemeet'), $settings->{'googlemeet' . $name}->description, $name);
        }
        $this->assertFalse(get_string_manager()->string_exists('notifycationexpanded_desc', 'googlemeet'));
    }

    /**
     * The AI model options keep the product name and take the note in brackets from a lang string.
     */
    public function test_ai_model_labels_are_translatable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = $this->settings_page()->settings->googlemeetaimodel;
        $setting->load_choices();

        $notes = [
            'gemini-3.5-flash' => ['Gemini 3.5 Flash', 'aimodel_note_default'],
            'gemini-3.1-flash-lite' => ['Gemini 3.1 Flash Lite', 'aimodel_note_fallback'],
            'gemini-3.1-pro-preview' => ['Gemini 3.1 Pro', 'aimodel_note_previewcapable'],
            'gemini-3-flash-preview' => ['Gemini 3 Flash', 'aimodel_note_preview'],
            'gemini-2.5-flash' => ['Gemini 2.5 Flash', 'aimodel_note_fast'],
            'gemini-2.5-pro' => ['Gemini 2.5 Pro', 'aimodel_note_capable'],
        ];
        $this->assertSame(array_keys($notes), array_keys($setting->choices));
        foreach ($notes as $model => [$name, $key]) {
            $a = (object)['name' => $name, 'note' => get_string($key, 'googlemeet')];
            $label = get_string('aimodel_option', 'googlemeet', $a);
            $this->assertSame($label, $setting->choices[$model], $model);
        }
        $this->assertSame('Gemini 3.5 Flash (Stable, default)', $setting->choices['gemini-3.5-flash']);
    }
}
