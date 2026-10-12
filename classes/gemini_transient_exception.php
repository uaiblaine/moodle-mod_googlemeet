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
 * The Gemini API returned a transient error (rate limit, overload, HTTP 429/500/503).
 *
 * The caller should back off and retry later. Extends \moodle_exception so catch blocks for
 * moodle_exception or \Exception still handle it. Thrown by {@see gemini_client::handle_http_response()}.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_transient_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $message Human-readable error message from the API.
     */
    public function __construct(string $message) {
        parent::__construct('ai_error', 'googlemeet', '', $message);
    }
}
