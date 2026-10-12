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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/gemini_upload_fake_curl.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/gemini_upload_testable_client.php');

/**
 * Gemini File API upload: where the key may go, and the site's HTTP rules on the content upload.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gemini_client::class)]
final class gemini_upload_test extends \advanced_testcase {
    /** @var string An upload URL of the shape the File API returns. */
    private const TRUSTED_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files'
        . '?upload_id=ABC-123_x&upload_protocol=resumable';

    /** @var string|null Temporary video file of the current test. */
    private ?string $videofile = null;

    /**
     * Remove the temporary video file.
     */
    protected function tearDown(): void {
        if ($this->videofile !== null && file_exists($this->videofile)) {
            unlink($this->videofile);
        }
        $this->videofile = null;
        parent::tearDown();
    }

    /**
     * A configured client whose upload uses the curl double, plus a small video file.
     *
     * @param string $uploadurl Value of the X-Goog-Upload-URL header the start call returns.
     * @param bool $answerupload Whether the double answers the content upload itself.
     * @return gemini_upload_testable_client
     */
    private function make_client(string $uploadurl, bool $answerupload): gemini_upload_testable_client {
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $this->videofile = make_request_directory() . '/video.mp4';
        file_put_contents($this->videofile, str_repeat('v', 2048));

        $client = new gemini_upload_testable_client();
        $client->uploadurl = $uploadurl;
        $client->answerupload = $answerupload;
        return $client;
    }

    /**
     * Upload URLs and whether the API key may be sent to them.
     *
     * @return array
     */
    public static function upload_url_provider(): array {
        return [
            'File API upload URL' => [self::TRUSTED_URL, true],
            'explicit port 443' => ['https://generativelanguage.googleapis.com:443/upload/v1beta/files?upload_id=a', true],
            'upper-case host' => ['https://GenerativeLanguage.googleapis.com/upload/v1beta/files?upload_id=a', true],
            'plain http' => ['http://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=a', false],
            'other host' => ['https://attacker.example/upload/v1beta/files?upload_id=a', false],
            'host suffix trick' => ['https://generativelanguage.googleapis.com.attacker.example/upload/v1beta/files', false],
            'userinfo before host' => ['https://generativelanguage.googleapis.com@attacker.example/upload/v1beta/files', false],
            'userinfo on the right host' => ['https://user@generativelanguage.googleapis.com/upload/v1beta/files', false],
            'backslash confusion' => ['https://attacker.example\\@generativelanguage.googleapis.com/upload/v1beta/files', false],
            'other port' => ['https://generativelanguage.googleapis.com:8443/upload/v1beta/files?upload_id=a', false],
            'other googleapis host' => ['https://storage.googleapis.com/upload/v1beta/files?upload_id=a', false],
            'not an upload path' => ['https://generativelanguage.googleapis.com/v1beta/files?upload_id=a', false],
            'whitespace inside' => ["https://generativelanguage.googleapis.com/upload/v1beta/files?a=1\r\nHost: x", false],
            'not a URL' => ['upload', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Only an https File API upload URL on Google's Gemini host is trusted with the API key.
     *
     * @param string $url Candidate upload URL.
     * @param bool $expected Whether it is trusted.
     */
    #[DataProvider('upload_url_provider')]
    public function test_is_trusted_upload_url(string $url, bool $expected): void {
        $this->assertSame($expected, gemini_client::is_trusted_upload_url($url));
    }

    /**
     * An upload URL naming another host stops the upload before the content request is made.
     */
    public function test_upload_refuses_an_untrusted_upload_url(): void {
        $this->resetAfterTest();
        $client = $this->make_client('https://attacker.invalid/upload/v1beta/files?upload_id=a', true);

        try {
            $client->upload_video_file($this->videofile, 'video/mp4', 'Video');
            $this->fail('An untrusted upload URL must be refused');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Untrusted upload URL', $e->getMessage());
        }
        $this->resetDebugging();

        // Only the upload-start client exists: the content (and the key) went nowhere.
        $this->assertCount(1, $client->curls);
    }

    /**
     * The content upload goes through core \curl as a streamed POST that follows no redirect.
     */
    public function test_upload_streams_the_file_through_core_curl(): void {
        $this->resetAfterTest();
        $client = $this->make_client(self::TRUSTED_URL, true);

        $file = $client->upload_video_file($this->videofile, 'video/mp4', 'Video');
        $this->resetDebugging();

        $this->assertSame('files/abc123', $file->name);
        $this->assertCount(2, $client->curls);
        // The start call carries the key too, so it must not follow a redirect either.
        $this->assertSame(0, $client->curls[0]->requests[0]['options']['CURLOPT_FOLLOWLOCATION']);
        $upload = $client->curls[1];
        $this->assertCount(1, $upload->requests);
        $request = $upload->requests[0];
        $this->assertSame(self::TRUSTED_URL, $request['url']);
        $this->assertSame(1, $request['options']['CURLOPT_PUT']);
        $this->assertSame('POST', $request['options']['CURLOPT_CUSTOMREQUEST']);
        $this->assertSame(2048, $request['options']['CURLOPT_INFILESIZE']);
        $this->assertSame(0, $request['options']['CURLOPT_FOLLOWLOCATION']);
        $this->assertContains('x-goog-api-key: test-key', $request['header']);
        $this->assertContains('X-Goog-Upload-Command: upload, finalize', $request['header']);
        $this->assertNotContains('X-Goog-Upload-Command: start', $request['header']);

        // Core's put() adds an anonymous basic-auth login unless it is switched off.
        $options = new \ReflectionProperty(\curl::class, 'options');
        $this->assertArrayNotHasKey('CURLOPT_USERPWD', $options->getValue($upload));
    }

    /**
     * The site's blocked-hosts list applies to the content upload.
     */
    public function test_upload_honours_curl_blocked_hosts(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', 'generativelanguage.googleapis.com');
        set_config('curlsecurityallowedport', '');
        $client = $this->make_client(self::TRUSTED_URL, false);

        try {
            $client->upload_video_file($this->videofile, 'video/mp4', 'Video');
            $this->fail('A blocked upload host must fail the upload');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Failed to upload file content', $e->getMessage());
        }
        $this->resetDebugging();

        $this->assertCount(2, $client->curls);
        $this->assertSame(get_string('curlsecurityurlblocked', 'admin'), $client->curls[1]->error);
    }

    /**
     * The content upload goes through the site's outbound proxy.
     *
     * The proxy is a closed local port, so the connection is refused at once and nothing reaches Google.
     */
    public function test_upload_uses_the_site_proxy(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $CFG->proxyhost = '127.0.0.1';
        $CFG->proxyport = '9';
        $CFG->proxytype = 'HTTP';
        $CFG->proxybypass = 'localhost';
        $client = $this->make_client(self::TRUSTED_URL, false);

        try {
            $client->upload_video_file($this->videofile, 'video/mp4', 'Video');
            $this->fail('An unreachable proxy must fail the upload');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Failed to upload file content', $e->getMessage());
        }
        $this->resetDebugging();

        $this->assertCount(2, $client->curls);
        $this->assertSame(CURLE_COULDNT_CONNECT, $client->curls[1]->get_errno());
        $this->assertStringContainsString('127.0.0.1', $client->curls[1]->error);
    }

    /**
     * Under CLI (cron, adhoc tasks, PHPUnit) a model fallback is printed and sent to debugging().
     *
     * The web branch (debugging() only) cannot run here: PHPUnit always defines CLI_SCRIPT.
     */
    public function test_log_fallback_prints_under_cli(): void {
        $this->resetAfterTest();
        $client = new gemini_client();
        $policy = new \ReflectionProperty($client, 'modelpolicy');
        $expected = "Gemini: model 'primary-model' failed (boom); falling back to '"
            . $policy->getValue($client)->get_fallback() . "'";

        $this->expectOutputString($expected . "\n");
        $method = new \ReflectionMethod($client, 'log_fallback');
        $method->invoke($client, 'primary-model', new \Exception('boom'));
        $this->assertDebuggingCalled($expected, DEBUG_DEVELOPER);
    }
}
