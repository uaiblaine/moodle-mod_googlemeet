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

namespace mod_googlemeet\event;

/**
 * A teacher moved a recording to the activity's trash (soft delete, restorable).
 *
 * "Delete all recordings" does not trigger it per recording: see {@see recordings_bulk_trashed}.
 *
 * @property-read array $other {
 *      - bool bulk: true when the recording was trashed as part of a bulk trash (log rows written before
 *        {@see recordings_bulk_trashed} existed); false for a single trash.
 * }
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_trashed extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'googlemeet_recordings';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_recording_trashed', 'mod_googlemeet');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $how = !empty($this->other['bulk']) ? ' (delete all recordings)' : '';
        return "The user with id '$this->userid' moved the recording with id '$this->objectid' to the trash{$how} "
            . "in the Google Meet activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Activity URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/googlemeet/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the custom data.
     *
     * @return void
     * @throws \coding_exception When other['bulk'] is not set
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['bulk'])) {
            throw new \coding_exception('The \'bulk\' value must be set in other.');
        }
    }

    /**
     * Restore mapping of objectid.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'googlemeet_recordings', 'restore' => 'googlemeet_recording'];
    }

    /**
     * Restore mapping of the other fields: none of them is an id.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [];
    }
}
