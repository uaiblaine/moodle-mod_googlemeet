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

use context_module;
use mod_googlemeet\client;
use mod_googlemeet\local\recording_format;
use mod_googlemeet\local\recording_progress;
use mod_googlemeet\local\recording_search;
use mod_googlemeet\local\recordings;
use moodle_url;
use single_button;
use stdClass;

/**
 * The recordings panel of the activity view (mod_googlemeet/recordingstable and
 * mod_googlemeet/syncbutton): filters, pagination, per-lesson progress or the students' counts
 * for teachers, the trash and the Google Drive sync panel.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recordings_list {
    /**
     * Print the recordings panel: search and topic filters, the paginated list or cards and, for
     * teachers, the trash and the Google Drive sync panel.
     *
     * Prints nothing when the Google client is not enabled.
     *
     * @param object $googlemeet
     * @param object $cm
     * @param object $context
     * @param int $page Current page number (0-based).
     * @param string|null $orderoverride Optional order override from URL parameter.
     * @param string $query Free-text search.
     * @param string $topic Topic filter.
     * @param bool $pendingonly Only recordings the current user has not viewed (ignored with editrecording).
     * @return void
     */
    public static function print_recordings(
        $googlemeet,
        $cm,
        $context,
        $page = 0,
        $orderoverride = null,
        $query = '',
        $topic = '',
        bool $pendingonly = false
    ) {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER;

        $config = get_config('googlemeet');

        $client = new client();
        if (!$client->enabled) {
            return;
        }

        $params = ['googlemeetid' => $googlemeet->id];
        $hascapability = has_capability('mod/googlemeet:editrecording', $context);
        if (!$hascapability) {
            $params['visible'] = true;
        }

        // Recordings view preference (cards|list). Used both for the view modifier class on the
        // outer wrapper (so list view can use the full content width) and for the template branch.
        $recordingsview = get_user_preferences('mod_googlemeet_recordings_view', 'list');
        if ($recordingsview !== 'cards' && $recordingsview !== 'list') {
            $recordingsview = 'list';
        }

        $html = '<div id="googlemeet_recordings" class="googlemeet_recordings googlemeet-view-' . $recordingsview . '">';

        // AI features need the site switch and an API key.
        $aiconfig = get_config('googlemeet');
        $aienabled = !empty($aiconfig->enableai) && !empty($aiconfig->geminiapikey);
        $cangenerateai = $aienabled && has_capability('mod/googlemeet:generateai', $context);

        // Get pagination settings.
        $maxrecordings = isset($googlemeet->maxrecordings) ? (int) $googlemeet->maxrecordings : 12;
        $maxrecordings = max(1, min(20, $maxrecordings));

        // Get order - use override if provided, otherwise use instance setting.
        $order = $orderoverride !== null ? $orderoverride : ($googlemeet->recordingsorder ?? 'DESC');
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $page = max(0, (int) $page);

        // Fetch every recording (no SQL limit): the search and topic filters run in PHP before pagination.
        $allrecordings = recordings::list_recordings($params, $aienabled, $order, 0, 0);

        // Distinct topics for the filter chips come from the unfiltered set.
        $alltopics = recording_search::collect_topics($allrecordings);

        // Distinguishable lesson titles (computed over the whole activity so duplicates are detected).
        $titleitems = [];
        foreach ($allrecordings as $recording) {
            $titleitems[$recording->id] = ['name' => (string)$recording->name, 'topics' => $recording->aitopics ?? []];
        }
        $lessontitles = recordings::assign_lesson_titles(
            $titleitems,
            [(string)$googlemeet->name, (string)($googlemeet->originalname ?? '')]
        );
        foreach ($allrecordings as $recording) {
            if (isset($lessontitles[$recording->id])) {
                $recording->displayname = $lessontitles[$recording->id]['title'];
            }
        }

        // Apply filters in PHP (topics are stored as JSON; this stays DB-portable).
        if (trim((string)$query) !== '') {
            if ($hascapability) {
                $allrecordings = recording_search::load_recording_search_content($allrecordings, true);
            }
            $allrecordings = recording_search::filter_recordings_by_query($allrecordings, (string)$query, $hascapability);
        }
        if (trim((string)$topic) !== '') {
            $allrecordings = recording_search::filter_recordings_by_topic($allrecordings, (string)$topic);
        }
        $progresssummary = hero::get_progress_summary($googlemeet, $context);
        $showprogresssummary = !$hascapability && $progresssummary['total'] > 0;
        $pendingonly = $pendingonly && $showprogresssummary;
        if ($pendingonly) {
            $viewed = array_flip($progresssummary['viewedids']);
            $allrecordings = array_values(array_filter($allrecordings, static function ($recording) use ($viewed) {
                return !isset($viewed[(int)$recording->id]);
            }));
        }

        $totalrecordings = count($allrecordings);
        $totalpages = max(1, (int)ceil($totalrecordings / $maxrecordings));
        $page = min($page, max(0, $totalpages - 1)); // Ensure page is within bounds.
        $offset = $page * $maxrecordings;
        $recordings = array_slice($allrecordings, $offset, $maxrecordings);

        $progressbyrecording = [];
        if (!empty($recordings)) {
            $recordingids = array_map(static function ($recording) {
                return (int)$recording->id;
            }, $recordings);
            [$insql, $inparams] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'progressid');
            $progressbyrecording = $DB->get_records_sql(
                "SELECT recordingid, watchedseconds, completed, markedviewed
                   FROM {googlemeet_recording_progress}
                  WHERE userid = :userid
                    AND recordingid {$insql}",
                ['userid' => $USER->id] + $inparams
            );
        }

        // Teachers see their students' activity per lesson instead of their own viewing progress.
        $teacherstats = ($hascapability && !empty($recordings))
            ? self::get_recordings_student_stats($context, $recordingids) : [];

        // Page-level aggregates instead of one question/file query per recording.
        $pagerecordingids = array_map(static fn($r) => (int)$r->id, $recordings);
        $questioncounts = \mod_googlemeet\local\list_aggregates::published_question_counts(
            $googlemeet,
            $cm,
            $context,
            $pagerecordingids
        );
        $materialsbyrecording = \mod_googlemeet\local\list_aggregates::materials_by_recording($context, $pagerecordingids);
        foreach ($recordings as $recording) {
            $recordingprogress = recording_progress::recording_progress_state(
                $recording,
                $progressbyrecording[$recording->id] ?? null
            );
            foreach ($recordingprogress as $key => $value) {
                $recording->$key = $value;
            }
            if ($hascapability) {
                $stats = $teacherstats[$recording->id] ?? null;
                $opened = $stats ? (int)$stats->opened : 0;
                $completedcount = $stats ? (int)$stats->completedcount : 0;
                $recording->teacherstatsopened = $opened;
                $recording->teacherstatslabel = $opened === 0 ? get_string('list_teacherstats_none', 'googlemeet')
                    : get_string(
                        $opened === 1 ? 'list_teacherstats_opened_one' : 'list_teacherstats_opened',
                        'googlemeet',
                        $opened
                    );
                $recording->teacherstatshascompleted = $completedcount > 0;
                $recording->teacherstatscompletedlabel = $completedcount > 0
                    ? get_string('list_teacherstats_completed', 'googlemeet', $completedcount) : '';
            }
            $recording->publishedquestioncount = $questioncounts[(int)$recording->id] ?? 0;
            $recording->haspublishedquestions = $recording->publishedquestioncount > 0;
            $urlparams = ['id' => $cm->id, 'recording' => $recording->id];
            if ($page > 0) {
                $urlparams['rpage'] = $page;
            }
            $urlparams['rorder'] = $order;
            $urlparams += recording_search::list_filter_params((string)$query, (string)$topic);
            $recording->huburl = (new moodle_url('/mod/googlemeet/view.php', $urlparams))->out(false);
            $materialsurlparams = $urlparams;
            $materialsurlparams['tab'] = 'materials';
            $recording->materialshuburl = (new moodle_url('/mod/googlemeet/view.php', $materialsurlparams))->out(false);
            // Surface attached materials directly in the recordings list so teachers can
            // upload/manage from here and students can download without entering the hub.
            $materials = $materialsbyrecording[(int)$recording->id] ?? [];
            $recording->materials = $materials;
            $recording->hasmaterials = !empty($materials);
            $recording->materialcount = count($materials);
            $recording->cardmaterials = array_slice($materials, 0, 2);
            $recording->cardmaterialoverflow = max(0, count($materials) - count($recording->cardmaterials));
            $recording->cardhasmaterialoverflow = $recording->cardmaterialoverflow > 0;
            $recording->managematerialsurl = (new moodle_url(
                '/mod/googlemeet/material.php',
                ['id' => $cm->id, 'recording' => $recording->id]
            ))->out(false);
        }
        $prevgroup = null;
        foreach ($recordings as $recording) {
            $recording->showdategroup = ($recording->dategroup !== $prevgroup);
            $prevgroup = $recording->dategroup;
        }
        $topicchips = [];
        foreach ($alltopics as $t) {
            $topicchips[] = [
                'text' => $t,
                'url' => (new moodle_url(
                    '/mod/googlemeet/view.php',
                    ['id' => $cm->id, 'topic' => $t, 'rorder' => $order]
                ))->out(false),
                'active' => (recording_format::fold($t) === recording_format::fold((string)$topic)),
            ];
        }
        $cansubscriberecordings = has_capability('mod/googlemeet:subscriberecordings', $context);
        $issubscribed = $cansubscriberecordings && $DB->record_exists(
            'googlemeet_recording_subs',
            ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id]
        );
        $canpurge = has_capability('mod/googlemeet:removerecording', $context);
        $deletedrecordings = [];
        if ($hascapability) {
            $deletedrecords = $DB->get_records(
                'googlemeet_recordings',
                ['googlemeetid' => $googlemeet->id, 'deleted' => 1],
                'timedeleted DESC, createdtime DESC',
                'id,name,createdtime,timedeleted,trashreason'
            );
            $retentiondays = \mod_googlemeet\local\recording_cleanup::get_retention_days();
            foreach ($deletedrecords as $deletedrecording) {
                $deletedrecordings[] = [
                    'id' => $deletedrecording->id,
                    'name' => $deletedrecording->name,
                    'createdtimeformatted' => userdate($deletedrecording->createdtime),
                    'timedeletedformatted' => !empty($deletedrecording->timedeleted)
                        ? userdate($deletedrecording->timedeleted)
                        : get_string('never', 'googlemeet'),
                    // When the retention task will purge it ('' when automatic purge is off).
                    'purgenotice' => \mod_googlemeet\local\recording_cleanup::purge_notice(
                        (int)$deletedrecording->timedeleted,
                        $retentiondays,
                        null,
                        (string)$deletedrecording->trashreason
                    ),
                ];
            }
        }

        // Pagination data.
        $haspagination = $totalpages > 1;
        $hasprevious = $page > 0;
        $hasnext = $page < ($totalpages - 1);

        // Sort and page links are complete URLs: each carries rorder exactly once (never an appended duplicate), the
        // filters (rq, topic, rpending) and, for the page links, rpage. Sorting returns to the first page.
        $listurl = static function (string $linkorder, ?int $linkpage = null) use ($cm, $query, $topic, $pendingonly): string {
            $params = ['id' => $cm->id];
            if (trim((string)$query) !== '') {
                $params['rq'] = $query;
            }
            if (trim((string)$topic) !== '') {
                $params['topic'] = $topic;
            }
            if ($pendingonly) {
                $params['rpending'] = 1;
            }
            $params['rorder'] = $linkorder;
            if ($linkpage !== null) {
                $params['rpage'] = $linkpage;
            }
            return (new moodle_url('/mod/googlemeet/view.php', $params))->out(false);
        };

        // Build page numbers for pagination.
        $pages = [];
        for ($i = 0; $i < $totalpages; $i++) {
            $pages[] = [
                'number' => $i + 1,
                'page' => $i,
                'active' => ($i === $page),
                'currentorder' => $order,
                'coursemoduleid' => $cm->id,
                'url' => $listurl($order, $i),
            ];
        }

        // Calculate display range.
        $start = $totalrecordings > 0 ? $offset + 1 : 0;
        $end = min($offset + $maxrecordings, $totalrecordings);

        // Per-user recordings view preference (cards|list), with toggle URLs that
        // preserve the current content state (page/order/query/topic) and carry the
        // rview action param. view.php consumes rview, sets the preference and
        // redirects to a clean URL.
        $view = $recordingsview;
        $viewurlparams = ['id' => $cm->id, 'rorder' => $order];
        if ($page > 0) {
            $viewurlparams['rpage'] = $page;
        }
        if (trim((string)$query) !== '') {
            $viewurlparams['rq'] = $query;
        }
        if (trim((string)$topic) !== '') {
            $viewurlparams['topic'] = $topic;
        }
        if ($pendingonly) {
            $viewurlparams['rpending'] = 1;
        }
        $filterbaseparams = $viewurlparams;
        unset($filterbaseparams['rpage'], $filterbaseparams['rpending']);
        $viewcardsurl = (new moodle_url(
            '/mod/googlemeet/view.php',
            $viewurlparams + ['rview' => 'cards']
        ))->out(false);
        $viewlisturl = (new moodle_url(
            '/mod/googlemeet/view.php',
            $viewurlparams + ['rview' => 'list']
        ))->out(false);

        $hasactivefilters = trim((string)$query) !== '' || trim((string)$topic) !== '' || $pendingonly;
        $html .= \mod_googlemeet\local\ai_review::render_bulk_banner($googlemeet, $cm, $context);
        $html .= $OUTPUT->render_from_template('mod_googlemeet/recordingstable', [
            'recordings' => $recordings,
            'hasrecordings' => !empty($recordings),
            'coursemoduleid' => $cm->id,
            'hascapability' => $hascapability,
            'aienabled' => $aienabled,
            'cangenerateai' => $cangenerateai,
            'cansubscriberecordings' => $cansubscriberecordings,
            'issubscribed' => $issubscribed,
            'canpurge' => $canpurge,
            'deletedrecordings' => $deletedrecordings,
            'hasdeletedrecordings' => !empty($deletedrecordings),
            'deletedrecordingcount' => count($deletedrecordings),
            'sesskey' => sesskey(),
            // Pagination data.
            'haspagination' => $haspagination,
            'hasprevious' => $hasprevious,
            'hasnext' => $hasnext,
            'currentpage' => $page,
            'previouspage' => $page - 1,
            'nextpage' => $page + 1,
            'previouspageurl' => $listurl($order, max(0, $page - 1)),
            'nextpageurl' => $listurl($order, $page + 1),
            'sortdescurl' => $listurl('DESC'),
            'sortascurl' => $listurl('ASC'),
            'pages' => $pages,
            'totalrecordings' => $totalrecordings,
            'start' => $start,
            'end' => $end,
            'totalpages' => $totalpages,
            // Order data.
            'currentorder' => $order,
            'isorderdesc' => ($order === 'DESC'),
            'isorderasc' => ($order === 'ASC'),
            // Filter data.
            // Not pre-escaped: Mustache {{ }} HTML-escapes these on output, so s() here would double-encode.
            'recordingquery' => $query,
            'selectedtopic' => $topic,
            'alltopics' => $topicchips,
            'hasactivefilters' => $hasactivefilters,
            // Keep the filter bar while a search returns nothing, so the query can still be edited.
            'showfilterbar' => !empty($recordings) || $hasactivefilters,
            // Filters folded into the "Filters" panel on phones: topic and a non-default order.
            'activefiltercount' => (trim((string)$topic) !== '' ? 1 : 0)
                + ($order !== (strtoupper((string)($googlemeet->recordingsorder ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC') ? 1 : 0),
            'showprogresssummary' => $showprogresssummary,
            'ispendingfilter' => $pendingonly,
            'pendingparam' => $pendingonly ? 1 : 0,
            'pendingfilterurl' => (new moodle_url('/mod/googlemeet/view.php', $filterbaseparams + ['rpending' => 1]))->out(false),
            'allfilterurl' => (new moodle_url('/mod/googlemeet/view.php', $filterbaseparams))->out(false),
            'clearfiltersurl' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]))->out(false),
            // View toggle (cards|list).
            'isviewcards' => ($view === 'cards'),
            'isviewlist' => ($view === 'list'),
            'viewcardsurl' => $viewcardsurl,
            'viewlisturl' => $viewlisturl,
        ] + hero::progress_summary_context($progresssummary));

        if ($hascapability) {
            $lastsync = get_string('never', 'googlemeet');
            if ($googlemeet->lastsync) {
                $lastsync = userdate($googlemeet->lastsync, get_string('timedate', 'googlemeet'));
            }

            $meetingcode = client::extract_meeting_code((string)($googlemeet->url ?? ''));
            $redordingname = $meetingcode ? '"' . $meetingcode . '" ' : '';
            if ($googlemeet->originalname) {
                $redordingname .= ($redordingname !== '' ? get_string('or', 'googlemeet') . ' ' : '') .
                    '"' . $googlemeet->originalname . '"';
            }

            $loginhtml = '';
            $syncbutton = '';
            $islogged = false;
            $cansync = \mod_googlemeet\local\sync_manager::can_request($context);
            $isloggedcreatoremail = $client->get_email() === $googlemeet->creatoremail;
            if (!$client->check_login()) {
                $loginhtml = $client->print_login_popup();
            } else {
                $islogged = true;
                $loginhtml = $client->print_user_info('drive');

                if ($cansync) {
                    $url = new moodle_url($PAGE->url);
                    $url->param('sync', true);
                    $syncbutton = new single_button($url, get_string('syncwithgoogledrive', 'googlemeet'), 'post', true);
                    $syncbutton = $OUTPUT->render($syncbutton);
                }
            }

            $syncstatus = \mod_googlemeet\local\sync_manager::get_status($googlemeet);
            $html .= $OUTPUT->render_from_template('mod_googlemeet/syncbutton', [
                // Async sync panel state.
                'cmid' => $cm->id,
                'syncactive' => $syncstatus['active'],
                'syncstatusmessage' => $syncstatus['active'] ? $syncstatus['message'] : '',
                'lastsync' => $lastsync,
                'creatoremail' => $googlemeet->creatoremail,
                'redordingname' => $redordingname,
                'login' => $loginhtml,
                'islogged' => $islogged,
                'syncbutton' => $syncbutton,
                'cansync' => $cansync,
                'isloggedcreatoremail' => $isloggedcreatoremail,
            ]);
        }

        $html .= '</div>';

        echo $html;
    }

    /**
     * Per-recording count of students who opened / completed each recording (teacher list view).
     *
     * "Opened" means a googlemeet_recording_progress row exists (some viewing time was recorded). Users who can
     * edit recordings (teachers) are left out so the numbers describe students only. One grouped query per page.
     *
     * @param context_module $context Module context.
     * @param int[] $recordingids Recordings on the current page.
     * @return stdClass[] Keyed by recording id: {recordingid, opened, completedcount}.
     */
    public static function get_recordings_student_stats(context_module $context, array $recordingids): array {
        global $DB;

        if (empty($recordingids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'statsrid');
        $where = "p.recordingid {$insql}";
        $editorids = array_keys(get_users_by_capability($context, 'mod/googlemeet:editrecording', 'u.id'));
        if ($editorids) {
            [$notinsql, $notinparams] = $DB->get_in_or_equal($editorids, SQL_PARAMS_NAMED, 'statsuid', false);
            $where .= " AND p.userid {$notinsql}";
            $params += $notinparams;
        }
        return $DB->get_records_sql(
            "SELECT p.recordingid, COUNT(1) AS opened, SUM(p.completed) AS completedcount
               FROM {googlemeet_recording_progress} p
              WHERE {$where}
           GROUP BY p.recordingid",
            $params
        );
    }
}
