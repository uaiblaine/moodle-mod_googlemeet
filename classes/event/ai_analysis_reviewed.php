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
 * A teacher published an AI analysis to students (marked it reviewed).
 *
 * "Publish all" triggers one event per analysis it published, with other['all'] set. Publishing an
 * analysis that was already published writes nothing and triggers nothing.
 *
 * @property-read array $other {
 *      - bool all: true when published by "Publish all" for the activity.
 *      - int recordingid: googlemeet_recordings id of the analysed recording.
 * }
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_analysis_reviewed extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'googlemeet_ai_analysis';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_ai_analysis_reviewed', 'mod_googlemeet');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $how = !empty($this->other['all']) ? ' (publish all)' : '';
        return "The user with id '$this->userid' published to students{$how} the AI analysis with id '$this->objectid' "
            . "of the recording with id '{$this->other['recordingid']}' in the Google Meet activity with course module id "
            . "'$this->contextinstanceid'.";
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
     * @throws \coding_exception When other['all'] or other['recordingid'] is missing
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['all'])) {
            throw new \coding_exception('The \'all\' value must be set in other.');
        }
        if (!isset($this->other['recordingid'])) {
            throw new \coding_exception('The \'recordingid\' value must be set in other.');
        }
    }

    /**
     * Restore mapping of objectid.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'googlemeet_ai_analysis', 'restore' => 'googlemeet_aianalysis'];
    }

    /**
     * Restore mapping of the other fields.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['recordingid' => ['db' => 'googlemeet_recordings', 'restore' => 'googlemeet_recording']];
    }
}
