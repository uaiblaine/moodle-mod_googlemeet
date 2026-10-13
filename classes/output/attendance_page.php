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
use mod_googlemeet\local\attendance\report;
use mod_googlemeet\local\attendance\service;
use moodle_url;
use renderer_base;
use single_button;

/**
 * Body of attendance.php below the page heading: template context for mod_googlemeet/attendance_page.
 *
 * The page script decides who may do what; this class only formats. Every text value is returned
 * plain (Mustache escapes it once); the *html values are core renderer output.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance_page implements \renderable, \templatable {
    /** @var \stdClass Course-module. */
    protected $cm;

    /** @var \stdClass[] Past sessions, from service::get_sessions(). */
    protected $sessions;

    /** @var \stdClass|null Selected session (googlemeet_events row), null for the list alone. */
    protected $session;

    /** @var \stdClass|null The selected session's googlemeet_attendance_sync row, null when never queued. */
    protected $sync;

    /** @var array[] Rows of the selected session, from report::session_rows(). */
    protected $rows;

    /** @var bool Whether the viewer holds mod/googlemeet:manageattendance (refetch, link, unlink). */
    protected $canmanage;

    /** @var \stdClass[] Users a participant can be linked to, from report::link_candidates(). */
    protected $candidates;

    /** @var string[] Notifications printed above the sessions, already rendered by core. */
    protected $notices;

    /** @var string The organiser's Google login button from client::print_login_popup(), '' for none. */
    protected $loginpopup;

    /** @var string core_renderer::download_dataformat_selector() output, '' when there is nothing to export. */
    protected $downloadselector;

    /**
     * Constructor.
     *
     * @param \stdClass $cm Course-module.
     * @param \stdClass[] $sessions Past sessions, from service::get_sessions().
     * @param \stdClass|null $session Selected session, or null.
     * @param \stdClass|null $sync Sync row of the selected session, or null.
     * @param array[] $rows Rows of the selected session, from report::session_rows().
     * @param bool $canmanage Whether the viewer may refetch, link and unlink.
     * @param \stdClass[] $candidates Users a participant can be linked to (empty unless $canmanage).
     * @param string[] $notices Rendered notifications to show above the sessions, in order.
     * @param string $loginpopup Rendered Google login button, '' when the viewer needs none.
     * @param string $downloadselector Rendered dataformat selector, '' for none.
     */
    public function __construct(
        \stdClass $cm,
        array $sessions,
        ?\stdClass $session,
        ?\stdClass $sync,
        array $rows,
        bool $canmanage,
        array $candidates,
        array $notices,
        string $loginpopup,
        string $downloadselector
    ) {
        $this->cm = $cm;
        $this->sessions = $sessions;
        $this->session = $session;
        $this->sync = $sync;
        $this->rows = $rows;
        $this->canmanage = $canmanage;
        $this->candidates = $candidates;
        $this->notices = $notices;
        $this->loginpopup = $loginpopup;
        $this->downloadselector = $downloadselector;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $baseurl = new moodle_url('/mod/googlemeet/attendance.php', ['id' => $this->cm->id]);
        $notices = [];
        foreach ($this->notices as $html) {
            $notices[] = ['html' => $html];
        }
        return [
            'notices' => $notices,
            'hasloginpopup' => $this->loginpopup !== '',
            'loginpopup' => $this->loginpopup,
            'hassessions' => !empty($this->sessions),
            'nosessionshtml' => $this->sessions ? '' : $output->render(new notification(
                get_string('attendance_no_sessions', 'googlemeet'),
                notification::NOTIFY_INFO
            )),
            'sessions' => $this->export_sessions($baseurl),
            'hassession' => $this->session !== null,
            'session' => $this->session ? $this->export_session($output, $baseurl) : null,
        ];
    }

    /**
     * Rows of the sessions table.
     *
     * @param moodle_url $baseurl The page URL with the course-module id.
     * @return array[]
     */
    protected function export_sessions(moodle_url $baseurl): array {
        $selectedid = $this->session ? (int)$this->session->id : 0;
        $sessions = [];
        foreach (array_values($this->sessions) as $s) {
            $sessions[] = [
                'label' => userdate($s->eventdate, get_string('strftimedatetimeshort', 'langconfig')),
                'selected' => (int)$s->id === $selectedid,
                'url' => (new moodle_url($baseurl, ['session' => $s->id]))->out(false),
                'statuslabel' => report::status_label($s->status),
                'counts' => $s->status === service::STATUS_DONE ? get_string(
                    'attendance_counts',
                    'googlemeet',
                    (object)['matched' => (int)$s->matched, 'total' => (int)$s->participants]
                ) : '',
                'last' => false,
            ];
        }
        if ($sessions) {
            $sessions[count($sessions) - 1]['last'] = true;
        }
        return $sessions;
    }

    /**
     * The selected session: heading, fetch status, refetch control, export and participant rows.
     *
     * @param renderer_base $output Renderer.
     * @param moodle_url $baseurl The page URL with the course-module id.
     * @return array
     */
    protected function export_session(renderer_base $output, moodle_url $baseurl): array {
        $sessionid = (int)$this->session->id;
        $sync = $this->sync;
        $haserror = $sync && $sync->status === service::STATUS_ERROR && $sync->message !== null;

        $refetchhtml = '';
        if ($this->canmanage) {
            // A POST form: single_button adds the sesskey.
            $refetchhtml = $output->render(new single_button(
                new moodle_url($baseurl, ['session' => $sessionid, 'action' => 'refetch']),
                get_string('attendance_refetch', 'googlemeet'),
                'post'
            ));
        }

        $rows = [];
        foreach (array_values($this->rows) as $row) {
            $rows[] = $this->export_row($row, $sessionid);
        }
        if ($rows) {
            $rows[count($rows) - 1]['last'] = true;
        }

        return [
            'heading' => get_string(
                'attendance_session_heading',
                'googlemeet',
                userdate($this->session->eventdate, get_string('strftimedatetime', 'langconfig'))
            ),
            'statuslabel' => report::status_label($sync->status ?? null),
            'haserror' => $haserror,
            'errormessage' => $haserror ? shorten_text($sync->message, 200) : '',
            'hasrefetch' => $refetchhtml !== '',
            'refetchhtml' => $refetchhtml,
            'hasrows' => !empty($rows),
            'downloadselector' => $this->downloadselector,
            'formaction' => $baseurl->out(false),
            'cmid' => (int)$this->cm->id,
            'sessionid' => $sessionid,
            'sesskey' => sesskey(),
            'options' => $rows ? $this->export_options() : [],
            'rows' => $rows,
        ];
    }

    /**
     * One participant row, with its link or unlink control when the viewer may manage attendance.
     *
     * @param array $row One row of report::session_rows().
     * @param int $sessionid Selected session id.
     * @return array
     */
    protected function export_row(array $row, int $sessionid): array {
        $kind = (string)$row['kind'];
        $attendanceid = (int)$row['attendanceid'];
        $meetname = (string)$row['meetname'];
        // The link and unlink controls are writes: only for mod/googlemeet:manageattendance.
        $canlink = $this->canmanage && $kind === report::ROW_UNMATCHED;
        // Undo a wrong (automatic or manual) match: the participant goes back to "not matched" and stays
        // so on later fetches.
        $canunlink = $this->canmanage && $kind === report::ROW_PRESENT && $attendanceid;
        $matchedby = report::matchedby_label((string)$row['matchedby']);
        $timeformat = get_string('strftimetime', 'langconfig');
        return [
            'name' => (string)$row['name'],
            'canlink' => $canlink,
            'canunlink' => (bool)$canunlink,
            'attendanceid' => $attendanceid,
            'selectid' => 'googlemeet-attendance-link-' . $attendanceid,
            'linklabel' => $canlink ? get_string('attendance_link_label', 'googlemeet', $meetname) : '',
            'unlinktitle' => $canunlink ? get_string('attendance_unlink_title', 'googlemeet', $meetname) : '',
            'kindlabel' => report::kind_label($kind),
            'hasmatchedby' => $matchedby !== '',
            'matchedby' => $matchedby,
            'meetname' => $meetname,
            'joined' => $row['timejoined'] ? userdate($row['timejoined'], $timeformat) : '',
            'left' => $row['timeleft'] ? userdate($row['timeleft'], $timeformat) : '',
            'minutes' => $kind === report::ROW_ABSENT ? '' : (string)(int)round($row['durationseconds'] / 60),
            'last' => false,
        ];
    }

    /**
     * Options of the link select: the placeholder (value 0, selected) sorted in with the candidates.
     *
     * @return array[] value, label, selected.
     */
    protected function export_options(): array {
        $labels = [0 => get_string('attendance_link_choose', 'googlemeet')];
        foreach ($this->candidates as $user) {
            $labels[(int)$user->id] = fullname($user);
        }
        \core_collator::asort($labels);
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'selected' => $value === 0];
        }
        return $options;
    }
}
