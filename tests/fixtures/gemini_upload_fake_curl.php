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
 * Core \curl double for the Gemini File API upload tests.
 *
 * It answers the upload-start POST itself with a chosen X-Goog-Upload-URL. The content upload is
 * either answered too (to inspect what would be sent) or handed to core's request(), which applies
 * the site's proxy and cURL security rules before any byte leaves.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_upload_fake_curl extends \curl {
    /** @var array Requests seen, each with the keys url, options and header. */
    public array $requests = [];

    /** @var string Value returned in the X-Goog-Upload-URL header of the upload-start call. */
    public string $uploadurl = '';

    /** @var bool True to answer the content upload here; false to send it through core's request(). */
    public bool $answerupload = true;

    /**
     * Record the request, answer it or hand it to core.
     *
     * @param string $url The URL requested.
     * @param array $options Per-request cURL options.
     * @return string|bool
     */
    protected function request($url, $options = []) {
        $this->requests[] = ['url' => $url, 'options' => $options, 'header' => $this->header];

        if (!empty($options['CURLOPT_POST'])) {
            $this->reset_request_state_vars();
            $this->info = ['http_code' => 200];
            $this->response = ['X-Goog-Upload-URL' => $this->uploadurl];
            return '{}';
        }

        if ($this->answerupload) {
            $this->reset_request_state_vars();
            $this->info = ['http_code' => 200];
            return json_encode(['file' => ['name' => 'files/abc123', 'uri' => 'https://example.invalid/files/abc123']]);
        }

        return parent::request($url, $options);
    }
}
