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
 * "Join the live class" entry point: logs the intention to join, resolves the Meet URL and
 * normalises a typed one.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class room_entry {
    /** @var int Seconds before the start in which a session already counts as the one being joined. */
    public const EARLY_WINDOW = 30 * MINSECS;

    /** @var string Valid Google Meet room URL. */
    public const MEET_URL_PATTERN = '/^https:\/\/meet\.google\.com\/'
        . '[-a-zA-Z0-9@:%._\+~#=]{3}-[-a-zA-Z0-9@:%._\+~#=]{4}-[-a-zA-Z0-9@:%._\+~#=]{3}$/';

    /**
     * The session (googlemeet_events row) being joined at $now: live, or starting within the early window.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int|null $now Timestamp (defaults to now).
     * @return \stdClass|null
     */
    public static function current_session(int $googlemeetid, ?int $now = null): ?\stdClass {
        global $DB;

        $now = $now ?? time();
        $records = $DB->get_records_select(
            'googlemeet_events',
            'googlemeetid = :googlemeetid AND eventdate <= :earliest AND eventdate + duration >= :now',
            ['googlemeetid' => $googlemeetid, 'earliest' => $now + self::EARLY_WINDOW, 'now' => $now],
            'eventdate ASC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * The activity's Meet URL when it is a valid room URL.
     *
     * @param \stdClass $googlemeet Activity record.
     * @return string|null
     */
    public static function room_url(\stdClass $googlemeet): ?string {
        $url = trim((string)($googlemeet->url ?? ''));
        return ($url !== '' && preg_match(self::MEET_URL_PATTERN, $url)) ? $url : null;
    }

    /**
     * Trigger the room_entered event.
     *
     * Nothing is logged for the guest user: its one account is shared by every guest.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass|\cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param string $via "web" or "mobile".
     * @return int Session id logged (0 when no session is live and none starts within EARLY_WINDOW, or for a guest).
     */
    public static function log(\stdClass $googlemeet, $cm, \context_module $context, string $via = 'web'): int {
        if (isguestuser()) {
            return 0;
        }
        $session = self::current_session((int)$googlemeet->id);
        $sessionid = $session ? (int)$session->id : 0;

        $event = \mod_googlemeet\event\room_entered::create([
            'context' => $context,
            'objectid' => $googlemeet->id,
            'other' => [
                'sessionid' => $sessionid,
                'via' => $via === 'mobile' ? 'mobile' : 'web',
            ],
        ]);
        $event->add_record_snapshot('googlemeet', $googlemeet);
        $event->trigger();

        return $sessionid;
    }

    /**
     * URL of the logging endpoint used by the "Join" button.
     *
     * @param int $cmid Course module id.
     * @return \moodle_url
     */
    public static function entry_url(int $cmid): \moodle_url {
        return new \moodle_url('/mod/googlemeet/enter.php', ['id' => $cmid, 'sesskey' => sesskey()]);
    }

    /**
     * Extract the Google Meet room from a URL as https://meet.google.com/xxx-xxxx-xxx.
     *
     * Every non-null result matches {@see self::MEET_URL_PATTERN}, which room_url() checks; that
     * pattern is the wider one (anchored, but it also admits @:%._+~#= inside the code).
     *
     * @param string $url
     * @return string|null The normalised URL, or null when the text holds no Meet room code.
     */
    public static function clear_url($url) {
        // Literal dots and a URL rebuilt from the code alone: the host of the result is always meet.google.com.
        if (preg_match('~meet\.google\.com/([a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3})~i', (string)$url, $matches)) {
            return 'https://meet.google.com/' . $matches[1];
        }

        return null;
    }
}
