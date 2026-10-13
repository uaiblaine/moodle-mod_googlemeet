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

use mod_googlemeet\local\ops_status;
use mod_googlemeet\local\sync_log;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Markup of the admin status page body (admin_status.php).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(admin_status_page::class)]
final class admin_status_page_test extends \advanced_testcase {
    /**
     * A last_syncs() row.
     *
     * @param int $id Activity id.
     * @return \stdClass
     */
    private function lastsync_row(int $id): \stdClass {
        return (object)['id' => $id, 'name' => 'Sala ' . $id, 'coursename' => 'Curso', 'cmid' => 100 + $id, 'lastsync' => 0,
            'autosynchours' => 0, 'manualstatus' => '', 'manualtime' => 0, 'autostatus' => sync_log::STATUS_SUCCESS,
            'autotime' => 1760000000];
    }

    /**
     * With nothing to report the three problem sections show the "none" notice and the last-sync table is
     * an empty body.
     */
    public function test_empty(): void {
        global $PAGE;
        $this->resetAfterTest();
        $output = $PAGE->get_renderer('core');
        $page = new admin_status_page([], [], [], []);
        $data = $page->export_for_template($output);
        $this->assertFalse($data['hasautosync'] || $data['hasstuckai'] || $data['hascalendar'] || $data['haslastsyncs']);
        $this->assertStringContainsString(get_string('adminstatus_none', 'googlemeet'), $data['nonehtml']);

        $html = $output->render($page);
        $this->assertSame(3, substr_count($html, get_string('adminstatus_none', 'googlemeet')));
        $this->assertSame(1, substr_count($html, '<table'));
        $this->assertStringContainsString('<tbody class="empty">', $html);
        $this->assertStringContainsString('<td colspan="6"></td>', $html);
        $this->assertStringNotContainsString(get_string('adminstatus_truncated', 'googlemeet', ops_status::LAST_SYNC_LIMIT), $html);
    }

    /**
     * Rows: names escaped once, a link per existing activity, the plain name of a deleted one, the failure
     * message under its status, and the "-" and "No" placeholders.
     */
    public function test_rows(): void {
        global $PAGE;
        $this->resetAfterTest();
        $output = $PAGE->get_renderer('core');
        $autosync = [7 => (object)['coursename' => 'Curso & Co', 'name' => 'Sala & B', 'cmid' => 70,
            'creatoremail' => 'o&x@example.com', 'failedruns' => 2, 'exhausted' => 0, 'retrying' => 1,
            'lastfailure' => 1760000000, 'laststatus' => sync_log::STATUS_ERROR, 'lastmessage' => 'Token <expired>',
            'lastsync' => 0]];
        $calendar = [3 => (object)['googlemeetid' => 987, 'kind' => sync_log::KIND_CALENDAR_UPDATE,
            'timequeued' => 1760000000, 'message' => '403 & <x>', 'name' => null, 'cmid' => null]];
        $html = $output->render(new admin_status_page($autosync, [], $calendar, [1 => $this->lastsync_row(1)]));

        $this->assertStringContainsString('<td class="cell c0">Curso &amp; Co</td>', $html);
        $viewurl = (new \moodle_url('/mod/googlemeet/view.php', ['id' => 70]))->out();
        $this->assertStringContainsString('<a href="' . $viewurl . '">Sala &amp; B</a>', $html);
        $this->assertStringContainsString('o&amp;x@example.com', $html);
        $this->assertStringContainsString(
            ' · ' . get_string('adminstatus_status_error', 'googlemeet')
                . '<div class="small text-muted">Token &lt;expired&gt;</div>',
            $html
        );
        $this->assertStringContainsString(get_string('adminstatus_deleted', 'googlemeet') . ' (#987)</td>', $html);
        $this->assertStringContainsString('<td class="cell c3 lastcol">403 &amp; &lt;x&gt;</td>', $html);
        $this->assertStringContainsString('<td class="cell c3">' . get_string('no') . '</td>', $html);
        $this->assertStringContainsString('<td class="cell c4">-</td>', $html);
        $this->assertStringContainsString(' · ' . get_string('adminstatus_status_success', 'googlemeet') . '</td>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        // The one problem section without rows still says so.
        $this->assertSame(1, substr_count($html, get_string('adminstatus_none', 'googlemeet')));
    }

    /**
     * The truncation note appears once the last-sync list reaches its limit, not before.
     */
    public function test_truncated(): void {
        global $PAGE;
        $this->resetAfterTest();
        $output = $PAGE->get_renderer('core');
        $rows = [];
        for ($i = 1; $i < ops_status::LAST_SYNC_LIMIT; $i++) {
            $rows[$i] = $this->lastsync_row($i);
        }
        $note = get_string('adminstatus_truncated', 'googlemeet', ops_status::LAST_SYNC_LIMIT);
        $this->assertStringNotContainsString($note, $output->render(new admin_status_page([], [], [], $rows)));
        $rows[ops_status::LAST_SYNC_LIMIT] = $this->lastsync_row(ops_status::LAST_SYNC_LIMIT);
        $this->assertStringContainsString(
            '<p class="small">' . $note . '</p>',
            $output->render(new admin_status_page([], [], [], $rows))
        );
    }
}
