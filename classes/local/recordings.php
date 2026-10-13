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

use completion_info;
use context_module;
use stdClass;

/**
 * Recording listing, counts, the Drive sync entry point, display names, lesson titles and the
 * lookup of one recording the current user may open.
 *
 * Also holds the course-module-viewed event and completion helper that view.php and the
 * mobile view call.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recordings {
    /**
     * List recordings with their display, AI and status fields for the templates.
     *
     * Unreviewed AI content is dropped for users who may not see it ({@see \mod_googlemeet\local\ai_review}),
     * so the list snippet, search, topic chips and topic practice built from this list never expose it.
     *
     * @param array $params Field conditions on googlemeet_recordings, e.g. ['googlemeetid' => 5]; 'deleted' defaults to 0.
     * @param bool $includeai Include AI analysis data.
     * @param string $order Sort order: 'DESC' for newest first, 'ASC' for oldest first.
     * @param int $limit Maximum number of recordings to return (0 for all).
     * @param int $offset Number of recordings to skip.
     * @return stdClass[] List of recordings.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function list_recordings($params, $includeai = false, $order = 'DESC', $limit = 0, $offset = 0) {
        global $DB;

        // Validate order parameter.
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        if (!array_key_exists('deleted', $params)) {
            $params['deleted'] = 0;
        }

        $recordings = $DB->get_records(
            'googlemeet_recordings',
            $params,
            'createdtime ' . $order,
            'id,googlemeetid,name,createdtime,duration,webviewlink,visible',
            $offset,
            $limit
        );

        // Get all recording IDs for batch AI query.
        $recordingids = array_keys($recordings);

        // Fetch AI analysis data if enabled and there are recordings.
        $aidata = [];
        if ($includeai && !empty($recordingids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
            $sql = "SELECT recordingid, summary, keypoints, topics, status, error, aimodel, timemodified, retrycount, nextretry,
                           reviewed
                    FROM {googlemeet_ai_analysis}
                    WHERE recordingid $insql";
            $airecords = $DB->get_records_sql($sql, $inparams);
            foreach ($airecords as $ai) {
                $aidata[$ai->recordingid] = $ai;
            }
        }

        // Per activity instance: whether the current user may see unreviewed AI content.
        $canseeunreviewed = [];

        $formattedrecordings = [];
        foreach ($recordings as $recording) {
            $recording->originalname = $recording->name;
            $recording->displayname = self::display_name((string)$recording->name);
            $recording->createdtimeformatted = userdate($recording->createdtime);
            // Card date chip only; full timestamps (createdtimeformatted) stay as userdate() returns them.
            $recording->createddateshort = recording_format::format_date_chip(
                userdate($recording->createdtime, get_string('strftimedm', 'googlemeet'))
            );
            $recording->isnew = recording_format::recording_is_new((int)$recording->createdtime, time(), 7);
            $recording->dategroup = recording_format::recording_date_group((int)$recording->createdtime);

            $ai = $aidata[$recording->id] ?? null;
            $status = $ai->status ?? null;
            $flags = recording_format::ai_status_flags($status);
            foreach ($flags as $key => $value) {
                $recording->$key = $value;
            }

            // The hasai flag means a completed analysis with some content.
            $hascontent = $ai && $status === 'completed'
                && (trim((string)$ai->summary) !== '' || ($ai->keypoints ?? '') !== '' || ($ai->topics ?? '') !== '');
            // Unreviewed content is dropped for students; teachers keep it with a "pending review" flag.
            $recording->aipendingreview = false;
            if ($hascontent && \mod_googlemeet\local\ai_review::is_pending_review($ai)) {
                $gmid = (int)$recording->googlemeetid;
                if (!array_key_exists($gmid, $canseeunreviewed)) {
                    $canseeunreviewed[$gmid] = \mod_googlemeet\local\ai_review::can_see_unreviewed($gmid);
                }
                $hascontent = $canseeunreviewed[$gmid];
                $recording->aipendingreview = $hascontent;
            }
            // Teachers see a stuck analysis as such (not as an endless "in progress").
            $recording->aistatusisstuck = false;
            if ($ai && \mod_googlemeet\ai_service::is_stuck($ai)) {
                $gmid = (int)$recording->googlemeetid;
                if (!array_key_exists($gmid, $canseeunreviewed)) {
                    $canseeunreviewed[$gmid] = \mod_googlemeet\local\ai_review::can_see_unreviewed($gmid);
                }
                if ($canseeunreviewed[$gmid]) {
                    $recording->aistatusisstuck = true;
                    $recording->aistatusisprocessing = false;
                }
            }
            if ($hascontent) {
                $recording->hasai = true;
                $recording->aisummary = $ai->summary;
                $recording->aisummarypreview = recording_format::truncate_summary((string)$ai->summary, 200);
                $recording->aikeypoints = json_decode($ai->keypoints) ?: [];
                $recording->aikeypointscount = count($recording->aikeypoints);
                $recording->aikeypointslabel = recording_format::keypoints_label($recording->aikeypointscount);
                $recording->aitopics = json_decode($ai->topics) ?: [];
                $tp = recording_search::topic_preview($recording->aitopics, 3);
                $recording->aitopicsvisible = $tp['visible'];
                $recording->aitopicsoverflow = $tp['overflow'];
                $recording->aimodel = $ai->aimodel;
                $recording->aidate = userdate($ai->timemodified, get_string('strftimedmy', 'googlemeet'));
            } else {
                $recording->hasai = false;
                $recording->aisummary = '';
                $recording->aisummarypreview = '';
                $recording->aikeypoints = [];
                $recording->aikeypointscount = 0;
                $recording->aikeypointslabel = '';
                $recording->aitopics = [];
                $recording->aitopicsvisible = [];
                $recording->aitopicsoverflow = 0;
                $recording->aimodel = '';
                $recording->aidate = '';
            }

            $formattedrecordings[] = $recording;
        }

        return $formattedrecordings;
    }

    /**
     * Counts the total number of recordings for a Google Meet instance.
     *
     * @param array $params Field conditions on googlemeet_recordings; 'deleted' defaults to 0.
     * @return int Total number of recordings.
     * @throws \dml_exception
     */
    public static function count_recordings($params) {
        global $DB;
        if (!array_key_exists('deleted', $params)) {
            $params['deleted'] = 0;
        }
        return $DB->count_records('googlemeet_recordings', $params);
    }

    /**
     * Synchronizes Google Drive recordings with the database.
     *
     * New Drive files are inserted, trashed rows that reappear are restored, and active rows missing
     * from Drive are moved to the trash. Requires mod/googlemeet:syncgoogledrive in the module context.
     *
     * @param int $googlemeetid the googlemeet ID
     * @param array $files Drive recording objects (recordingId, name, createdTime, duration, webViewLink, optional
     *     notestext/transcripttext); entries with ->unprocessed set are neither inserted nor restored.
     * @param bool $deferenrichment Queue one process_recording_enrichment task for the new and restored recordings
     *     instead of queuing AI analysis and new-recording notifications here.
     * @return array 'recordings' list, 'stats' (inserted, updated, deleted, trashed, restored counts),
     *     'newrecordingids' and 'restoredrecordingids'.
     * @throws \dml_exception
     * @throws \required_capability_exception
     * @throws \moodle_exception
     */
    public static function sync_recordings($googlemeetid, $files, bool $deferenrichment = false) {
        global $DB;

        $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/googlemeet:syncgoogledrive', $context);

        $googlemeetrecordings = $DB->get_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);

        // Lookup maps keyed by the Drive recording id.
        $recordingsbyid = [];
        foreach ($googlemeetrecordings as $rec) {
            $recordingsbyid[$rec->recordingid] = $rec;
        }

        $fileidsmap = [];
        $filesbyid = [];
        foreach ($files as $file) {
            if (isset($file->recordingId)) {
                $fileidsmap[$file->recordingId] = true;
                $filesbyid[$file->recordingId] = $file;
            }
        }

        $insertrecordings = [];
        $restorerecordings = [];
        $trashrecordings = [];

        foreach ($files as $file) {
            if (isset($file->unprocessed) || empty($file->recordingId)) {
                continue;
            }
            if (!isset($recordingsbyid[$file->recordingId])) {
                $insertrecordings[] = $file;
                continue;
            }
            if (!empty($recordingsbyid[$file->recordingId]->deleted)) {
                $restorerecordings[] = $file;
            }
        }

        // No 'deleted' counter: a recording gone from Drive is trashed, never hard-deleted, here.
        $stats = [
            'inserted' => 0,
            'updated' => 0,
            'trashed' => 0,
            'restored' => 0,
        ];

        $updatednotes = 0;
        foreach ($googlemeetrecordings as $googlemeetrecording) {
            if (!isset($fileidsmap[$googlemeetrecording->recordingid])) {
                if (empty($googlemeetrecording->deleted)) {
                    // Active recording no longer in Drive: trashed below.
                    $trashrecordings[] = $googlemeetrecording->id;
                }
                continue;
            }

            if (!empty($googlemeetrecording->deleted)) {
                continue;
            }

            // Backfill notes for an active recording that just received them (Gemini notes are often
            // published after the recording first synced). Notes are the only field refreshed on
            // active rows; their Drive metadata is not updated.
            if (
                empty($googlemeetrecording->notestext)
                    && !empty($fileidsmap[$googlemeetrecording->recordingid])
            ) {
                $incoming = $filesbyid[$googlemeetrecording->recordingid] ?? null;
                if ($incoming && !empty($incoming->notestext)) {
                    $DB->update_record('googlemeet_recordings', (object)[
                        'id' => $googlemeetrecording->id,
                        'notestext' => $incoming->notestext,
                        'notesdocid' => $incoming->notesdocid ?? null,
                        'timemodified' => time(),
                    ]);
                    $updatednotes++;
                }
            }
        }
        $stats['updated'] = $updatednotes;

        if ($trashrecordings) {
            [$insql, $inparams] = $DB->get_in_or_equal($trashrecordings, SQL_PARAMS_NAMED);
            $now = time();
            $params = $inparams + [
                'deleted' => 1,
                'timedeleted' => $now,
                'trashreason' => \mod_googlemeet\local\recording_cleanup::TRASH_SYNC,
                'timemodified' => $now,
            ];
            $DB->execute("UPDATE {googlemeet_recordings}
                             SET deleted = :deleted,
                                 timedeleted = :timedeleted,
                                 trashreason = :trashreason,
                                 timemodified = :timemodified
                           WHERE id $insql", $params);
            $stats['trashed'] = count($trashrecordings);
        }

        if ($restorerecordings) {
            $restoredrecordingids = [];
            foreach ($restorerecordings as $restorerecording) {
                $existing = $recordingsbyid[$restorerecording->recordingId];
                $restoredrecordingids[] = (int)$existing->id;
                $update = (object)[
                    'id' => $existing->id,
                    'name' => $restorerecording->name,
                    'createdtime' => $restorerecording->createdTime,
                    'duration' => $restorerecording->duration,
                    'durationseconds' => recording_format::recording_duration_to_seconds($restorerecording->duration) ?: null,
                    'webviewlink' => $restorerecording->webViewLink,
                    'deleted' => 0,
                    'timedeleted' => 0,
                    'trashreason' => '',
                    'timemodified' => time(),
                ];
                if (empty($existing->notestext) && !empty($restorerecording->notestext)) {
                    $update->notestext = $restorerecording->notestext;
                    $update->notesdocid = $restorerecording->notesdocid ?? null;
                }
                $DB->update_record('googlemeet_recordings', $update);
            }
            $stats['restored'] = count($restorerecordings);
        } else {
            $restoredrecordingids = [];
        }

        $newrecordingids = [];
        if ($insertrecordings) {
            foreach ($insertrecordings as $insertrecording) {
                $recording = new stdClass();
                $recording->googlemeetid = $googlemeetid;
                $recording->recordingid = $insertrecording->recordingId;
                $recording->name = $insertrecording->name;
                $recording->createdtime = $insertrecording->createdTime;
                $recording->duration = $insertrecording->duration;
                $recording->durationseconds = recording_format::recording_duration_to_seconds($insertrecording->duration) ?: null;
                $recording->webviewlink = $insertrecording->webViewLink;
                $recording->deleted = 0;
                $recording->timedeleted = 0;
                $recording->timemodified = time();

                // Add transcript if available.
                if (!empty($insertrecording->transcripttext)) {
                    $recording->transcripttext = $insertrecording->transcripttext;
                    $recording->transcriptfileid = $insertrecording->transcriptfileid ?? null;
                }

                // Add notes if available.
                if (!empty($insertrecording->notestext)) {
                    $recording->notestext = $insertrecording->notestext;
                    $recording->notesdocid = $insertrecording->notesdocid ?? null;
                }

                $newrecordingids[] = $DB->insert_record('googlemeet_recordings', $recording);
            }
            $stats['inserted'] = count($insertrecordings);

            $aiautogenerate = !$deferenrichment && !empty(get_config('googlemeet', 'ai_autogenerate'));
            if ($aiautogenerate) {
                $aiservice = new \mod_googlemeet\ai_service();
                if ($aiservice->is_available()) {
                    foreach ($newrecordingids as $recordingid) {
                        $aiservice->queue_for_analysis($recordingid);
                    }
                }
            }

            if (!$deferenrichment && $DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeetid])) {
                $task = new \mod_googlemeet\task\notify_new_recordings();
                $task->set_custom_data([
                    'googlemeetid' => $googlemeetid,
                    'newcount' => $stats['inserted'],
                    'recordingids' => $newrecordingids,
                ]);
                \core\task\manager::queue_adhoc_task($task);
            }
        }

        if ($deferenrichment) {
            $enrichmentids = array_values(array_unique(array_merge($newrecordingids, $restoredrecordingids)));
            if (!empty($enrichmentids)) {
                $task = new \mod_googlemeet\task\process_recording_enrichment();
                $task->set_custom_data([
                    'googlemeetid' => $googlemeetid,
                    'recordingids' => $enrichmentids,
                    // Restored recordings get their empty fields refilled but must not retrigger AI
                    // analysis or "new recording" notifications (as without $deferenrichment, which
                    // queues those for inserts only).
                    'newrecordingids' => $newrecordingids,
                ]);
                \core\task\manager::queue_adhoc_task($task, true);
            }
        }

        // Update lastsync even when nothing changed.
        $DB->set_field('googlemeet', 'lastsync', time(), ['id' => $googlemeetid]);

        return [
            'recordings' => self::list_recordings(['googlemeetid' => $googlemeetid]),
            'stats' => $stats,
            'newrecordingids' => $newrecordingids,
            'restoredrecordingids' => $restoredrecordingids,
        ];
    }

    /**
     * Build a shorter human-readable recording name for presentation only.
     *
     * Google Drive Meet recordings commonly end with a generated date/time block and
     * the literal "Recording" marker. Keep the stored name intact and only strip the
     * generated suffixes when the remaining title is non-empty.
     *
     * @param string $name Stored Drive recording name.
     * @return string Display name.
     */
    public static function display_name(string $name): string {
        $original = $name;
        $display = trim($name);

        if ($display === '') {
            return $original;
        }

        // Only strip real file extensions: a generic .\w{2,10} would eat legitimate
        // endings like "Tema 3.10".
        $display = preg_replace('/\.(?:mp4|m4v|webm|mkv|mov|avi|pdf|docx?|xlsx?|pptx?|txt)\s*$/iu', '', $display);
        $display = preg_replace('/\s+-\s+Recording\s*$/iu', '', $display);
        $display = preg_replace(
            '/(?:^\s*-?\s*|\s+-\s+)\d{4}[\/-]\d{1,2}[\/-]\d{1,2}\s+\d{1,2}:\d{2}(?::\d{2})?' .
                '(?:\s+(?:[A-Z]{2,8}|(?:UTC|GMT)[+-]?\d{1,2}(?::?\d{2})?|[+-]\d{2}:?\d{2}))?\s*$/u',
            '',
            $display
        );

        $display = trim($display);

        return $display !== '' ? $display : $original;
    }

    /**
     * Choose a distinguishable lesson title for each recording (presentation only).
     *
     * Meet recordings are usually named after the meeting, so every lesson of an activity
     * ends up with the same title. When a recording's display name is "generic" (equal to
     * one of the activity names, or shared by several recordings of the activity) and the
     * AI analysis produced topics, the first topic becomes the title and the meeting name
     * moves to a subtitle. If two topic-based titles still collide, the second topic is
     * appended. Stored names are never changed (they drive the Drive sync filters).
     *
     * @param array $items Map id => ['name' => string, 'topics' => string[]].
     * @param string[] $genericnames Names that are generic for this activity (activity name, Meet name).
     * @return array Map id => ['title' => string, 'subtitle' => string, 'hassubtitle' => bool].
     */
    public static function assign_lesson_titles(array $items, array $genericnames): array {
        $generic = [];
        foreach ($genericnames as $genericname) {
            $genericname = trim((string)$genericname);
            if ($genericname !== '') {
                $generic[recording_format::fold(self::display_name($genericname))] = true;
            }
        }

        $displaynames = [];
        $namecounts = [];
        foreach ($items as $id => $item) {
            $display = self::display_name((string)($item['name'] ?? ''));
            $displaynames[$id] = $display;
            $key = recording_format::fold($display);
            $namecounts[$key] = ($namecounts[$key] ?? 0) + 1;
        }

        $result = [];
        $topictitles = [];
        foreach ($items as $id => $item) {
            $display = $displaynames[$id];
            $key = recording_format::fold($display);
            $topics = array_values(array_filter(array_map(static function ($topic) {
                return trim((string)$topic);
            }, (array)($item['topics'] ?? [])), static function ($topic) {
                return $topic !== '';
            }));
            $isgeneric = isset($generic[$key]) || $namecounts[$key] > 1;
            if (!$isgeneric || empty($topics)) {
                $result[$id] = ['title' => $display, 'subtitle' => '', 'hassubtitle' => false, 'topics' => $topics];
                continue;
            }
            $result[$id] = ['title' => $topics[0], 'subtitle' => $display, 'hassubtitle' => true, 'topics' => $topics];
            $topictitles[recording_format::fold($topics[0])][] = $id;
        }

        // Disambiguate lessons whose first topic is the same by adding their second topic.
        foreach ($topictitles as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $topics = $result[$id]['topics'];
                if (isset($topics[1])) {
                    $result[$id]['title'] = $topics[0] . ' · ' . $topics[1];
                }
            }
        }
        foreach ($result as $id => $entry) {
            unset($result[$id]['topics']);
        }

        return $result;
    }

    /**
     * Lesson titles for every recording of an activity, cached per request.
     *
     * @param stdClass $googlemeet Activity record (name, originalname, id).
     * @param bool $includehidden Whether hidden recordings take part (teacher view).
     * @return array Map recording id => ['title', 'subtitle', 'hassubtitle'].
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function get_lesson_titles(stdClass $googlemeet, bool $includehidden): array {
        global $DB;
        // Topics of an unreviewed analysis must not become a student-visible lesson title.
        $canseeunreviewed = !\mod_googlemeet\local\ai_review::is_required()
            || \mod_googlemeet\local\ai_review::can_see_unreviewed((int)$googlemeet->id);
        // Request cache (purged between PHPUnit tests, unlike a static array).
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'mod_googlemeet', 'lessontitles');
        $cachekey = (int)$googlemeet->id . '_' . (int)$includehidden . '_' . (int)$canseeunreviewed;
        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        $params = ['googlemeetid' => (int)$googlemeet->id, 'deleted' => 0];
        $visiblewhere = '';
        if (!$includehidden) {
            $visiblewhere = 'AND r.visible = 1';
        }
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.name, a.topics, a.reviewed
               FROM {googlemeet_recordings} r
          LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id AND a.status = 'completed'
              WHERE r.googlemeetid = :googlemeetid AND r.deleted = :deleted {$visiblewhere}",
            $params
        );
        $items = [];
        foreach ($rows as $row) {
            $topics = ($canseeunreviewed || !empty($row->reviewed)) ? (json_decode((string)$row->topics) ?: []) : [];
            $items[$row->id] = ['name' => (string)$row->name, 'topics' => $topics];
        }

        $titles = self::assign_lesson_titles(
            $items,
            [(string)($googlemeet->name ?? ''), (string)($googlemeet->originalname ?? '')]
        );
        $cache->set($cachekey, $titles);
        return $titles;
    }

    /**
     * Returns a recording the current user may open, or false.
     *
     * Trashed recordings, recordings of another activity and hidden recordings (for
     * users without editrecording) are all reported identically as "not available".
     *
     * @param object $googlemeet Activity record.
     * @param context_module $context Activity context.
     * @param int $recordingid Recording id.
     * @return object|false
     */
    public static function get_accessible_recording($googlemeet, $context, int $recordingid) {
        global $DB;

        $recording = $DB->get_record(
            'googlemeet_recordings',
            ['id' => $recordingid, 'googlemeetid' => $googlemeet->id, 'deleted' => 0]
        );
        if (!$recording) {
            return false;
        }
        if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
            return false;
        }
        return $recording;
    }

    /**
     * Mark the activity completed (if required) and trigger the course_module_viewed event.
     *
     * @param  stdClass $googlemeet googlemeet object
     * @param  stdClass $course     course object
     * @param  stdClass $cm         course module object
     * @param  stdClass $context    context object
     * @since Moodle 3.0
     * @return void
     * @throws \coding_exception
     */
    public static function view($googlemeet, $course, $cm, $context) {

        // Trigger course_module_viewed event.
        $params = [
            'context' => $context,
            'objectid' => $googlemeet->id,
        ];

        $event = \mod_googlemeet\event\course_module_viewed::create($params);
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('googlemeet', $googlemeet);
        $event->trigger();

        // Completion.
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm);
    }
}
