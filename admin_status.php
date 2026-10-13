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
 * Admin page: Google Meet sync status.
 *
 * Failed/exhausted autosync in the last week, stuck AI analyses, failed Calendar updates and
 * the last sync of each activity (up to ops_status::LAST_SYNC_LIMIT rows), so admins do not
 * need to read cron logs.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_googlemeet\local\ops_status;
use mod_googlemeet\output\admin_status_page;

admin_externalpage_setup('mod_googlemeet_status');

$PAGE->set_url(new moodle_url('/mod/googlemeet/admin_status.php'));
$PAGE->set_title(get_string('adminstatus', 'googlemeet'));
$PAGE->set_heading(get_string('adminstatus', 'googlemeet'));

echo $OUTPUT->header();
echo $OUTPUT->render(new admin_status_page(
    ops_status::autosync_problems(),
    ops_status::stuck_ai_analyses(),
    ops_status::calendar_failures(),
    ops_status::last_syncs()
));
echo $OUTPUT->footer();
