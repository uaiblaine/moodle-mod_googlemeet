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
 * How googlemeet_pluginfile() sends teacher files (attachments and recording materials).
 *
 * A file a browser renders as a document of the site's origin (HTML, XHTML, SVG, XML, which can
 * carry XHTML or SVG) would run its scripts with the viewer's session, so it is always sent as an
 * attachment. PDFs, images and other files keep the download choice of the request.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_serving {
    /** @var string[] MIME types a browser renders as an active document. */
    private const ACTIVE_MIMETYPES = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
    ];

    /**
     * The $forcedownload to pass to send_stored_file() for $file.
     *
     * @param \stored_file $file File about to be sent.
     * @param bool $requested Download forced by the request (the forcedownload URL parameter).
     * @return bool
     */
    public static function force_download(\stored_file $file, bool $requested): bool {
        return $requested || self::is_active_content($file->get_mimetype(), $file->get_filename());
    }

    /**
     * Whether a browser would render the file as an active document.
     *
     * Both the stored MIME type and the one the file name implies are checked: send_file() falls
     * back to the name when the stored type is document/unknown.
     *
     * @param string $mimetype Stored MIME type.
     * @param string $filename File name.
     * @return bool
     */
    public static function is_active_content(string $mimetype, string $filename): bool {
        foreach ([$mimetype, (string)mimeinfo('type', $filename)] as $type) {
            $type = \core_text::strtolower(trim($type));
            if (in_array($type, self::ACTIVE_MIMETYPES, true) || str_ends_with($type, '+xml')) {
                return true;
            }
        }
        return false;
    }
}
