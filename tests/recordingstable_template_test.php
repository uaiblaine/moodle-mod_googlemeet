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

use mod_googlemeet\output\recordings_list;
use PHPUnit\Framework\Attributes\CoversClass;


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * The recordings list template: URL-encoded sort and page links, JS-quoted lang strings.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recordings_list::class)]
final class recordingstable_template_test extends \advanced_testcase {
    /** @var string Search text with the characters a query string gives a meaning to. */
    private const QUERY = 'a&b #c';

    /** @var string Topic with an ampersand. */
    private const TOPIC = 'Q&A';

    /**
     * Configure a Google OAuth issuer without network access so the list renders (client enabled).
     *
     * @return void
     */
    private function configure_issuer(): void {
        // Persisted directly: api::create_issuer() would run endpoint discovery over HTTP.
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'Google',
            'image' => '',
            'baseurl' => 'https://accounts.google.com',
            'clientid' => 'client',
            'clientsecret' => 'secret',
            'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email',
            'loginparams' => '',
            'loginparamsoffline' => 'access_type=offline&prompt=consent',
            'showonloginpage' => 0,
        ]);
        $issuer->create();
        foreach (
            [
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            ] as $name => $url
        ) {
            (new \core\oauth2\endpoint(0, (object)['issuerid' => $issuer->get('id'), 'name' => $name, 'url' => $url]))->create();
        }
        set_config('issuerid', $issuer->get('id'), 'googlemeet');
    }

    /**
     * Render the second of three pages of a search for self::QUERY within self::TOPIC, as the admin.
     *
     * @return array [html, inline JavaScript]
     */
    private function render(): array {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_issuer();
        // Topics are read only while the AI features are on.
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'url' => 'https://meet.google.com/abc-defg-hij',
            'maxrecordings' => 1,
        ]);
        $DB->set_field('googlemeet', 'maxrecordings', 1, ['id' => $gm->id]);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $gm->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        for ($i = 1; $i <= 3; $i++) {
            $id = (int)$DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $googlemeet->id,
                'recordingid' => 'drive-' . $i . '-' . random_string(6),
                'name' => 'Clase ' . self::QUERY . ' ' . $i,
                'createdtime' => 1700000000 + $i * DAYSECS,
                'duration' => '1:00:00',
                'webviewlink' => 'https://drive.google.com/file/d/x' . $i . '/view',
                'visible' => 1,
                'deleted' => 0,
                'timemodified' => time(),
            ]);
            $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_ai_analysis([
                'recordingid' => $id,
                'topics' => json_encode([self::TOPIC]),
                'reviewed' => 1,
            ]);
        }

        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
        $PAGE->set_cm($cm);
        $PAGE->set_context($context);
        ob_start();
        recordings_list::print_recordings($googlemeet, $cm, $context, 1, null, self::QUERY, self::TOPIC);
        $html = ob_get_clean();
        $js = implode(";\n", (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires));
        return [$html, $js];
    }

    /**
     * Every sort and page link carries the search and the topic intact.
     */
    public function test_sort_and_page_links_encode_the_filters(): void {
        [$html] = $this->render();

        preg_match_all('/<a [^>]*href="([^"]*)"[^>]*>/', $html, $matches);
        $orders = [];
        $pages = [];
        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);
            parse_str((string)parse_url($href, PHP_URL_QUERY), $params);
            // View toggles and the links into a class (hub) are built in PHP and are not list navigation.
            if (!isset($params['rorder']) || isset($params['rview']) || isset($params['recording'])) {
                continue;
            }
            // A repeated parameter is resolved by the server, not by us: each name must appear once.
            $query = (string)parse_url($href, PHP_URL_QUERY);
            $this->assertSame(1, preg_match_all('/(^|&)rorder=/', $query), $href);
            $this->assertLessThanOrEqual(1, preg_match_all('/(^|&)rpage=/', $query), $href);
            $this->assertSame(self::QUERY, $params['rq'] ?? null, $href);
            $this->assertSame(self::TOPIC, $params['topic'] ?? null, $href);
            if (isset($params['rpage'])) {
                $pages[] = (int)$params['rpage'];
            } else {
                $orders[] = $params['rorder'];
            }
        }
        // Sort links (they reset to the first page), plus the "all" filter link, which keeps the order.
        $this->assertContains('DESC', $orders);
        $this->assertContains('ASC', $orders);
        // Previous, the two other page numbers, next.
        sort($pages);
        $this->assertSame([0, 0, 2, 2], $pages);
        $this->assertStringNotContainsString('rq=a&amp;b', $html);
    }

    /**
     * The links into a class (hub and its materials tab) carry the list's search and topic.
     */
    public function test_hub_links_carry_the_filters(): void {
        [$html] = $this->render();

        preg_match_all('/<a [^>]*href="([^"]*)"[^>]*>/', $html, $matches);
        $hublinks = 0;
        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);
            parse_str((string)parse_url($href, PHP_URL_QUERY), $params);
            if (!isset($params['recording']) || !str_contains($href, '/mod/googlemeet/view.php')) {
                continue;
            }
            $hublinks++;
            $this->assertSame(self::QUERY, $params['rq'] ?? null, $href);
            $this->assertSame(self::TOPIC, $params['topic'] ?? null, $href);
        }
        $this->assertGreaterThan(0, $hublinks);
    }

    /**
     * Lang strings inside the inline script are JSON-quoted literals, so an apostrophe cannot end them.
     */
    public function test_inline_script_quotes_lang_strings(): void {
        [, $js] = $this->render();
        $keys = ['recording_purge', 'recording_purge_confirm', 'recording_trash', 'recording_trash_confirm', 'recording_hidden'];
        foreach ($keys as $key) {
            $text = get_string($key, 'googlemeet');
            $this->assertStringContainsString(json_encode($text), $js, $key);
            $this->assertStringNotContainsString("'" . $text . "'", $js, $key);
        }
        $this->assertStringNotContainsString('>' . get_string('recording_hidden', 'googlemeet') . '<', $js);
    }
}
