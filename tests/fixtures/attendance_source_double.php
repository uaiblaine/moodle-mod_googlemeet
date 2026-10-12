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

namespace mod_googlemeet\tests;

use mod_googlemeet\local\attendance\scope_exception;
use mod_googlemeet\local\attendance\source;

/**
 * In-memory attendance source for tests (ANA-03).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance_source_double implements source {
    /** @var array Conferences returned. */
    public $conferences = [];
    /** @var array conference name => participants. */
    public $participants = [];
    /** @var bool Throw a scope error. */
    public $scopeerror = false;
    /** @var array Calls received. */
    public $calls = [];

    /**
     * Record the call and return the configured conferences.
     *
     * @param string $meetingcode Meeting code (abc-defg-hij).
     * @param int $from Earliest start time.
     * @param int $to Latest start time.
     * @return array[] The $conferences property.
     * @throws scope_exception When $scopeerror is set.
     */
    public function list_conferences(string $meetingcode, int $from, int $to): array {
        $this->calls[] = ['conferences', $meetingcode, $from, $to];
        if ($this->scopeerror) {
            throw new scope_exception('403: Request had insufficient authentication scopes.');
        }
        return $this->conferences;
    }

    /**
     * Record the call and return the configured participants of one conference.
     *
     * @param string $conference Conference resource name (conferenceRecords/...).
     * @return array[] The $participants entry of that conference, or an empty array.
     */
    public function list_participants(string $conference): array {
        $this->calls[] = ['participants', $conference];
        return $this->participants[$conference] ?? [];
    }
}
