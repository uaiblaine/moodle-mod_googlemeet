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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/mod_form.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/client_test_fake_oauth_client.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/client_userinfo_testable_client.php');

/**
 * Markup of the Google account UI: the login button, the linked-account box and their wrappers.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(client::class)]
#[CoversClass(\mod_googlemeet_mod_form::class)]
final class google_account_ui_test extends \advanced_testcase {
    /**
     * Forget the request parameters a test set.
     */
    protected function tearDown(): void {
        unset($_GET['client_islogged']);
        parent::tearDown();
    }

    /**
     * Configure an enabled Google issuer with no stored token (the teacher is not logged in).
     */
    private function configure_issuer(): void {
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'Google', 'image' => '', 'baseurl' => 'https://accounts.google.com',
            'clientid' => 'client', 'clientsecret' => 'secret', 'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email', 'loginparams' => '',
            'loginparamsoffline' => 'access_type=offline&prompt=consent', 'alloweddomains' => '', 'enabled' => 1,
            'showonloginpage' => 0, 'basicauth' => 0, 'servicetype' => 'google',
        ]);
        $issuer->create();
        $endpoints = [
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ];
        foreach ($endpoints as $name => $url) {
            (new \core\oauth2\endpoint(0, (object)['issuerid' => $issuer->get('id'), 'name' => $name, 'url' => $url]))->create();
        }
        set_config('issuerid', $issuer->get('id'), 'googlemeet');
    }

    /**
     * Parse an HTML fragment.
     *
     * @param string $html Fragment.
     * @return \DOMXPath
     */
    private function xpath(string $html): \DOMXPath {
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR);
        return new \DOMXPath($doc);
    }

    /**
     * A client logged in to Google as the fake account, its userinfo served from the cache.
     *
     * @param string $name Google account name to cache.
     * @return client
     */
    private function logged_in_client(string $name): client {
        client_test_fake_oauth_client::$loggedin = true;
        $key = (new \ReflectionMethod(client::class, 'userinfo_cache_key'))->invoke(null);
        \cache::make('mod_googlemeet', 'userinfo')->set($key, [
            'username' => 'teacher@example.com',
            'name' => $name,
            'picture' => base64_encode('JPEGDATA'),
        ]);
        return (new \ReflectionClass(client_userinfo_testable_client::class))->newInstanceWithoutConstructor();
    }

    /**
     * The login button carries its URL in data-loginurl (escaped once) and the AMD module opens it: no inline JS.
     */
    public function test_login_button_carries_url_in_data_attribute(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_issuer();
        $client = new client();
        $loginurl = (new \ReflectionMethod(client::class, 'get_user_oauth_client'))->invoke($client)->get_login_url();

        $html = $client->print_login_popup();

        $buttons = $this->xpath($html)->query('//div[@class="mt-2"]/button');
        $this->assertSame(1, $buttons->length);
        $button = $buttons->item(0);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertSame('btn btn-primary', $button->getAttribute('class'));
        $this->assertSame($loginurl->out(false), $button->getAttribute('data-loginurl'));
        $this->assertSame(get_string('logintoaccount', 'googlemeet'), $button->textContent);
        $this->assertFalse($button->hasAttribute('onclick'));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&amp;response_type=code', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);

        $buttonid = $button->getAttribute('id');
        $this->assertMatchesRegularExpression('/^googlemeet_login_[A-Za-z0-9]+$/', $buttonid);
        $amd = implode("\n", (new \ReflectionProperty(\page_requirements_manager::class, 'amdjscode'))->getValue($PAGE->requires));
        $this->assertStringContainsString("require(['mod_googlemeet/google_login']", $amd);
        $this->assertStringContainsString('amd.init("' . $buttonid . '")', $amd);
        $this->assertStringNotContainsString('window.open', $amd);
    }

    /**
     * The linked-account box links the picture to Google and the logout link carries logout=1 and sesskey.
     */
    public function test_user_info_logout_link_has_sesskey(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/course/modedit.php', ['update' => 5]);

        $xpath = $this->xpath($this->logged_in_client('Ana Docente')->print_user_info('calendar'));

        $picture = $xpath->query('//div[@id="googlemeet_auth-info"]/a[@id="googlemeet_picture-user"]')->item(0);
        $this->assertSame('https://calendar.google.com/', $picture->getAttribute('href'));
        $this->assertSame('_blank', $picture->getAttribute('target'));
        $this->assertSame(get_string('manage', 'googlemeet'), $picture->getAttribute('title'));
        $img = $xpath->query('img', $picture)->item(0);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('JPEGDATA'), $img->getAttribute('src'));
        $this->assertSame('', $img->getAttribute('alt'));

        $spans = $xpath->query('//div[@id="googlemeet_user-name"]/span');
        $this->assertSame(3, $spans->length);
        $this->assertSame(get_string('loggedinaccount', 'googlemeet'), $spans->item(0)->textContent);
        $this->assertSame('Ana Docente', $spans->item(1)->textContent);
        $this->assertSame('teacher@example.com', $spans->item(2)->textContent);

        $logout = $xpath->query('//div[@id="googlemeet_auth-info"]/a[@class="btn btn-secondary btn-sm"]')->item(0);
        $this->assertSame(get_string('logout', 'googlemeet'), $logout->getAttribute('title'));
        $url = new \moodle_url($logout->getAttribute('href'));
        $this->assertSame('/moodle/course/modedit.php', $url->get_path());
        $this->assertSame(['update' => '5', 'logout' => '1', 'sesskey' => sesskey()], $url->params());
        $this->assertSame(1, $xpath->query('i[contains(@class, "fa-sign-out")]', $logout)->length);
    }

    /**
     * The Google account name is text: escaped exactly once.
     */
    public function test_user_info_escapes_the_google_name_once(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/course/view.php', ['id' => 2]);

        $html = $this->logged_in_client('Ana & <b>Docente</b>')->print_user_info('drive');

        $this->assertStringContainsString('<span>Ana &amp; &lt;b&gt;Docente&lt;/b&gt;</span>', $html);
        $this->assertStringContainsString('href="https://drive.google.com/"', $html);
    }

    /**
     * The activity form asks a teacher not logged in to Google to log in, and says so when the session expired.
     */
    public function test_form_login_notices(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_issuer();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $PAGE->set_url('/course/modedit.php', ['add' => 'googlemeet', 'course' => $course->id]);

        $expected = [
            'alert-info' => 'logintoyourgoogleaccount',
            'alert-danger' => 'sessionexpired',
        ];
        foreach ($expected as $alertclass => $stringid) {
            if ($alertclass === 'alert-danger') {
                $_GET['client_islogged'] = 1;
            }
            $current = (object)['instance' => '', 'course' => $course->id, 'section' => 0, 'coursemodule' => '',
                'add' => 'googlemeet', 'modulename' => 'googlemeet'];
            $form = new \mod_googlemeet_mod_form($current, 0, null, $course);
            $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
            $html = '';
            foreach ($mform->_elements as $element) {
                if ($element->getType() === 'html') {
                    $html .= $element->_text;
                }
            }

            $xpath = $this->xpath($html);
            $notices = $xpath->query('//div[contains(@class, "googlemeet_loginbutton")]');
            $this->assertSame(1, $notices->length, $alertclass);
            $notice = $notices->item(0);
            $this->assertSame('mdl-align alert ' . $alertclass . ' googlemeet_loginbutton', $notice->getAttribute('class'));
            $this->assertSame(get_string($stringid, 'googlemeet'), trim($notice->firstChild->textContent));
            $this->assertSame(1, $xpath->query('div[@class="mt-2"]/button[@data-loginurl]', $notice)->length);
        }
    }

    /**
     * The settings page links to the sync status page.
     */
    public function test_settings_link_to_status_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $page = admin_get_root(true, true)->locate('modsettinggooglemeet');

        $xpath = $this->xpath($page->settings->googlemeetadminstatus_heading->description);

        $link = $xpath->query('//a')->item(0);
        $this->assertSame((new \moodle_url('/mod/googlemeet/admin_status.php'))->out(false), $link->getAttribute('href'));
        $this->assertSame(get_string('adminstatus_link', 'googlemeet'), $link->textContent);
    }
}
