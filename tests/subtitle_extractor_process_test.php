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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How subtitle_extractor runs yt-dlp: only on a Drive URL, and never longer than its time limit.
 *
 * A shell script set as googlemeet/ytdlppath stands in for yt-dlp.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(subtitle_extractor::class)]
final class subtitle_extractor_process_test extends \advanced_testcase {
    /** @var string A Drive webViewLink. */
    private const DRIVE_URL = 'https://drive.google.com/file/d/1AbC_-xyz/view?usp=drivesdk';

    /** @var string Directory of the fake yt-dlp. */
    private string $dir;

    /**
     * Fresh directory for the fake binary; the PATH lookup is pretended to have found nothing.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->dir = make_request_directory();
        $this->set_extractor_static('whichlookedup', true);
        $this->set_extractor_static('cachedwhich', null);
    }

    /**
     * Forget the pretended PATH lookup.
     */
    protected function tearDown(): void {
        $this->set_extractor_static('whichlookedup', false);
        $this->set_extractor_static('cachedwhich', null);
        parent::tearDown();
    }

    /**
     * Set a private static property of subtitle_extractor.
     *
     * @param string $name Property name.
     * @param mixed $value Value.
     */
    private function set_extractor_static(string $name, $value): void {
        $prop = new \ReflectionProperty(subtitle_extractor::class, $name);
        $prop->setValue(null, $value);
    }

    /**
     * Install a fake yt-dlp with the given shell body and point the setting at it.
     *
     * @param string $body Shell commands.
     */
    private function fake_ytdlp(string $body): void {
        $path = $this->dir . '/yt-dlp';
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0755);
        set_config('ytdlppath', $path, 'googlemeet');
    }

    /**
     * An extractor whose yt-dlp run is limited to one second.
     *
     * @return subtitle_extractor
     */
    private function quick_extractor(): subtitle_extractor {
        return new class ('es') extends subtitle_extractor {
            /** @var int Seconds before yt-dlp is killed. */
            protected const YTDLP_TIMEOUT = 1;
        };
    }

    /**
     * The timedtext URL is read from yt-dlp's output (control for the tests below).
     */
    public function test_reads_the_timedtext_url_from_the_output(): void {
        $url = 'https://drive.google.com/timedtext?id=1&authpayload=x&type=track&lang=es';
        $this->fake_ytdlp('echo "[debug] Invoking http downloader on \"' . $url . '\"" >&2');

        $this->assertSame($url, $this->quick_extractor()->get_timedtext_url(self::DRIVE_URL));
    }

    /**
     * A yt-dlp that hangs is killed after the time limit and the tier yields nothing.
     */
    public function test_hanging_ytdlp_is_killed(): void {
        $this->fake_ytdlp('exec sleep 20');

        $start = microtime(true);
        $result = $this->quick_extractor()->get_timedtext_url(self::DRIVE_URL);
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        $this->assertLessThan(8, $elapsed, 'yt-dlp was not stopped at its time limit.');
        $this->resetDebugging();
    }

    /**
     * A yt-dlp that closes its output but keeps running is still stopped at the time limit.
     */
    public function test_ytdlp_that_closes_its_output_is_still_killed(): void {
        $this->fake_ytdlp("exec 1>&- 2>&-\nexec sleep 20");

        $start = microtime(true);
        $result = $this->quick_extractor()->get_timedtext_url(self::DRIVE_URL);
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        $this->assertLessThan(8, $elapsed, 'proc_close() waited for a child that had closed its output.');
        $this->resetDebugging();
    }

    /**
     * The subtitle language reaches yt-dlp only as a language tag; anything else falls back.
     */
    public function test_language_is_a_tag_or_the_default(): void {
        $prop = new \ReflectionProperty(subtitle_extractor::class, 'language');
        $this->assertSame('pt-BR', $prop->getValue(new subtitle_extractor('pt-BR')));
        $this->assertSame('en_US', $prop->getValue(new subtitle_extractor('en_US')));
        $this->assertSame('es', $prop->getValue(new subtitle_extractor('--exec=touch /tmp/x')));
        $this->assertSame('es', $prop->getValue(new subtitle_extractor('')));
    }

    /**
     * URLs yt-dlp must never be started with.
     *
     * @return array
     */
    public static function rejected_url_provider(): array {
        return [
            'other host' => ['https://attacker.example/file/d/1AbC_-xyz/view'],
            'host suffix trick' => ['https://drive.google.com.attacker.example/file/d/1AbC_-xyz/view'],
            'userinfo before host' => ['https://drive.google.com@attacker.example/file/d/x'],
            'plain http' => ['http://drive.google.com/file/d/1AbC_-xyz/view'],
            'local file' => ['file:///etc/passwd'],
            'yt-dlp option' => ['--exec=touch /tmp/x'],
            'not a URL' => ['1AbC_-xyz'],
        ];
    }

    /**
     * Only an https drive.google.com URL reaches yt-dlp.
     *
     * @param string $url Candidate URL.
     */
    #[DataProvider('rejected_url_provider')]
    public function test_non_drive_url_never_runs_ytdlp(string $url): void {
        $marker = $this->dir . '/ran';
        $this->fake_ytdlp('touch ' . escapeshellarg($marker));
        $extractor = $this->quick_extractor();

        $this->assertNull($extractor->get_timedtext_url($url));
        $this->assertFileDoesNotExist($marker);
        $this->resetDebugging();

        // Control: the same fake runs for a Drive URL.
        $extractor->get_timedtext_url(self::DRIVE_URL);
        $this->assertFileExists($marker);
    }
}
