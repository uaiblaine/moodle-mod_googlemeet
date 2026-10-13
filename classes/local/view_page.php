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

use context_module;
use mod_googlemeet\client;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Request handling of view.php: the page header and the write actions (Google logout, Drive
 * sync, recording notification subscription) that run before it.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_page {
    /**
     * Print googlemeet header.
     * @param object $googlemeet
     * @param object $cm
     * @param object $course
     * @return void
     */
    public static function print_header($googlemeet, $cm, $course) {
        global $PAGE, $OUTPUT;

        $PAGE->set_title($course->shortname . ': ' . $googlemeet->name);
        $PAGE->set_heading($course->fullname);
        $PAGE->set_activity_record($googlemeet);
        echo $OUTPUT->header();
    }

    /**
     * Handle the write actions triggered from the activity view (logout / sync).
     *
     * Both actions need mod/googlemeet:editrecording (without it the call does nothing) and a valid
     * sesskey. Sync also needs a POST, so a GET to ?sync=1 is ignored even with a valid sesskey; it
     * queues a background sync and redirects to the view. Logout stays reachable by GET (sesskey
     * protected) for the OAuth account link and does not redirect: the page keeps rendering.
     *
     * @param object $googlemeet the googlemeet instance record
     * @param object $cm the course module record
     * @param object $course the course record
     * @return void
     */
    public static function handle_view_actions($googlemeet, $cm, $course) {
        $context = context_module::instance($cm->id);

        if (!has_capability('mod/googlemeet:editrecording', $context)) {
            return;
        }

        $client = new client();

        $logout = optional_param('logout', 0, PARAM_BOOL);
        if ($logout && confirm_sesskey()) {
            $client->logout();
        }

        $sync = optional_param('sync', 0, PARAM_BOOL);
        // Sync is a write: POST only, on top of the sesskey check.
        if ($sync && confirm_sesskey() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            // The queued task syncs as this user, which requires syncgoogledrive: fail now, not in cron.
            require_capability('mod/googlemeet:syncgoogledrive', $context);
            // No-JS fallback of the async button. Only queue the background sync (the page polls its
            // status when JS is on); never run Google calls inside the request.
            $redirecturl = new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
            $queued = \mod_googlemeet\local\sync_manager::request($googlemeet, (int)$GLOBALS['USER']->id);
            redirect(
                $redirecturl,
                get_string($queued ? 'sync_status_queued' : 'sync_already_running', 'googlemeet'),
                null,
                \core\output\notification::NOTIFY_INFO
            );
        }
    }

    /**
     * Handle the user's recording notification subscription toggle.
     *
     * @param object $googlemeet the googlemeet instance record
     * @param object $cm the course module record
     * @return void
     */
    public static function handle_subscription_action($googlemeet, $cm) {
        global $DB, $USER;

        $subscribe = optional_param('subscribe', -1, PARAM_INT);
        if ($subscribe === -1) {
            return;
        }

        $context = context_module::instance($cm->id);
        require_capability('mod/googlemeet:subscriberecordings', $context);
        require_sesskey();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new moodle_exception('invalidrequest', 'error');
        }

        if ($subscribe) {
            if (!$DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id])) {
                $record = new stdClass();
                $record->googlemeetid = $googlemeet->id;
                $record->userid = $USER->id;
                $record->timecreated = time();
                $DB->insert_record('googlemeet_recording_subs', $record);
            }
        } else {
            $DB->delete_records('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id]);
        }

        redirect(new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]));
    }
}
