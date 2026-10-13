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

use mod_googlemeet\local\attendance\report;
use mod_googlemeet\local\attendance\service;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Markup of the attendance page body: the write controls follow the manage flag and post with the sesskey.
 *
 * Who holds the flag is attendance.php's decision, pinned through the page in attendance_access_test.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(attendance_page::class)]
final class attendance_page_test extends \advanced_testcase {
    /** @var \stdClass Course-module stand-in (only the id is read). */
    private $cm;

    /** @var \stdClass Selected session. */
    private $session;

    /** @var \stdClass Link candidate. */
    private $candidate;

    /**
     * A session with one matched, one unmatched and one absent row.
     *
     * @return array[]
     */
    private function rows(): array {
        $base = ['email' => '', 'timejoined' => 1760000000, 'timeleft' => 1760001830, 'durationseconds' => 1830,
            'sessions' => 1];
        return [
            ['name' => 'Ana Alpha', 'kind' => report::ROW_PRESENT, 'matchedby' => 'email', 'attendanceid' => 41,
                'meetname' => 'Ana & <co>', 'userid' => 5] + $base,
            ['name' => 'Visitor', 'kind' => report::ROW_UNMATCHED, 'matchedby' => '', 'attendanceid' => 42,
                'meetname' => 'Visitor & co', 'userid' => 0] + $base,
            ['name' => 'Carla Gamma', 'kind' => report::ROW_ABSENT, 'matchedby' => '', 'attendanceid' => 0,
                'meetname' => '', 'userid' => 6, 'timejoined' => 0, 'timeleft' => 0, 'durationseconds' => 0] + $base,
        ];
    }

    /**
     * The renderable for the selected session.
     *
     * @param bool $canmanage Manage flag.
     * @param array[] $rows Session rows.
     * @param \stdClass|null $sync Sync row.
     * @return attendance_page
     */
    private function page(bool $canmanage, array $rows, ?\stdClass $sync = null): attendance_page {
        return new attendance_page(
            $this->cm,
            [$this->session->id => $this->session],
            $this->session,
            $sync,
            $rows,
            $canmanage,
            $canmanage ? [$this->candidate->id => $this->candidate] : [],
            [],
            '',
            ''
        );
    }

    /**
     * Fixtures.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->cm = (object)['id' => 77];
        $this->session = (object)['id' => 9, 'eventdate' => 1760000000, 'status' => service::STATUS_DONE,
            'matched' => 1, 'participants' => 2];
        $this->candidate = $this->getDataGenerator()->create_user(['firstname' => 'Bruno', 'lastname' => 'Beta & Co']);
    }

    /**
     * A manager gets the refetch button (a POST form with the sesskey), a link form on the unmatched row and
     * an unlink form on the matched one, both posting action=link with the sesskey.
     */
    public function test_manager_controls(): void {
        global $PAGE;
        $data = $this->page(true, $this->rows())->export_for_template($PAGE->get_renderer('core'));
        $session = $data['session'];
        $this->assertTrue($session['hasrefetch']);
        $this->assertMatchesRegularExpression(
            '~<form method="post"[^>]*>(?:(?!</form>).)*name="action" value="refetch"(?:(?!</form>).)*name="sesskey" value="'
                . sesskey() . '"~s',
            $session['refetchhtml']
        );
        $this->assertSame([true, false, false], array_column($session['rows'], 'canunlink'));
        $this->assertSame([false, true, false], array_column($session['rows'], 'canlink'));
        // The placeholder (value 0) is preselected and sorted in with the candidates, as html_writer::select() had it.
        $this->assertSame([(int)$this->candidate->id, 0], array_column($session['options'], 'value'));
        $this->assertSame([false, true], array_column($session['options'], 'selected'));

        $html = $PAGE->get_renderer('core')->render($this->page(true, $this->rows()));
        $forms = [];
        preg_match_all('~<form method="post" action="([^"]*)" class="([^"]*)">(.*?)</form>~s', $html, $forms, PREG_SET_ORDER);
        $byclass = [];
        foreach ($forms as $form) {
            $byclass[$form[2]] = $form;
        }
        $unlink = $byclass['d-inline googlemeet-attendance-unlink'];
        $link = $byclass['d-flex flex-wrap gap-1 mt-1 googlemeet-attendance-link'];
        foreach ([$unlink, $link] as $form) {
            $this->assertStringEndsWith('/mod/googlemeet/attendance.php?id=77', $form[1]);
            foreach (['id' => 77, 'session' => 9, 'action' => 'link', 'sesskey' => sesskey()] as $name => $value) {
                $this->assertStringContainsString('<input type="hidden" name="' . $name . '" value="' . $value . '">', $form[3]);
            }
        }
        $this->assertStringContainsString('name="attendanceid" value="41"', $unlink[3]);
        $this->assertStringContainsString('<input type="hidden" name="userid" value="0">', $unlink[3]);
        $this->assertStringContainsString('name="attendanceid" value="42"', $link[3]);
        $this->assertStringContainsString('<select id="googlemeet-attendance-link-42"', $link[3]);
        $this->assertStringContainsString('<label class="visually-hidden" for="googlemeet-attendance-link-42">', $link[3]);
        $this->assertStringContainsString('<option value="0" selected>', $link[3]);
        // The candidate's name is escaped once.
        $this->assertStringContainsString('>Bruno Beta &amp; Co</option>', $link[3]);
        // No whitespace between the name and the inline unlink form: it would show as a space.
        $this->assertStringContainsString('Ana Alpha<form', $html);
    }

    /**
     * A viewer without the manage flag gets no refetch button, no link or unlink form and no candidate list.
     */
    public function test_viewer_has_no_controls(): void {
        global $PAGE;
        $data = $this->page(false, $this->rows())->export_for_template($PAGE->get_renderer('core'));
        $session = $data['session'];
        $this->assertFalse($session['hasrefetch']);
        $this->assertSame('', $session['refetchhtml']);
        $this->assertSame([false, false, false], array_column($session['rows'], 'canlink'));
        $this->assertSame([false, false, false], array_column($session['rows'], 'canunlink'));

        $html = $PAGE->get_renderer('core')->render($this->page(false, $this->rows()));
        $this->assertStringContainsString('Visitor &amp; co', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('sesskey', $html);
        $this->assertStringNotContainsString(get_string('attendance_refetch', 'googlemeet'), $html);
    }

    /**
     * Participant text is escaped exactly once, in element content and in the unlink title attribute.
     */
    public function test_participant_text_escaped_once(): void {
        global $PAGE;
        $html = $PAGE->get_renderer('core')->render($this->page(true, $this->rows()));
        $this->assertStringContainsString('<td class="cell c2">Ana &amp; &lt;co&gt;</td>', $html);
        $title = s(get_string('attendance_unlink_title', 'googlemeet', 'Ana & <co>'));
        $this->assertStringContainsString('title="' . $title . '"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString(
            '>' . s(get_string('attendance_link_label', 'googlemeet', 'Visitor & co')) . '</label>',
            $html
        );
        // How the match was made, and an absent row's empty minutes.
        $this->assertStringContainsString(
            '<small class="text-muted"> (' . get_string('attendance_matchedby_email', 'googlemeet') . ')</small>',
            $html
        );
        $this->assertStringContainsString('<td class="cell c5 lastcol"></td>', $html);
        $this->assertStringContainsString('<td class="cell c5 lastcol">31</td>', $html);
    }

    /**
     * Empty states: no past session shows the notice and no table; a session without rows shows its status
     * (with the error message) but no table and no export.
     */
    public function test_empty_states(): void {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $page = new attendance_page($this->cm, [], null, null, [], true, [], [], '', '');
        $data = $page->export_for_template($output);
        $this->assertFalse($data['hassessions']);
        $this->assertStringContainsString(get_string('attendance_no_sessions', 'googlemeet'), $data['nosessionshtml']);
        $html = $output->render($page);
        $this->assertStringNotContainsString('<table', $html);

        $sync = (object)['status' => service::STATUS_ERROR, 'message' => 'Quota <exceeded>'];
        $html = $output->render($this->page(true, [], $sync));
        $this->assertStringContainsString('googlemeet-attendance-sessions', $html);
        $this->assertStringNotContainsString('googlemeet-attendance-table', $html);
        $this->assertStringContainsString(
            '<p>' . get_string('attendance_status_error', 'googlemeet') . ' · Quota &lt;exceeded&gt;</p>',
            $html
        );
        // The selected session is shown in bold, the others as links.
        $this->assertMatchesRegularExpression('~<td class="cell c0"><strong>[^<]+</strong></td>~', $html);
    }
}
