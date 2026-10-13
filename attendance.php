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

/**
 * Teacher view of the real attendance read from Google Meet, with dataformat export.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\attendance\report;
use mod_googlemeet\local\attendance\scope;
use mod_googlemeet\local\attendance\service;
use mod_googlemeet\output\attendance_page;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$sessionid = optional_param('session', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:viewreports', $context);
// Reading the report is viewreports (given to non-editing teachers); changing it is manageattendance.
$canmanage = has_capability('mod/googlemeet:manageattendance', $context);

$baseurl = new moodle_url('/mod/googlemeet/attendance.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl, $sessionid ? ['session' => $sessionid] : []);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . $googlemeet->name . ': ' . get_string('attendance_title', 'googlemeet'));
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($googlemeet);
$PAGE->activityheader->disable();

$session = null;
if ($sessionid) {
    $session = $DB->get_record('googlemeet_events', ['id' => $sessionid, 'googlemeetid' => $googlemeet->id]);
    if (!$session) {
        redirect($baseurl);
    }
}

// Actions.
if ($action === 'relink') {
    require_sesskey();
    // Only the organiser's link matters for the attendance fetch: nobody else unlinks their account here.
    // This only forgets the current user's own Google token, so it needs no attendance write capability.
    if (!scope::is_organiser($googlemeet, $USER)) {
        redirect($baseurl);
    }
    scope::reset_link((int)$USER->id);
    redirect($baseurl, get_string('attendance_relink_done', 'googlemeet'), null, \core\output\notification::NOTIFY_INFO);
}
if (($action === 'refetch' || $action === 'link') && $session) {
    require_sesskey();
    require_capability('mod/googlemeet:manageattendance', $context);
    // Writes are POST only (the controls are forms), on top of the sesskey check.
    if (!data_submitted()) {
        redirect(new moodle_url($baseurl, ['session' => $session->id]));
    }
}
if ($action === 'refetch' && $session) {
    // No group fence: sessions belong to the activity and every viewer of this page lists every
    // session ({@see report::sessions()}). A refetch re-reads the whole session from Google, as the
    // scheduled fetch does, and keeps every teacher's manual link and unlink ({@see service::store()}),
    // so a manager fenced to their own groups overrides no decision taken for another group.
    service::request_refetch((int)$googlemeet->id, (int)$session->id);
    redirect(
        new moodle_url($baseurl, ['session' => $session->id]),
        get_string('attendance_refetch_queued', 'googlemeet'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
if ($action === 'link' && $session) {
    $attendanceid = required_param('attendanceid', PARAM_INT);
    $userid = required_param('userid', PARAM_INT);
    try {
        // Only a row this teacher is shown, to a user inside their groups (separate groups fence).
        if (!report::can_link($googlemeet, (int)$session->id, $attendanceid, $userid, (int)$USER->id)) {
            throw new moodle_exception('invaliduser');
        }
        service::link_manually($googlemeet, $attendanceid, $userid);
        $message = get_string($userid ? 'attendance_linked' : 'attendance_unlinked', 'googlemeet');
        $type = \core\output\notification::NOTIFY_SUCCESS;
    } catch (moodle_exception $e) {
        $message = $e->getMessage();
        $type = \core\output\notification::NOTIFY_ERROR;
    }
    redirect(new moodle_url($baseurl, ['session' => $session->id]), $message, null, $type);
}

$rows = $session ? report::session_rows($googlemeet, (int)$session->id, null, (int)$USER->id) : [];

if ($download !== '' && $session) {
    $table = report::export_table($rows, $context);
    $filename = clean_filename(format_string($googlemeet->name) . '-' . get_string('attendance_title', 'googlemeet')
        . '-' . userdate($session->eventdate, '%Y%m%d-%H%M'));
    \core\dataformat::download_data($filename, $download, $table['columns'], $table['rows']);
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($googlemeet->name) . ': ' . get_string('attendance_title', 'googlemeet'));

if (!scope::site_enabled()) {
    echo $OUTPUT->notification(get_string('attendance_site_disabled', 'googlemeet'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$notices = [];
if (empty($googlemeet->attendanceenabled)) {
    $editurl = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
    $notices[] = $OUTPUT->notification(
        get_string('attendance_activity_disabled', 'googlemeet', $editurl->out()),
        \core\output\notification::NOTIFY_INFO
    );
}
$relinknotice = scope::relink_notice($googlemeet, $context);
if ($relinknotice !== '') {
    $notices[] = $relinknotice;
}

// Organiser without a Google link: offer the login popup here (callback reloads this page).
$loginpopup = '';
if (scope::is_organiser($googlemeet, $USER)) {
    $client = new \mod_googlemeet\client();
    if ($client->enabled && !$client->check_login()) {
        $loginpopup = $client->print_login_popup();
    }
}

$sessions = report::sessions($googlemeet, time(), (int)$USER->id);
$sync = null;
$candidates = [];
$downloadselector = '';
if ($sessions && $session) {
    $sync = $DB->get_record('googlemeet_attendance_sync', ['eventid' => $session->id]) ?: null;
    if ($rows) {
        $downloadselector = $OUTPUT->download_dataformat_selector(
            get_string('attendance_download', 'googlemeet'),
            new moodle_url('/mod/googlemeet/attendance.php'),
            'download',
            ['id' => $cm->id, 'session' => $session->id]
        );
        $candidates = $canmanage ? report::link_candidates($googlemeet, (int)$USER->id) : [];
    }
}

echo $OUTPUT->render(new attendance_page(
    $cm,
    $sessions,
    $session,
    $sync,
    $rows,
    $canmanage,
    $candidates,
    $notices,
    $loginpopup,
    $downloadselector
));

echo $OUTPUT->footer();
