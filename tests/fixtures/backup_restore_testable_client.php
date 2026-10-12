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

/**
 * Test wrapper exposing the Drive recording filter of the client.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_restore_testable_client extends client {
    /**
     * Expose filter_recordings_for_activity() for unit tests.
     *
     * @param array $recordings Drive recordings.
     * @param string $meetingcode Meeting code.
     * @param string $activityname Activity name.
     * @param int $googlemeetid Activity id.
     * @return array
     */
    public function filter_for_test(array $recordings, string $meetingcode, string $activityname, int $googlemeetid): array {
        return $this->filter_recordings_for_activity($recordings, $meetingcode, $activityname, $googlemeetid);
    }
}
