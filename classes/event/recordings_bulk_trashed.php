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
 * A teacher moved every active recording of the activity to the trash with "Delete all recordings".
 *
 * One event per request, not one per recording: an activity can hold thousands of recordings and
 * that many log rows in a single request would swamp the log store. A single trash still logs
 * {@see recording_trashed}.
 *
 * @property-read array $other {
 *      - int count: recordings moved to the trash by this request.
 * }
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recordings_bulk_trashed extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_recordings_bulk_trashed', 'mod_googlemeet');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' moved {$this->other['count']} recording(s) to the trash with "
            . "\"delete all recordings\" in the Google Meet activity with course module id '$this->contextinstanceid'.";
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
     * @throws \coding_exception When other['count'] is missing
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['count'])) {
            throw new \coding_exception('The \'count\' value must be set in other.');
        }
    }

    /**
     * Restore mapping of objectid: the event has no object.
     *
     * @return int
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
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
