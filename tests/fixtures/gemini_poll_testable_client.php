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
 * Gemini client whose File API status polls read scripted answers and whose pauses only move a clock.
 *
 * Each answer is [HTTP code, body, response headers]; the last answer repeats once the script runs out.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_poll_testable_client extends gemini_client {
    /** @var array Scripted answers, consumed in order. */
    public array $answers = [];

    /** @var int[] Every pause requested, in seconds. */
    public array $pauses = [];

    /** @var int Virtual clock, advanced by pause(). */
    public int $clock = 1000000;

    /**
     * A \curl double that answers with the next scripted answer.
     *
     * @return \curl
     */
    protected function create_curl(): \curl {
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : reset($this->answers);
        return new class ($answer) extends \curl {
            /** @var array [HTTP code, body, headers]. */
            private array $answer;

            /**
             * Constructor.
             *
             * @param array $answer [HTTP code, body, headers].
             */
            public function __construct(array $answer) {
                parent::__construct();
                $this->answer = $answer;
            }

            /**
             * Answer without any network access.
             *
             * @param string $url The URL requested.
             * @param array $options Per-request cURL options.
             * @return string
             */
            protected function request($url, $options = []) {
                $this->reset_request_state_vars();
                $this->info = ['http_code' => $this->answer[0]];
                $this->response = $this->answer[2] ?? [];
                return $this->answer[1];
            }
        };
    }

    /**
     * Record the pause and move the clock instead of sleeping.
     *
     * @param int $seconds Seconds to wait.
     * @return void
     */
    protected function pause(int $seconds): void {
        $this->pauses[] = $seconds;
        $this->clock += $seconds;
    }

    /**
     * The virtual clock.
     *
     * @return int
     */
    protected function now(): int {
        return $this->clock;
    }
}
