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

namespace mod_googlemeet\output;

use core\output\notification;
use mod_googlemeet\local\ops_status;
use moodle_url;
use renderer_base;

/**
 * Body of admin_status.php: template context for mod_googlemeet/admin_status_page.
 *
 * Every text value is returned plain (Mustache escapes it once): names go through format_string()
 * with escape off, which differs from the escaped form only on &, < and >.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_status_page implements \renderable, \templatable {
    /** @var \stdClass[] From ops_status::autosync_problems(). */
    protected $autosync;

    /** @var \stdClass[] From ops_status::stuck_ai_analyses(). */
    protected $stuckai;

    /** @var \stdClass[] From ops_status::calendar_failures(). */
    protected $calendar;

    /** @var \stdClass[] From ops_status::last_syncs(). */
    protected $lastsyncs;

    /**
     * Constructor.
     *
     * @param \stdClass[] $autosync From ops_status::autosync_problems().
     * @param \stdClass[] $stuckai From ops_status::stuck_ai_analyses().
     * @param \stdClass[] $calendar From ops_status::calendar_failures().
     * @param \stdClass[] $lastsyncs From ops_status::last_syncs().
     */
    public function __construct(array $autosync, array $stuckai, array $calendar, array $lastsyncs) {
        $this->autosync = $autosync;
        $this->stuckai = $stuckai;
        $this->calendar = $calendar;
        $this->lastsyncs = $lastsyncs;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $autosync = [];
        foreach ($this->autosync as $row) {
            $autosync[] = [
                'course' => self::plain_name((string)$row->coursename),
                'activity' => self::activity($row),
                'creator' => (string)$row->creatoremail,
                'failedruns' => (int)$row->failedruns,
                'exhausted' => (int)$row->exhausted,
                'retrying' => (int)$row->retrying,
                'haslastfailure' => !empty($row->lastfailure),
                'lastfailure' => $row->lastfailure ? self::date((int)$row->lastfailure) : '',
                'laststatus' => $row->lastfailure ? ops_status::status_label((string)$row->laststatus) : '',
                'haslastmessage' => $row->lastfailure && $row->lastmessage !== '',
                'lastmessage' => (string)$row->lastmessage,
                'lastsync' => self::date((int)$row->lastsync),
            ];
        }

        $stuckai = [];
        foreach ($this->stuckai as $row) {
            $stuckai[] = [
                'course' => self::plain_name((string)$row->coursename),
                'activity' => self::activity($row),
                'recording' => (string)$row->recordingname,
                'status' => (string)$row->status,
                'since' => self::date((int)$row->timemodified),
                'retries' => (int)$row->retrycount,
                'error' => shorten_text((string)$row->error, 200),
            ];
        }

        $calendar = [];
        foreach ($this->calendar as $row) {
            $calendar[] = [
                'activity' => self::activity($row),
                'googlemeetid' => (int)$row->googlemeetid,
                'operation' => ops_status::kind_label((string)$row->kind),
                'date' => self::date((int)$row->timequeued),
                'message' => (string)$row->message,
            ];
        }

        $lastsyncs = [];
        foreach ($this->lastsyncs as $row) {
            $lastsyncs[] = [
                'course' => self::plain_name((string)$row->coursename),
                'activity' => self::activity($row),
                'lastsync' => self::date((int)$row->lastsync),
                'autosynchours' => (int)$row->autosynchours > 0 ? (string)(int)$row->autosynchours : get_string('no'),
                'manual' => self::run((int)$row->manualtime, (string)$row->manualstatus),
                'auto' => self::run((int)$row->autotime, (string)$row->autostatus),
            ];
        }

        return [
            'windowdays' => ops_status::WINDOW_DAYS,
            'nonehtml' => $output->render(new notification(
                get_string('adminstatus_none', 'googlemeet'),
                notification::NOTIFY_SUCCESS
            )),
            'hasautosync' => !empty($autosync),
            'autosync' => self::mark_last($autosync),
            'hasstuckai' => !empty($stuckai),
            'stuckai' => self::mark_last($stuckai),
            'hascalendar' => !empty($calendar),
            'calendar' => self::mark_last($calendar),
            'haslastsyncs' => !empty($lastsyncs),
            'lastsyncs' => self::mark_last($lastsyncs),
            'truncated' => count($this->lastsyncs) >= ops_status::LAST_SYNC_LIMIT,
            'lastsynclimit' => ops_status::LAST_SYNC_LIMIT,
        ];
    }

    /**
     * Activity cell: a link to the activity, or its plain name when the course module is gone.
     *
     * @param \stdClass $row Has name and cmid (both empty when the activity was deleted).
     * @return array name, and url ('' for no link).
     */
    protected static function activity(\stdClass $row): array {
        if (empty($row->cmid)) {
            return ['name' => (string)($row->name ?? get_string('adminstatus_deleted', 'googlemeet')), 'url' => ''];
        }
        return [
            'name' => self::plain_name((string)$row->name),
            'url' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $row->cmid]))->out(false),
        ];
    }

    /**
     * An admin-set name, filtered as format_string() does but not escaped (the template escapes it).
     *
     * @param string $name
     * @return string
     */
    protected static function plain_name(string $name): string {
        return format_string($name, true, ['escape' => false]);
    }

    /**
     * Date or "never".
     *
     * @param int $time Timestamp, 0 for never.
     * @return string
     */
    protected static function date(int $time): string {
        return $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : get_string('never', 'googlemeet');
    }

    /**
     * Latest run of one kind: "date · status", or "-" when the activity never ran it.
     *
     * @param int $time When it was queued, 0 for never.
     * @param string $status sync_log status.
     * @return string
     */
    protected static function run(int $time, string $status): string {
        return $time ? self::date($time) . ' · ' . ops_status::status_label($status) : '-';
    }

    /**
     * Flag the last row of a table (html_table's lastrow class).
     *
     * @param array[] $rows
     * @return array[]
     */
    protected static function mark_last(array $rows): array {
        foreach ($rows as $i => $row) {
            $rows[$i]['last'] = $i === count($rows) - 1;
        }
        return $rows;
    }
}
