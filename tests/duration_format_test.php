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
 * Duration and timestamp formatting with fractional seconds (no float modulo).
 *
 * PHP 8.1+ deprecates % on a float with a fractional part, and the PHPUnit configuration fails a
 * test on a deprecation, so each fractional case here fails if the arithmetic goes back to float %.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(client::class)]
#[CoversClass(subtitle_extractor::class)]
final class duration_format_test extends \advanced_testcase {
    /**
     * Durations in milliseconds and their display.
     *
     * @return array
     */
    public static function duration_provider(): array {
        return [
            'zero' => [0, '0:00'],
            'under a minute, fractional' => [59999, '0:59'],
            'exact minute' => [60000, '1:00'],
            'minutes, fractional' => [65500, '1:05'],
            'minutes, integral' => [65000, '1:05'],
            'exact hour' => [3600000, '1:00:00'],
            'hours, fractional' => [3723456, '1:02:03'],
            'hours, integral' => [3723000, '1:02:03'],
        ];
    }

    /**
     * Drive durations are shown as M:SS or H:MM:SS, truncating the fractional second.
     *
     * @param int $milli Duration in milliseconds.
     * @param string $expected Formatted duration.
     */
    #[DataProvider('duration_provider')]
    public function test_formatseconds(int $milli, string $expected): void {
        $client = (new \ReflectionClass(client::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(client::class, 'formatseconds');
        $this->assertSame($expected, $method->invoke($client, $milli));
    }

    /**
     * Fractional subtitle start times give the same minute markers as their whole second.
     */
    public function test_parse_xml_fractional_start_times(): void {
        $xml = '<transcript>'
            . '<text start="148.92" dur="2.1">Hello</text>'
            . '<text start="149.5" dur="1">same minute</text>'
            . '<text start="3725.75" dur="1">Bye</text>'
            . '</transcript>';
        $extractor = new subtitle_extractor('es');
        $this->assertSame("2:28\nHello\nsame minute\n1:02:05\nBye", $extractor->parse_xml($xml));
    }
}
