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
 * Gemini client whose File API upload talks to {@see gemini_upload_fake_curl} instances.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_upload_testable_client extends gemini_client {
    /** @var gemini_upload_fake_curl[] Every HTTP client the upload created, in order. */
    public array $curls = [];

    /** @var string Value the upload-start call returns in X-Goog-Upload-URL. */
    public string $uploadurl = '';

    /** @var bool Passed to each double: answer the content upload instead of sending it through core. */
    public bool $answerupload = true;

    /**
     * Create a fresh double per call, as production creates a fresh \curl.
     *
     * @return \curl
     */
    protected function create_curl(): \curl {
        $curl = new gemini_upload_fake_curl();
        $curl->uploadurl = $this->uploadurl;
        $curl->answerupload = $this->answerupload;
        $this->curls[] = $curl;
        return $curl;
    }
}
