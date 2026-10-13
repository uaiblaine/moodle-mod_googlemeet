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
use core_text;
use mod_googlemeet\local\materials;
use mod_googlemeet\local\recording_format;
use mod_googlemeet\local\recording_media;
use mod_googlemeet\local\recording_progress;
use mod_googlemeet\local\recordings;
use mod_googlemeet\question_service;
use moodle_url;
use stdClass;

/**
 * The per-recording hub (mod_googlemeet/recording_hub): player, AI summary, key points, chapters,
 * questions, materials, the teacher-only transcript and notes, and the "continue" label.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_hub {
    /**
     * Print the per-recording hub.
     *
     * @param object $googlemeet Activity record.
     * @param object $cm Course-module record.
     * @param context_module $context Module context.
     * @param object $recording Recording record scoped by the caller.
     * @return void
     */
    public static function print_recording_hub($googlemeet, $cm, $context, $recording) {
        global $CFG, $DB, $OUTPUT, $PAGE, $USER;

        $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
        $canmanagequestions = has_capability('mod/googlemeet:managequestions', $context);
        $aiconfig = get_config('googlemeet');
        $aienabled = !empty($aiconfig->enableai) && !empty($aiconfig->geminiapikey);
        $questionservice = new question_service();
        $questions = $questionservice->get_questions($googlemeet, $cm, $context, $recording->id, !$canmanagequestions);
        $draftcount = 0;
        $publishedcount = 0;
        foreach ($questions as $question) {
            if ($question['isready']) {
                $publishedcount++;
            } else if ($question['isdraft']) {
                $draftcount++;
            }
        }
        if ($canmanagequestions && $questions) {
            // Teacher view: how students did on each question in the practice.
            $practicestats = \mod_googlemeet\local\practice_attempts::get_question_stats(
                (int)$googlemeet->id,
                (int)$recording->id
            );
            foreach ($questions as &$question) {
                $stat = $practicestats[(int)$question['id']] ?? null;
                $question['haspracticestats'] = !empty($stat['attempts']);
                $question['practicestatslabel'] = $question['haspracticestats']
                    ? get_string('question_practice_stats', 'googlemeet', (object)[
                        'pct' => $stat['correctpct'], 'attempts' => $stat['attempts'], 'users' => $stat['users']])
                    : '';
                $question['practicestatslow'] = $question['haspracticestats'] && $stat['correctpct'] < 50;
            }
            unset($question);
        }

        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id]);
        // An unreviewed analysis is hidden from students (teachers see it with a review banner).
        $aireview = \mod_googlemeet\local\ai_review::hub_context(
            $analysis,
            $context,
            (int)$cm->id,
            $aienabled && has_capability('mod/googlemeet:generateai', $context)
        );
        $analysiscompleted = $analysis && $analysis->status === 'completed' && $aireview['aicontentvisible'];
        $statusflags = recording_format::ai_status_flags($analysis->status ?? null);
        // Teacher-only tab: never render the transcript into a student's page.
        $hubtranscript = $caneditrecording ? self::hub_transcript($analysis, $recording) : '';
        $keypoints = [];
        $topics = [];
        $chapters = [];
        if ($analysiscompleted) {
            $keypoints = json_decode($analysis->keypoints) ?: [];
            $topics = json_decode($analysis->topics) ?: [];
            $chapters = recording_format::normalise_chapters($analysis->chapters ?? '');
        }

        $rpage = optional_param('rpage', 0, PARAM_INT);
        $rorder = optional_param('rorder', null, PARAM_ALPHA);
        $backparams = ['id' => $cm->id];
        if ($rpage > 0) {
            $backparams['rpage'] = $rpage;
        }
        if ($rorder) {
            $backparams['rorder'] = $rorder;
        }
        // The list's search and topic filter come back with the way back and the previous/next links.
        $backparams += \mod_googlemeet\local\recording_search::list_filter_params(
            optional_param('rq', '', PARAM_TEXT),
            optional_param('topic', '', PARAM_TEXT)
        );
        // Previous/next are chronological whatever the list order (students think in class dates).
        [$previousrecording, $nextrecording] = \mod_googlemeet\local\list_aggregates::hub_neighbours(
            $recording,
            !$caneditrecording
        );
        $previousparams = $backparams;
        $nextparams = $backparams;
        if ($previousrecording) {
            $previousparams['recording'] = $previousrecording->id;
        }
        if ($nextrecording) {
            $nextparams['recording'] = $nextrecording->id;
        }
        $activetab = optional_param('tab', '', PARAM_ALPHA);

        $materials = materials::get_recording_materials($context, $recording->id);
        $hasmaterials = !empty($materials);
        $showmaterials = $hasmaterials || $caneditrecording;

        $materialsactive = $showmaterials && $activetab === 'materials';
        $summaryactive = !$materialsactive;
        $progress = $DB->get_record(
            'googlemeet_recording_progress',
            ['recordingid' => $recording->id, 'userid' => $USER->id],
            'watchedseconds,completed,markedviewed'
        );
        $progressstate = recording_progress::recording_progress_state($recording, $progress ?: null);
        $canembed = recording_media::recording_can_embed((string)$recording->webviewlink);
        $embedurl = $canembed ? recording_media::get_recording_embed_url((string)$recording->webviewlink) : '';
        $durationseconds = recording_format::recording_duration_to_seconds((string)$recording->duration);
        // Deep link: view.php?id=..&recording=..&t=<seconds> opens the Drive player at that second.
        $starttime = max(0, optional_param('t', 0, PARAM_INT));
        if ($durationseconds > 0) {
            $starttime = min($starttime, $durationseconds);
        }
        $playerurl = $canembed ? recording_media::get_recording_seek_url((string)$recording->webviewlink, $starttime) : '';
        // Approximate "continue where you left off": the last chapter/timestamp this user jumped to,
        // not the real playback position (see recording_progress::lastjump_preference_name()).
        $lastjump = 0;
        if ($canembed && $starttime === 0 && isloggedin() && !isguestuser()) {
            $lastjump = max(0, (int)get_user_preferences(recording_progress::lastjump_preference_name((int)$recording->id), 0));
            if ($durationseconds > 0 && $lastjump > $durationseconds) {
                $lastjump = 0;
            }
        }

        $lessontitles = recordings::get_lesson_titles($googlemeet, $caneditrecording);
        $lessontitle = $lessontitles[$recording->id] ?? [
            'title' => recordings::display_name((string)$recording->name), 'subtitle' => '', 'hassubtitle' => false,
        ];
        // The page header already shows the activity name: do not repeat it as the lesson subtitle.
        if (
            !empty($lessontitle['hassubtitle'])
                && core_text::strtolower(trim(format_string($lessontitle['subtitle'])))
                    === core_text::strtolower(trim(format_string($googlemeet->name)))
        ) {
            $lessontitle['subtitle'] = '';
            $lessontitle['hassubtitle'] = false;
        }
        $navtitle = static function (?stdClass $navrecording) use ($lessontitles): string {
            if (!$navrecording) {
                return '';
            }
            return format_string(
                $lessontitles[$navrecording->id]['title'] ?? recordings::display_name((string)$navrecording->name)
            );
        };
        $navdate = static function (?stdClass $navrecording): string {
            return $navrecording ? userdate((int)$navrecording->createdtime, get_string('strftimedmy', 'googlemeet')) : '';
        };

        $templatecontext = array_merge([
            'cmid' => $cm->id,
            'recordingid' => $recording->id,
            'name' => format_string($lessontitle['title']),
            'hassubtitle' => $lessontitle['hassubtitle'],
            'subtitle' => format_string($lessontitle['subtitle']),
            'datelabel' => userdate((int)$recording->createdtime, get_string('strftimedaydate', 'langconfig')),
            'datetimeiso' => date('c', (int)$recording->createdtime),
            'previousrecordingtitle' => $navtitle($previousrecording),
            'previousrecordingdate' => $navdate($previousrecording),
            'nextrecordingtitle' => $navtitle($nextrecording),
            'nextrecordingdate' => $navdate($nextrecording),
            'originalname' => $recording->name,
            'duration' => s($recording->duration),
            'durationseconds' => $durationseconds,
            'starttime' => $canembed ? $starttime : 0,
            'playerurl' => $playerurl,
            'hasresume' => $lastjump > 0,
            'resumeseconds' => $lastjump,
            'resumelabel' => $lastjump > 0 ? self::hub_resume_label($lastjump, $chapters) : '',
            'huburl' => (new moodle_url(
                '/mod/googlemeet/view.php',
                ['id' => $cm->id, 'recording' => $recording->id]
            ))->out(false),
            'webviewlink' => $recording->webviewlink,
            'canembed' => $canembed,
            'embedurl' => $embedurl,
            'backurl' => (new moodle_url('/mod/googlemeet/view.php', $backparams))->out(false),
            'hasrecordingnav' => !empty($previousrecording) || !empty($nextrecording),
            'haspreviousrecording' => !empty($previousrecording),
            'previousrecordingurl' => $previousrecording
                ? (new moodle_url('/mod/googlemeet/view.php', $previousparams))->out(false)
                : '',
            'hasnextrecording' => !empty($nextrecording),
            'nextrecordingurl' => $nextrecording
                ? (new moodle_url('/mod/googlemeet/view.php', $nextparams))->out(false)
                : '',
            'caneditrecording' => $caneditrecording,
            'canmanagequestions' => $canmanagequestions,
            'visibilitybuttonlabel' => get_string(
                !empty($recording->visible) ? 'recording_hide_from_students_button' : 'recording_show_to_students_button',
                'googlemeet'
            ),
            'aienabled' => $aienabled,
            'sesskey' => sesskey(),
            'hasanalysis' => $analysiscompleted,
            'aistatusisprocessing' => $statusflags['aistatusisprocessing'],
            'aistatusispending' => $statusflags['aistatusispending'],
            'aistatusisfailed' => $statusflags['aistatusisfailed'],
            'aierror' => ($caneditrecording && $analysis && !empty($analysis->error)) ? s($analysis->error) : '',
            'summary' => $analysiscompleted ? implode('', array_map(static function (string $paragraph) use ($context) {
                return '<p>' . format_text($paragraph, FORMAT_PLAIN, ['context' => $context, 'para' => false]) . '</p>';
            }, recording_format::summary_paragraphs((string)$analysis->summary))) : '',
            'keypoints' => array_map(static function ($point, $index) {
                return ['text' => (string)$point, 'number' => $index + 1];
            }, array_values($keypoints), array_keys(array_values($keypoints))),
            'keypointcount' => count($keypoints),
            'keypointshash' => recording_progress::keypoints_hash($keypoints),
            'keypointsstate' => (isloggedin() && !isguestuser())
                ? recording_progress::keypoints_state(
                    (string)get_user_preferences(recording_progress::keypoints_preference_name((int)$recording->id), ''),
                    recording_progress::keypoints_hash($keypoints),
                    count($keypoints)
                )
                : '',
            'topics' => array_map(static function ($topic) use ($cm) {
                return [
                    'text' => (string)$topic,
                    'url' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'topic' => (string)$topic]))->out(false),
                ];
            }, $topics),
            'chapters' => $chapters,
            'haschapters' => !empty($chapters),
            'chaptercount' => count($chapters),
            'transcript' => $hubtranscript !== '' ? format_text($hubtranscript, FORMAT_PLAIN, ['context' => $context]) : '',
            'hastranscript' => $hubtranscript !== '',
            'hasnotes' => !empty($recording->notestext),
            'notes' => !empty($recording->notestext)
                ? format_text($recording->notestext, FORMAT_HTML, ['context' => $context])
                : '',
            'questions' => $questions,
            'hasquestions' => !empty($questions),
            // Students only get the Questions tab when there is something to practise (published questions).
            'showquestionstab' => $canmanagequestions || !empty($questions),
            'draftcount' => $draftcount,
            'publishedcount' => $publishedcount,
            'questioncount' => count($questions),
            'hasdrafts' => $draftcount > 0,
            'activitydraftcount' => $canmanagequestions
                ? $questionservice->count_activity_drafts($googlemeet, $cm, $context) : 0,
            'generationqueued' => $questionservice->is_generation_queued($recording->id),
            // Generating questions is a paid Gemini call: the button follows the web service's gate.
            'cangeneratequestions' => $aienabled && $canmanagequestions
                && has_capability('mod/googlemeet:generateai', $context),
            'summaryactive' => $summaryactive,
            // The Questions tab is never initially active (Summary is, or Materials with tab=materials).
            'questionsactive' => false,
            'showmaterials' => $showmaterials,
            'materialsactive' => $materialsactive,
            'materials' => $materials,
            'hasmaterials' => $hasmaterials,
            'materialcount' => count($materials),
            'managematerialsurl' => (new moodle_url(
                '/mod/googlemeet/material.php',
                ['id' => $cm->id, 'recording' => $recording->id]
            ))->out(false),
        ], $progressstate, $aireview);

        echo $OUTPUT->render_from_template('mod_googlemeet/recording_hub', $templatecontext);
    }

    /**
     * Transcript shown in the teacher-only hub tab.
     *
     * Prefers the transcript of a completed AI analysis and falls back to the recording's own (Meet or
     * subtitle) transcript when the analysis stored none.
     *
     * @param stdClass|false|null $analysis googlemeet_ai_analysis row.
     * @param stdClass $recording googlemeet_recordings row.
     * @return string Plain-text transcript ('' when none).
     */
    public static function hub_transcript($analysis, $recording): string {
        $transcript = ($analysis && ($analysis->status ?? '') === 'completed') ? (string)($analysis->transcript ?? '') : '';
        if (trim($transcript) === '') {
            $transcript = (string)($recording->transcripttext ?? '');
        }
        return trim($transcript) === '' ? '' : $transcript;
    }

    /**
     * Student-facing label for the hub "continue" button.
     *
     * "Continue with chapter «X» (14:02)" when the remembered second is a chapter start, else "Continue at 14:02".
     *
     * @param int $seconds Remembered offset (last jump), > 0.
     * @param array $chapters Normalised chapters (recording_format::normalise_chapters()).
     * @return string
     */
    public static function hub_resume_label(int $seconds, array $chapters): string {
        $time = recording_format::format_seconds_timestamp($seconds);
        foreach ($chapters as $chapter) {
            if (abs((int)$chapter['startseconds'] - $seconds) <= 2) {
                return get_string('hub_resume_chapter', 'googlemeet', (object)[
                    'title' => format_string($chapter['title']),
                    'time' => $time,
                ]);
            }
        }
        return get_string('hub_resume_time', 'googlemeet', $time);
    }
}
