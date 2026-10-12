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

namespace mod_googlemeet\task;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Video download helpers of the AI analysis task: Drive id parsing, confirm token, byte cap.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(process_video_analysis::class)]
final class process_video_analysis_test extends \advanced_testcase {
    /**
     * Drive URLs and the file id each yields.
     *
     * @return array
     */
    public static function drive_url_provider(): array {
        return [
            'webViewLink' => ['https://drive.google.com/file/d/1AbC_-xyz/view?usp=drivesdk', '1AbC_-xyz'],
            'file link without suffix' => ['https://drive.google.com/file/d/1AbC_-xyz', '1AbC_-xyz'],
            'multi-account link' => ['https://drive.google.com/u/1/file/d/1AbC_-xyz/view', '1AbC_-xyz'],
            'open?id' => ['https://drive.google.com/open?id=1AbC_-xyz', '1AbC_-xyz'],
            'uc download' => ['https://drive.google.com/uc?export=download&id=1AbC_-xyz', '1AbC_-xyz'],
            'plain http' => ['http://drive.google.com/file/d/1AbC_-xyz/view', '1AbC_-xyz'],
            'upper-case host' => ['https://Drive.Google.com/file/d/1AbC_-xyz/view', '1AbC_-xyz'],
            'id on another host' => ['https://attacker.example/?id=1AbC_-xyz', null],
            'file path on another host' => ['https://attacker.example/file/d/1AbC_-xyz/view', null],
            'host suffix trick' => ['https://drive.google.com.attacker.example/file/d/1AbC_-xyz/view', null],
            'id in a fragment' => ['https://drive.google.com/drive/my-drive#id=1AbC_-xyz', null],
            'id of another parameter' => ['https://drive.google.com/open?resourceid=1AbC_-xyz', null],
            'folder link' => ['https://drive.google.com/drive/folders/1AbC_-xyz', null],
            'id with bad characters' => ['https://drive.google.com/open?id=1AbC%2F..%2Fx', null],
            'not a URL' => ['1AbC_-xyz', null],
            'empty' => ['', null],
        ];
    }

    /**
     * The file id is read only from drive.google.com file, open and uc links.
     *
     * @param string $url Recording web view link.
     * @param string|null $expected Expected file id.
     */
    #[DataProvider('drive_url_provider')]
    public function test_extract_drive_file_id(string $url, ?string $expected): void {
        $this->assertSame($expected, process_video_analysis::extract_drive_file_id($url));
    }

    /**
     * The confirm token of Drive's interstitial page is found and URL-encoded into the download URL.
     */
    public function test_confirm_token_is_encoded_into_the_download_url(): void {
        $html = '<a id="uc-download-link" href="/uc?export=download&amp;confirm=t0k#n/x?y&amp;id=F1">Download</a>';
        $token = process_video_analysis::extract_confirm_token($html);
        $this->assertSame('t0k#n/x?y', $token);
        $this->assertSame(
            'https://drive.google.com/uc?export=download&confirm=t0k%23n%2Fx%3Fy&id=F1',
            process_video_analysis::build_drive_download_url('F1', $token)
        );

        $this->assertNull(process_video_analysis::extract_confirm_token('<html><body>Video</body></html>'));
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=F1',
            process_video_analysis::build_drive_download_url('F1', null)
        );
    }

    /**
     * Every Drive download is capped at the size the task accepts, so a larger body is cut early.
     */
    public function test_download_options_cap_the_body_size(): void {
        $options = process_video_analysis::download_curl_options(1800, 10);
        $this->assertSame(process_video_analysis::MAX_VIDEO_BYTES, $options['CURLOPT_MAXFILESIZE_LARGE']);
        $this->assertSame(2147483648, process_video_analysis::MAX_VIDEO_BYTES);
        $this->assertSame(1800, $options['CURLOPT_TIMEOUT']);
        $this->assertSame(10, $options['CURLOPT_MAXREDIRS']);

        // The progress callback enforces the cap on a body of unknown length (libcurl before 8.4.0).
        $this->assertFalse($options['CURLOPT_NOPROGRESS']);
        $progress = $options['CURLOPT_PROGRESSFUNCTION'];
        $this->assertSame(0, $progress(null, 0, process_video_analysis::MAX_VIDEO_BYTES, 0, 0));
        $this->assertSame(1, $progress(null, 0, process_video_analysis::MAX_VIDEO_BYTES + 1, 0, 0));
    }
}
