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
 * Minimal fake REST service used by helper::request().
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_test_fake_drive_service {
    /** @var array Queued responses. */
    private array $responses;

    /** @var array Captured calls. */
    public array $calls = [];

    /**
     * Queue the responses that call() returns, in order.
     *
     * @param array $responses Responses returned by call(): an array becomes a stdClass (a JSON
     *     answer), any other value (a downloaded file body) is returned as it is.
     */
    public function __construct(array $responses) {
        $this->responses = $responses;
    }

    /**
     * Fake core\oauth2\rest::call().
     *
     * @param string $api API name.
     * @param array $params Request params.
     * @param mixed $rawpost Raw body.
     * @return mixed
     */
    public function call($api, $params, $rawpost = false): mixed {
        $this->calls[] = ['api' => $api, 'params' => $params, 'rawpost' => $rawpost];
        $response = array_shift($this->responses);
        return is_array($response) ? (object)$response : $response;
    }
}
