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

use stdClass;

/**
 * Per-user recording progress: the completion threshold, the template progress state, and the
 * user preferences that hold the last jump point and the key points checklist.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_progress {
    /**
     * User preference that stores the last chapter/timestamp a user jumped to in a recording.
     *
     * This is the last jump point chosen in Moodle, not the real playback position: the Drive
     * preview iframe does not expose its current time.
     *
     * @param int $recordingid Recording ID.
     * @return string
     */
    public static function lastjump_preference_name(int $recordingid): string {
        return 'mod_googlemeet_lastjump_' . $recordingid;
    }

    /**
     * User preference that stores the key points review checklist state of a recording.
     *
     * @param int $recordingid Recording ID.
     * @return string
     */
    public static function keypoints_preference_name(int $recordingid): string {
        return 'mod_googlemeet_kp_' . $recordingid;
    }

    /**
     * Short hash identifying the current key point list (so state of a regenerated list is ignored).
     *
     * @param array $keypoints Key point texts.
     * @return string 8 hex characters.
     */
    public static function keypoints_hash(array $keypoints): string {
        $normalised = array_map(static function ($point): string {
            return trim(preg_replace('/\s+/u', ' ', (string)$point));
        }, array_values($keypoints));
        return sprintf('%08x', crc32(implode("\n", $normalised)));
    }

    /**
     * Checked state ("0"/"1" per key point) stored for a user, or '' when absent or stale.
     *
     * The stored value is "<hash>:<bits>", e.g. "1a2b3c4d:10110".
     *
     * @param string $stored Stored preference value.
     * @param string $hash Hash of the current key point list.
     * @param int $count Number of key points.
     * @return string Bit string of length $count, or '' when it does not apply.
     */
    public static function keypoints_state(string $stored, string $hash, int $count): string {
        if (!preg_match('/^([0-9a-f]{8}):([01]{1,200})$/', $stored, $m) || $m[1] !== $hash || strlen($m[2]) !== $count) {
            return '';
        }
        return $m[2];
    }

    /**
     * Completion threshold for heartbeat progress.
     *
     * The value is intentionally a presence proxy: 60% of the recording duration capped at 8 minutes.
     * An unknown duration uses the 8-minute cap; the 30-second floor (one heartbeat) stops a very short
     * recording from completing on its first beat.
     *
     * @param string|null $duration Stored recording duration.
     * @return int Threshold in seconds.
     */
    public static function recording_completion_threshold(?string $duration): int {
        $durationseconds = recording_format::recording_duration_to_seconds($duration);
        if ($durationseconds <= 0) {
            return 8 * MINSECS;
        }

        return max(30, min((int)ceil($durationseconds * 0.6), 8 * MINSECS));
    }

    /**
     * Build template fields for one user's progress on a recording.
     *
     * The student's "Viewed" state (progresscompleted) is measured completion OR the student's own
     * "Mark as viewed" (markedviewed). Only progressmeasuredcompleted, the measured flag, is what the
     * completion rules count; the recording hub keeps its heartbeat running until that one is set.
     *
     * @param stdClass $recording Recording object with at least duration.
     * @param stdClass|null $progress Progress row with watchedseconds, completed and markedviewed, if it exists.
     * @return array Template-safe progress fields.
     * @throws \coding_exception
     */
    public static function recording_progress_state(stdClass $recording, ?stdClass $progress): array {
        $threshold = self::recording_completion_threshold($recording->duration ?? '');
        $watchedseconds = $progress ? max(0, (int)$progress->watchedseconds) : 0;
        $measuredcompleted = $progress && !empty($progress->completed);
        $completed = $measuredcompleted || ($progress && !empty($progress->markedviewed));
        $percent = 0;

        if ($completed) {
            $percent = 100;
        } else if ($threshold > 0 && $watchedseconds > 0) {
            $percent = min(99, max(1, (int)floor(($watchedseconds / $threshold) * 100)));
        }

        $partial = !$completed && $watchedseconds > 0;
        $unseen = !$completed && !$partial;
        if ($completed) {
            $label = get_string('recording_progress_completed', 'googlemeet');
        } else if ($partial) {
            $label = get_string('recording_progress_partial', 'googlemeet');
        } else {
            $label = get_string('recording_progress_unseen', 'googlemeet');
        }

        return [
            'progresswatchedseconds' => $watchedseconds,
            'progressthresholdseconds' => $threshold,
            'progresspct' => $percent,
            'progresspcttext' => $percent . '%',
            'progressbarstyle' => 'width: ' . $percent . '%;',
            'showprogressbar' => $partial,
            'progresscompleted' => $completed,
            'progressmeasuredcompleted' => $measuredcompleted,
            'progresspartial' => $partial,
            'progressunseen' => $unseen,
            'progressstatuslabel' => $label,
            'progressarialabel' => get_string('recording_progress_aria', 'googlemeet', $label),
        ];
    }
}
