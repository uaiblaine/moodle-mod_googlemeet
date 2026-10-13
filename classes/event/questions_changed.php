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
 * A teacher changed the practice questions of a recording.
 *
 * One event per recording and action, whatever the number of questions: "Publish all drafts"
 * triggers one per recording batch it published.
 *
 * @property-read array $other {
 *      - string action: "published", "unpublished", "discarded", "updated" or "queued" (generation queued).
 *      - int count: questions affected (for "queued", the number of questions requested).
 * }
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class questions_changed extends \core\event\base {
    /** @var string[] Accepted values of other['action']. */
    const ACTIONS = ['discarded', 'published', 'queued', 'unpublished', 'updated'];

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
        return get_string('event_questions_changed', 'mod_googlemeet');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $count = (int)$this->other['count'];
        $what = $this->other['action'] === 'queued'
            ? "queued the generation of $count practice question(s)"
            : "{$this->other['action']} $count practice question(s)";
        return "The user with id '$this->userid' $what of the recording with id '$this->objectid' in the Google Meet "
            . "activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Trigger the event for one recording, unless no question changed.
     *
     * @param \context_module $context Module context of the activity.
     * @param int $recordingid googlemeet_recordings id.
     * @param string $action One of ACTIONS.
     * @param int $count Questions affected (requested, for "queued").
     * @return void
     */
    public static function trigger_for(\context_module $context, int $recordingid, string $action, int $count): void {
        if ($count < 1) {
            return;
        }
        self::create([
            'context' => $context,
            'objectid' => $recordingid,
            'other' => ['action' => $action, 'count' => $count],
        ])->trigger();
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
     * @throws \coding_exception When other['action'] is not one of ACTIONS or other['count'] is missing
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['action']) || !in_array($this->other['action'], self::ACTIONS, true)) {
            throw new \coding_exception(
                'The \'action\' value must be set in other to one of ' . implode(', ', self::ACTIONS) . '.'
            );
        }
        if (!isset($this->other['count'])) {
            throw new \coding_exception('The \'count\' value must be set in other.');
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
