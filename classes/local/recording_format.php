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

use core_text;

/**
 * Pure formatting helpers for recordings: durations, timestamps, chapters, date chips and groups,
 * summary truncation, accent folding, AI status flags and the key points label.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_format {
    /**
     * Parse a stored Drive recording duration into seconds.
     *
     * Durations created by the sync are stored as M:SS or H:MM:SS strings.
     *
     * @param string|null $duration Stored duration.
     * @return int Duration in seconds, or 0 when it cannot be parsed.
     */
    public static function recording_duration_to_seconds(?string $duration): int {
        $duration = trim((string)$duration);
        if ($duration === '') {
            return 0;
        }

        $parts = explode(':', $duration);
        if (count($parts) < 2 || count($parts) > 3) {
            return 0;
        }

        $seconds = 0;
        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part)) {
                return 0;
            }
            $seconds = ($seconds * 60) + (int)$part;
        }

        return $seconds;
    }

    /**
     * Parse a transcript timestamp into seconds.
     *
     * Accepts M:SS, MM:SS or H:MM:SS. Returns -1 for invalid values so 0:00 remains valid.
     *
     * @param string|null $timestamp Timestamp text.
     * @return int Seconds from the start, or -1 when invalid.
     */
    public static function timestamp_to_seconds(?string $timestamp): int {
        $timestamp = trim((string)$timestamp);
        if ($timestamp === '') {
            return -1;
        }

        $parts = explode(':', $timestamp);
        if (count($parts) < 2 || count($parts) > 3) {
            return -1;
        }

        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part)) {
                return -1;
            }
        }

        $parts = array_map('intval', $parts);
        $seconds = array_pop($parts);
        $minutes = array_pop($parts);
        $hours = !empty($parts) ? array_pop($parts) : 0;

        if ($seconds > 59 || $minutes > 59) {
            return -1;
        }

        return ($hours * HOURSECS) + ($minutes * MINSECS) + $seconds;
    }

    /**
     * Format seconds as M:SS or H:MM:SS (the same shape the chapters use).
     *
     * @param int $seconds Seconds.
     * @return string
     */
    public static function format_seconds_timestamp(int $seconds): string {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, HOURSECS);
        $minutes = intdiv($seconds % HOURSECS, MINSECS);
        $secs = $seconds % MINSECS;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Decode and normalise stored AI chapters for template rendering.
     *
     * @param string|array|null $rawchapters Stored JSON or already decoded chapter list.
     * @return array Template-ready chapters.
     * @throws \coding_exception
     */
    public static function normalise_chapters($rawchapters): array {
        if (is_string($rawchapters)) {
            $rawchapters = trim($rawchapters);
            if ($rawchapters === '') {
                return [];
            }
            $decoded = json_decode($rawchapters);
        } else {
            $decoded = $rawchapters;
        }

        if (is_object($decoded) && isset($decoded->chapters)) {
            $decoded = $decoded->chapters;
        }
        if (!is_array($decoded)) {
            return [];
        }

        $chapters = [];
        $seen = [];
        foreach ($decoded as $item) {
            $title = '';
            $start = '';
            if (is_array($item)) {
                $title = trim((string)($item['title'] ?? ''));
                $start = trim((string)($item['start'] ?? ''));
            } else if (is_object($item)) {
                $title = trim((string)($item->title ?? ''));
                $start = trim((string)($item->start ?? ''));
            }

            $seconds = self::timestamp_to_seconds($start);
            if ($title === '' || $seconds < 0) {
                continue;
            }
            $key = $seconds . ':' . core_text::strtolower($title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (core_text::strlen($title) > 80) {
                $title = core_text::substr($title, 0, 77) . '...';
            }
            $chapters[] = [
                'title' => $title,
                'start' => $start,
                'startseconds' => $seconds,
                'chapterarialabel' => get_string('chapter_jump_aria', 'googlemeet', (object) [
                    'title' => $title,
                    'timestamp' => $start,
                ]),
            ];
        }

        usort($chapters, static function (array $a, array $b): int {
            return $a['startseconds'] <=> $b['startseconds'];
        });

        return array_slice($chapters, 0, 12);
    }

    /**
     * Multibyte-safe truncation for AI summary previews.
     *
     * @param string $text The full summary text.
     * @param int $length Maximum length in characters.
     * @return string Truncated text with an ellipsis if it was cut.
     */
    public static function truncate_summary(string $text, int $length = 200): string {
        if (core_text::strlen($text) <= $length) {
            return $text;
        }
        return core_text::substr($text, 0, $length) . '…';
    }

    /**
     * Fold Spanish accents for accent-insensitive internal search.
     *
     * @param string $s Raw text.
     * @return string Lowercased text with Spanish accents folded to ASCII.
     */
    public static function fold(string $s): string {
        return strtr(core_text::strtolower($s), [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
    }

    /**
     * Normalize a compact card date chip: lowercase and strip trailing dots.
     *
     * Abbreviated month names often end in a dot ("27 May." becomes "27 may"); dots inside the
     * string, such as after an abbreviated weekday, are kept. For chips only, never full timestamps.
     *
     * @param string $date Formatted short date string.
     * @return string Lowercased, trailing-dot-trimmed date string.
     */
    public static function format_date_chip(string $date): string {
        $date = core_text::strtolower($date);
        // Trim surrounding whitespace, then any trailing dot.
        $date = trim($date);
        $date = rtrim($date, '.');
        return $date;
    }

    /**
     * Localised month/year heading for grouping recordings, e.g. "May 2026".
     *
     * @param int $timestamp Recording creation time.
     * @return string
     * @throws \coding_exception
     */
    public static function recording_date_group(int $timestamp): string {
        return userdate($timestamp, get_string('strftimemonthyear', 'langconfig'));
    }

    /**
     * Whether a recording counts as "new" relative to a reference time.
     *
     * @param int $createdtime Recording creation time.
     * @param int $now Reference "now" timestamp.
     * @param int $thresholddays Age threshold in days.
     * @return bool
     */
    public static function recording_is_new(int $createdtime, int $now, int $thresholddays = 7): bool {
        return ($now - $createdtime) <= ($thresholddays * DAYSECS);
    }

    /**
     * Build template booleans for an AI analysis status.
     *
     * @param string|null $status One of pending|processing|completed|failed, or null when no row exists.
     * @return array Associative array of status flags.
     */
    public static function ai_status_flags(?string $status): array {
        $valid = ['pending', 'processing', 'completed', 'failed'];
        $status = in_array($status, $valid, true) ? $status : null;
        return [
            'aistatus' => $status ?? '',
            'hasanalysisrow' => $status !== null,
            'aistatusiscompleted' => $status === 'completed',
            'aistatusisprocessing' => $status === 'processing',
            'aistatusispending' => $status === 'pending',
            'aistatusisfailed' => $status === 'failed',
        ];
    }

    /**
     * Build a localized, correctly pluralized "N key points" label for a recording card.
     *
     * @param int $count Number of key points.
     * @return string Localized label, e.g. "1 key point" / "3 key points".
     * @throws \coding_exception
     */
    public static function keypoints_label(int $count): string {
        $key = $count === 1 ? 'ai_keypoints_count' : 'ai_keypoints_count_plural';
        return get_string($key, 'googlemeet', $count);
    }
}
