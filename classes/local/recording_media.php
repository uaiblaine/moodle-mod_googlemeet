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

namespace mod_googlemeet\local;

/**
 * Google Drive player URLs of a recording: whether it can be embedded, its preview URL and the
 * preview URL that starts at a given second.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_media {
    /**
     * Whether a Google Drive URL can be embedded with the preview player.
     *
     * @param string $webviewlink Drive web view URL.
     * @return bool
     */
    public static function recording_can_embed(string $webviewlink): bool {
        return preg_match('~/file/d/([^/]+)~', $webviewlink) === 1;
    }

    /**
     * Convert a Google Drive view URL to an embeddable preview URL when possible.
     *
     * @param string $webviewlink Drive web view URL.
     * @return string
     */
    public static function get_recording_embed_url(string $webviewlink): string {
        if (preg_match('~/file/d/([^/?#]+)~', $webviewlink, $matches)) {
            return 'https://drive.google.com/file/d/' . rawurlencode($matches[1]) . '/preview';
        }
        return $webviewlink;
    }

    /**
     * Build the Drive preview URL that starts playback at a given second.
     *
     * Google Drive's /preview player honours a `t` query parameter (seconds), so seeking is done by
     * reloading the iframe with `?t=<seconds>`. Returns the plain embed URL when no offset is needed,
     * and the original link untouched when it is not an embeddable Drive file.
     *
     * @param string $webviewlink Drive web view URL.
     * @param int $seconds Start offset in seconds (negative values are clamped to 0).
     * @return string
     */
    public static function get_recording_seek_url(string $webviewlink, int $seconds): string {
        if (!self::recording_can_embed($webviewlink)) {
            return $webviewlink;
        }
        $url = self::get_recording_embed_url($webviewlink);
        $seconds = max(0, $seconds);
        return $seconds > 0 ? $url . '?t=' . $seconds : $url;
    }
}
