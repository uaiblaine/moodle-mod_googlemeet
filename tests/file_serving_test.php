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

use mod_googlemeet\local\file_serving;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Teacher files that a browser would run as a page are sent as downloads.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(file_serving::class)]
final class file_serving_test extends \advanced_testcase {
    /**
     * File names, the stored MIME type (null = what the file API derives) and the expected decision.
     *
     * @return array
     */
    public static function files_provider(): array {
        return [
            'html' => ['material.html', null, true],
            'htm' => ['material.htm', null, true],
            'xhtml' => ['material.xhtml', null, true],
            'svg' => ['diagram.svg', null, true],
            'xml' => ['data.xml', null, true],
            'xsl' => ['style.xsl', null, true],
            'html stored under another name' => ['material.bin', 'text/html', true],
            'html stored as unknown' => ['material.html', 'document/unknown', true],
            'pdf' => ['slides.pdf', null, false],
            'png' => ['photo.png', null, false],
            'jpeg' => ['photo.jpg', null, false],
            'text' => ['notes.txt', null, false],
            'docx' => ['handout.docx', null, false],
        ];
    }

    /**
     * HTML, SVG and XML are forced to download; documents and images stay inline.
     *
     * @param string $filename File name.
     * @param string|null $mimetype Stored MIME type, null to let the file API derive it.
     * @param bool $expected Whether the download is forced.
     */
    #[DataProvider('files_provider')]
    public function test_force_download(string $filename, ?string $mimetype, bool $expected): void {
        $this->resetAfterTest();
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_googlemeet',
            'filearea' => 'attachment',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ];
        if ($mimetype !== null) {
            $record['mimetype'] = $mimetype;
        }
        $file = get_file_storage()->create_file_from_string($record, '<html><script>alert(1)</script></html>');
        if ($mimetype !== null) {
            $this->assertSame($mimetype, $file->get_mimetype());
        }

        $this->assertSame($expected, file_serving::force_download($file, false));
        // A download the request already forces stays forced.
        $this->assertTrue(file_serving::force_download($file, true));
    }
}
