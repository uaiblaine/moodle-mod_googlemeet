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

use calendar_event;
use moodle_exception;
use stdClass;

/**
 * Google API request wrapper (with retry on transient errors) and the session calendar event.
 *
 * @package     mod_googlemeet
 * @copyright   2023 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** @var string Calendar eventtype of the event that mirrors one session (googlemeet_events row). */
    public const GOOGLEMEET_EVENT_START = 'googlemeet_event';

    /** @var int Number of retry attempts after the initial Google API request. */
    private const GOOGLE_REQUEST_RETRIES = 2;

    /**
     * Perform a Google API call, retrying transient errors up to GOOGLE_REQUEST_RETRIES times.
     *
     * A disabled Drive or Calendar API becomes moodle_exception('servicenotenabled'); any other
     * failure is rethrown as is.
     *
     * @param rest $service The rest API object
     * @param string $api The name of the API call, e.g. 'insertevent'
     * @param array $params The parameters required by the API call
     * @param string|false $rawpost Raw request body for a POST, or false for none.
     *
     * @return \stdClass|string|null The response object, or a raw string body for
     *         endpoints declared with a non-JSON ('raw') response (e.g. Drive get/export).
     * @throws moodle_exception
     */
    public static function request($service, $api, $params, $rawpost = false) {
        $attempt = 0;

        while (true) {
            try {
                return $service->call($api, $params, $rawpost);
            } catch (\Exception $e) {
                if (self::is_service_not_enabled_error($e)) {
                    // This is raised when the Drive API service or the Calendar API service
                    // has not been enabled on Google APIs control panel.
                    throw new moodle_exception('servicenotenabled', 'mod_googlemeet');
                }

                if ($attempt >= self::GOOGLE_REQUEST_RETRIES || !self::is_transient_google_error($e)) {
                    throw $e;
                }

                $attempt++;
                $delay = self::retry_delay_seconds($e, $attempt);
                debugging(
                    "mod_googlemeet: transient Google API error on {$api}; retry {$attempt}/" .
                        self::GOOGLE_REQUEST_RETRIES . " in {$delay}s: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                sleep($delay);
            }
        }
    }

    /**
     * Decide whether an exception represents Google/API infrastructure trouble.
     *
     * @param \Throwable $e The exception to inspect.
     * @return bool
     */
    public static function is_infrastructure_error(\Throwable $e): bool {
        if ($e instanceof moodle_exception && $e->errorcode === 'servicenotenabled') {
            return true;
        }

        if ($e instanceof \Exception) {
            return self::is_service_not_enabled_error($e) || self::is_transient_google_error($e);
        }

        return false;
    }

    /**
     * Detect the 403 "Access Not Configured" error of a Google API not enabled for the project.
     *
     * @param \Exception $e The exception thrown by core\oauth2\rest.
     * @return bool
     */
    private static function is_service_not_enabled_error(\Exception $e): bool {
        return self::extract_http_status($e) === 403
            && strpos($e->getMessage(), 'Access Not Configured') !== false;
    }

    /**
     * Decide whether a failed Google API request is worth retrying.
     *
     * The core\oauth2\rest class throws core\oauth2\rest_exception with JSON API failures
     * encoded only as "HTTPSTATUS: message" in the exception text, and transport failures
     * as the curl errno in the exception code. With no structured status accessor, this
     * accepts only Google's transient set: 429/500/502/503, and a 403 or status-less error
     * whose text names rateLimitExceeded, userRateLimitExceeded or backendError.
     *
     * @param \Exception $e The exception thrown by core\oauth2\rest.
     * @return bool
     */
    private static function is_transient_google_error(\Exception $e): bool {
        $message = $e->getMessage();
        $status = self::extract_http_status($e);
        $hastransientreason = preg_match('/\b(rateLimitExceeded|userRateLimitExceeded|backendError)\b/i', $message);

        if ($status === 403) {
            return (bool)$hastransientreason;
        }

        if (in_array($status, [429, 500, 502, 503], true)) {
            return true;
        }

        if ($status === null && $hastransientreason) {
            return true;
        }

        return false;
    }

    /**
     * Extract an HTTP status code from a Moodle OAuth REST exception.
     *
     * @param \Exception $e The exception to inspect.
     * @return int|null HTTP status code, or null when it is not available.
     */
    private static function extract_http_status(\Exception $e): ?int {
        $code = (int)$e->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        if (preg_match('/(?:^|\D)([1-5][0-9]{2})\s*:/', $e->getMessage(), $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Calculate retry delay, respecting Retry-After when it is present in the error text.
     *
     * @param \Exception $e The exception thrown by core\oauth2\rest.
     * @param int $attempt Retry attempt number, starting at 1.
     * @return int Seconds to wait.
     */
    private static function retry_delay_seconds(\Exception $e, int $attempt): int {
        $retryafter = self::extract_retry_after($e->getMessage());
        if ($retryafter !== null) {
            // Cap it: this can run inside a web request (e.g. creating the Meet room on
            // activity save), where an upstream Retry-After of minutes must not stall the page.
            return min(10, max(0, $retryafter));
        }

        $base = 2 ** ($attempt - 1);
        $jitter = random_int(-250, 250) / 1000;
        return max(1, (int)round($base + $jitter));
    }

    /**
     * Parse a Retry-After value from exception text when an upstream layer includes headers.
     *
     * @param string $message Exception message.
     * @return int|null Delay in seconds, or null when absent/unparseable.
     */
    private static function extract_retry_after(string $message): ?int {
        if (!preg_match('/Retry-After:\s*([^\r\n]+)/i', $message, $matches)) {
            return null;
        }

        $value = trim($matches[1]);
        if (preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return max(0, $timestamp - time());
        }

        return null;
    }

    /**
     * Create the course calendar event that mirrors one session.
     *
     * @param stdClass $googlemeet Activity record, with coursemodule set
     * @param stdClass $event The googlemeet_events row (eventdate, duration)
     *
     * @return void
     **/
    public static function create_calendar_event(stdClass $googlemeet, stdClass $event) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $calendarevent = (object) [
            'eventtype' => self::GOOGLEMEET_EVENT_START,
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'name' => get_string('calendareventname', 'googlemeet', $googlemeet->name),
            'description' => format_module_intro('googlemeet', $googlemeet, $googlemeet->coursemodule, false),
            'format' => FORMAT_HTML,
            'courseid' => $googlemeet->course,
            'groupid' => 0,
            'userid' => 0,
            'modulename' => 'googlemeet',
            'instance' => $googlemeet->id,
            'timestart' => $event->eventdate,
            'timeduration' => $event->duration,
            'timesort' => $event->eventdate,
            'visible' => instance_is_visible('googlemeet', $googlemeet),
            'priority' => null,
        ];

        // No capability check: like core modules (assign, quiz, lesson), module events are
        // created by the system on add/update/restore, also from CLI/cron with no user set.
        calendar_event::create($calendarevent, false);
    }
}
