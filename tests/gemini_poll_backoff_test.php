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
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/gemini_poll_testable_client.php');

/**
 * File API status polling: capped exponential back-off, Retry-After, and the total time budget.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gemini_client::class)]
final class gemini_poll_backoff_test extends \advanced_testcase {
    /**
     * Poll number, time left, Retry-After and the pause expected.
     *
     * @return array
     */
    public static function delay_provider(): array {
        return [
            'first pause' => [1, 900, null, 2],
            'second pause doubles' => [2, 900, null, 4],
            'third pause' => [3, 900, null, 8],
            'fourth pause' => [4, 900, null, 16],
            'capped at 30 seconds' => [5, 900, null, 30],
            'stays capped' => [12, 900, null, 30],
            'never past the budget' => [4, 5, null, 5],
            'Retry-After wins over the schedule' => [1, 900, 45, 45],
            'Retry-After within the budget' => [1, 20, 45, 20],
            'Retry-After zero still waits a second' => [3, 900, 0, 1],
        ];
    }

    /**
     * The pause before the next poll.
     *
     * @param int $attempt Polls made so far.
     * @param int $remaining Seconds left in the budget.
     * @param int|null $retryafter Seconds asked by the server.
     * @param int $expected Pause in seconds.
     */
    #[DataProvider('delay_provider')]
    public function test_poll_delay(int $attempt, int $remaining, ?int $retryafter, int $expected): void {
        $this->assertSame($expected, gemini_client::poll_delay($attempt, $remaining, $retryafter));
    }

    /**
     * Retry-After headers and the seconds they ask for.
     *
     * @return array
     */
    public static function retry_after_provider(): array {
        $now = 1760000000;
        return [
            'delta seconds' => [['Retry-After' => '120'], $now, 120],
            'lower-case HTTP/2 name' => [['retry-after' => '7'], $now, 7],
            'HTTP date' => [['Retry-After' => gmdate('D, d M Y H:i:s', $now + 90) . ' GMT'], $now, 90],
            'date in the past' => [['Retry-After' => gmdate('D, d M Y H:i:s', $now - 90) . ' GMT'], $now, 0],
            'repeated header, last wins' => [['Retry-After' => ['5', '9']], $now, 9],
            'garbage' => [['Retry-After' => 'soon'], $now, null],
            'absent' => [['Content-Type' => 'application/json'], $now, null],
        ];
    }

    /**
     * Retry-After is read as delta seconds or as an HTTP date.
     *
     * @param array $headers Response headers as core \curl stores them.
     * @param int $now Reference time.
     * @param int|null $expected Seconds, or null when the header gives none.
     */
    #[DataProvider('retry_after_provider')]
    public function test_retry_after_seconds(array $headers, int $now, ?int $expected): void {
        $this->assertSame($expected, gemini_client::retry_after_seconds($headers, $now));
    }

    /**
     * A configured polling client with scripted answers.
     *
     * @param array $answers [HTTP code, body, headers] per poll.
     * @return gemini_poll_testable_client
     */
    private function client(array $answers): gemini_poll_testable_client {
        $this->resetAfterTest();
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $client = new gemini_poll_testable_client();
        $client->answers = $answers;
        return $client;
    }

    /**
     * Processing twice, then active: two pauses on the back-off schedule.
     */
    public function test_waits_on_the_backoff_schedule(): void {
        $client = $this->client([
            [200, '{"state":"PROCESSING"}'],
            [200, '{"state":"PROCESSING"}'],
            [200, '{"state":"ACTIVE","uri":"https://example.invalid/f"}'],
        ]);

        $file = $client->wait_for_file_processing('files/abc', 900);

        $this->assertSame('ACTIVE', $file->state);
        $this->assertSame([2, 4], $client->pauses);
        $this->resetDebugging();
    }

    /**
     * A 503 with Retry-After is waited out, not treated as a failure.
     */
    public function test_honours_retry_after_on_503(): void {
        $client = $this->client([
            [503, '{}', ['Retry-After' => '12']],
            [200, '{"state":"ACTIVE"}'],
        ]);

        $this->assertSame('ACTIVE', $client->wait_for_file_processing('files/abc', 900)->state);
        $this->assertSame([12], $client->pauses);
        $this->resetDebugging();
    }

    /**
     * Another error status still fails at once.
     */
    public function test_other_error_fails_at_once(): void {
        $client = $this->client([[403, '{}']]);

        try {
            $client->wait_for_file_processing('files/abc', 900);
            $this->fail('A 403 must not be polled again.');
        } catch (\moodle_exception $e) {
            $this->assertSame([], $client->pauses);
        }
        $this->resetDebugging();
    }

    /**
     * A file that never becomes active times out once the pauses fill the budget, never later.
     */
    public function test_times_out_within_the_budget(): void {
        $client = $this->client([[200, '{"state":"PROCESSING"}']]);

        try {
            $client->wait_for_file_processing('files/abc', 60);
            $this->fail('The wait must time out.');
        } catch (\moodle_exception $e) {
            $this->assertSame('File processing timed out', $e->a);
        }
        $this->assertSame([2, 4, 8, 16, 30], $client->pauses);
        $this->assertSame(60, array_sum($client->pauses));
        $this->resetDebugging();
    }
}
