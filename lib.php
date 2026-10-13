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
 * Library of interface functions and constants.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\client;
use mod_googlemeet\local\creator;
use mod_googlemeet\local\file_serving;
use mod_googlemeet\local\schedule;

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return mixed True or false, the archetype or purpose value, or null when the feature is unknown.
 */
function googlemeet_supports($feature) {
    switch ($feature) {
        case FEATURE_USES_QUESTIONS:
            // The AI practice questions live in a private question bank in the module context, as in
            // mod_quiz: FEATURE_USES_QUESTIONS without FEATURE_PUBLISHES_QUESTIONS. Core then creates the
            // module's default question category on add and counts it among the activities with private questions.
            return true;
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COMMUNICATION;
        default:
            return null;
    }
}

/**
 * Serve recording material files.
 *
 * @package  mod_googlemeet
 * @category files
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context Context object.
 * @param string $filearea File area.
 * @param array $args Extra path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional file serving options.
 * @return bool False if file is not found or access is denied.
 */
function googlemeet_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);
    if (!has_capability('mod/googlemeet:view', $context)) {
        return false;
    }

    // Teacher attachments (files for students to download). Itemid is always 0.
    if ($filearea === 'attachment') {
        $fs = get_file_storage();
        $relativepath = implode('/', $args);
        $fullpath = "/$context->id/mod_googlemeet/attachment/$relativepath";
        if (!($file = $fs->get_file_by_hash(sha1($fullpath))) || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 86400, 0, file_serving::force_download($file, (bool)$forcedownload), $options);
        return true;
    }

    if ($filearea !== 'recordingmaterial') {
        return false;
    }

    $recordingid = (int)array_shift($args);
    if ($recordingid <= 0) {
        return false;
    }

    $recording = $DB->get_record(
        'googlemeet_recordings',
        ['id' => $recordingid, 'googlemeetid' => $cm->instance, 'deleted' => 0],
        'id, visible'
    );
    if (!$recording) {
        return false;
    }

    if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_googlemeet/recordingmaterial/$recordingid/$relativepath";
    if (!($file = $fs->get_file_by_hash(sha1($fullpath))) || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, file_serving::force_download($file, (bool)$forcedownload), $options);
}

/**
 * Saves a new instance of the mod_googlemeet into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $googlemeet An object from the form.
 * @param mod_googlemeet_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function googlemeet_add_instance($googlemeet, $mform = null) {
    global $DB, $CFG, $USER;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    // The creator is derived below from who linked or owns the organiser e-mail, never taken from the data.
    unset($googlemeet->creatoruserid);

    $client = new client();

    // Not logged in to Google: keep the Meet URL typed in the form, normalised.
    if (!$client->check_login()) {
        $url = googlemeet_clear_url($googlemeet->url);
        if ($url) {
            $googlemeet->url = $url;
        }
    } else {
        try {
            $calendarevent = $client->create_meeting_event($googlemeet);
            $googlemeet->url = $calendarevent->hangoutLink;

            $link = new moodle_url($calendarevent->htmlLink);
            $googlemeet->eventid = $link->get_param('eid');
            $googlemeet->originalname = $calendarevent->summary;
            $googlemeet->creatoremail = $calendarevent->creator->email;
            // The event was created with this user's Google token, so they own the organiser account.
            $googlemeet->creatoruserid = (int)$USER->id;
        } catch (\Throwable $e) {
            debugging('mod_googlemeet: Google Calendar event creation failed during add_instance: ' .
                $e->getMessage(), DEBUG_DEVELOPER);
            \core\notification::warning(get_string('googleeventcreationfailed', 'googlemeet'));
            if (!isset($googlemeet->url)) {
                $googlemeet->url = '';
            }
        }
    }

    // Without a Calendar event the organiser e-mail was typed. It names the user whose Google token
    // background tasks will use, so it must be the editor's own account (an administrator may name
    // anyone, and then the single active owner is recorded). Restore and the upgrade do not come here.
    if (!isset($googlemeet->creatoruserid)) {
        $typed = (string)($googlemeet->creatoremail ?? '');
        if (creator::is_editors_own_email($typed, creator::linked_email())) {
            $googlemeet->creatoruserid = (int)$USER->id;
        } else if (creator::may_name_email($typed)) {
            $googlemeet->creatoruserid = creator::userid_for_email($typed, '', 0);
        } else {
            throw new \moodle_exception('creatoremail_notyours', 'googlemeet');
        }
    }

    // Only a created Calendar event sets originalname; without one (no Google login, or the
    // creation failed) fall back to the activity name rather than the column default ''.
    if (empty($googlemeet->originalname)) {
        $googlemeet->originalname = $googlemeet->name;
    }

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_encode($googlemeet->days);
    }

    $googlemeet->timemodified = time();

    if (!$googlemeet->id = $DB->insert_record('googlemeet', $googlemeet)) {
        return false;
    }

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_decode($googlemeet->days, true);
    }

    // Save holiday periods.
    schedule::save_holidays($googlemeet);

    // Save cancelled dates.
    schedule::save_cancelled($googlemeet);

    $events = schedule::construct_events_data_for_add($googlemeet);

    schedule::set_events($googlemeet, $events);

    // Save teacher attachments (files for students to download).
    googlemeet_save_attachments($googlemeet);

    return $googlemeet->id;
}

/**
 * Updates an instance of the mod_googlemeet in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $googlemeet An object from the form in mod_form.php.
 * @param mod_googlemeet_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function googlemeet_update_instance($googlemeet, $mform = null) {
    global $DB, $CFG, $USER;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $googlemeet->id = $googlemeet->instance;
    // The stored creator stays unless the organiser e-mail changes (below); the data never names it.
    unset($googlemeet->creatoruserid);

    if (isset($googlemeet->addmultiply)) {
        if (isset($googlemeet->days)) {
            $googlemeet->days = json_encode($googlemeet->days);
        }
    } else {
        $googlemeet->addmultiply = 0;
        $googlemeet->days = null;
        $googlemeet->eventenddate = $googlemeet->eventdate;
        $googlemeet->period = null;
    }

    if (isset($googlemeet->url)) {
        $url = googlemeet_clear_url($googlemeet->url);
        if ($url) {
            $googlemeet->url = $url;
        }
    }

    $googlemeet->timemodified = time();

    // Snapshot what the Google Calendar event mirrors before overwriting it.
    $calendarbefore = $DB->get_record('googlemeet', ['id' => $googlemeet->id]);

    // A new organiser e-mail names a new creator: the editor when it is their linked Google account
    // or their own Moodle e-mail, else its single active owner, else nobody yet. The rule of mod_form::can_change_creatoremail()
    // is enforced here too, so a caller that skips the form cannot bind someone else's account.
    if (
        $calendarbefore && isset($googlemeet->creatoremail)
            && !creator::same_email((string)$googlemeet->creatoremail, (string)$calendarbefore->creatoremail)
    ) {
        if (!creator::may_change_to_email((string)$googlemeet->creatoremail)) {
            throw new \moodle_exception('creatoremail_notyours', 'googlemeet');
        }
        $googlemeet->creatoruserid = creator::userid_for_email(
            (string)$googlemeet->creatoremail,
            creator::linked_email(),
            (int)$USER->id
        );
    }

    $googlemeetupdated = $DB->update_record('googlemeet', $googlemeet);

    // Push name/date/time/recurrence changes to Google Calendar in the background.
    // Never let Google (or the queue) block saving the form.
    try {
        if ($calendarbefore && \mod_googlemeet\local\calendar_sync::needs_patch($calendarbefore, $googlemeet)) {
            \mod_googlemeet\local\calendar_sync::queue_update($calendarbefore, (int)$GLOBALS['USER']->id);
        }
    } catch (\Throwable $e) {
        debugging('mod_googlemeet: could not queue the Google Calendar update: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Save holiday periods.
    schedule::save_holidays($googlemeet);

    // Save cancelled dates.
    schedule::save_cancelled($googlemeet);

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_decode($googlemeet->days);
    }
    $events = schedule::construct_events_data_for_add($googlemeet);

    // Merge instead of wipe-and-recreate, see schedule::merge_events().
    schedule::merge_events($googlemeet, $events);

    // Save teacher attachments (files for students to download).
    googlemeet_save_attachments($googlemeet);

    return $googlemeetupdated;
}

/**
 * Removes an instance of the mod_googlemeet from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function googlemeet_delete_instance($id) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $googlemeetrecord = $DB->get_record('googlemeet', ['id' => $id]);
    if (!$googlemeetrecord) {
        return false;
    }

    // Delete the Google Calendar event in the background (data captured now, the record is
    // gone when the task runs). Never block the deletion.
    try {
        \mod_googlemeet\local\calendar_sync::queue_delete($googlemeetrecord, (int)($GLOBALS['USER']->id ?? 0));
    } catch (\Throwable $e) {
        debugging('mod_googlemeet: could not queue the Google Calendar deletion: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    $DB->delete_records('googlemeet_sync_log', ['googlemeetid' => $id]);

    schedule::delete_events($id);

    // Recordings and every dependent (AI, progress, attempts, files, preferences). Core deletes the
    // module question bank right after this callback, so questions are left to it.
    $cm = get_coursemodule_from_instance('googlemeet', $id, 0, false, IGNORE_MISSING);
    $context = $cm ? context_module::instance($cm->id, IGNORE_MISSING) : false;
    \mod_googlemeet\local\recording_cleanup::purge_instance_recordings((int)$id, $context ?: null, false);

    $DB->delete_records('googlemeet_recording_subs', ['googlemeetid' => $id]);
    $DB->delete_records('googlemeet_practice_attempts', ['googlemeetid' => $id]);
    \mod_googlemeet\local\attendance\service::delete_for_activity((int)$id);
    $DB->delete_records('googlemeet_holidays', ['googlemeetid' => $id]);
    $DB->delete_records('googlemeet_cancelled', ['googlemeetid' => $id]);

    $DB->delete_records('googlemeet', ['id' => $id]);

    return true;
}

/**
 * Course module info cached in modinfo: the name, the custom completion rules and, with
 * showdescription, the formatted intro.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info|null Null when the instance record is missing.
 */
function googlemeet_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    if (
        !$googlemeet = $DB->get_record(
            'googlemeet',
            ['id' => $coursemodule->instance],
            'id, name, url, intro, introformat, completionrecordings, completionwatchpercent, completionpractice'
        )
    ) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $googlemeet->name;

    // Custom completion rules, only when completion is automatic.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules'] = [
            'completionrecordings' => (int)$googlemeet->completionrecordings,
            'completionwatchpercent' => (int)$googlemeet->completionwatchpercent,
            'completionpractice' => (int)$googlemeet->completionpractice,
        ];
    }

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $info->content = format_module_intro('googlemeet', $googlemeet, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Human-readable descriptions of the active custom completion rules (bulk completion editing).
 *
 * @param cm_info|stdClass $cm Object with ->completion and ->customdata['customcompletionrules'].
 * @return string[]
 */
function mod_googlemeet_get_completion_active_rule_descriptions($cm) {
    if (empty($cm->customdata['customcompletionrules']) || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }

    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $val) {
        if (empty($val)) {
            continue;
        }
        switch ($key) {
            case 'completionrecordings':
                $descriptions[] = get_string('completiondetail:recordings', 'googlemeet', (int)$val);
                break;
            case 'completionwatchpercent':
                $descriptions[] = get_string('completiondetail:watchpercent', 'googlemeet', (int)$val);
                break;
            case 'completionpractice':
                $descriptions[] = get_string('completiondetail:practice', 'googlemeet', (int)$val);
                break;
        }
    }
    return $descriptions;
}

/**
 * Provide the Timeline action for Google Meet calendar events.
 *
 * @param calendar_event $event Calendar event.
 * @param \core_calendar\action_factory $factory Action factory.
 * @param int $userid User id to use for visibility checks.
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_googlemeet_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    int $userid = 0
) {
    global $USER;

    if ($event->eventtype !== \mod_googlemeet\helper::GOOGLEMEET_EVENT_START) {
        return null;
    }

    if (empty($userid)) {
        $userid = $USER->id;
    }

    $modinfo = get_fast_modinfo($event->courseid, $userid);
    if (empty($modinfo->instances['googlemeet'][$event->instance])) {
        return null;
    }

    $cm = $modinfo->instances['googlemeet'][$event->instance];
    if (!$cm->uservisible) {
        return null;
    }

    $start = (int)$event->timestart;
    $end = $start + (int)$event->timeduration;
    $now = time();
    $actionable = $now >= ($start - (30 * MINSECS)) && $now <= $end;

    return $factory->create_instance(
        get_string('calendar_action_enterroom', 'googlemeet'),
        new \moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]),
        1,
        $actionable
    );
}

/**
 * User preferences that the current user may update through core_user_set_user_preferences.
 *
 * The patterns match the names built by {@see \mod_googlemeet\local\recording_progress::lastjump_preference_name()}
 * and {@see \mod_googlemeet\local\recording_progress::keypoints_preference_name()}; keep them in step.
 *
 * @return array
 */
function googlemeet_user_preferences(): array {
    return [
        '/^mod_googlemeet_lastjump_\\d+$/' => [
            'isregex' => true,
            'type' => PARAM_INT,
            'null' => NULL_NOT_ALLOWED,
            'default' => 0,
            'permissioncallback' => [core_user::class, 'is_current_user'],
            'cleancallback' => static function ($value) {
                return max(0, (int)$value);
            },
        ],
        '/^mod_googlemeet_kp_\\d+$/' => [
            'isregex' => true,
            'type' => PARAM_RAW_TRIMMED,
            'null' => NULL_NOT_ALLOWED,
            'default' => '',
            'permissioncallback' => [core_user::class, 'is_current_user'],
            'cleancallback' => static function ($value) {
                $value = (string)$value;
                if ($value !== '' && !preg_match('/^[0-9a-f]{8}:[01]{1,200}$/', $value)) {
                    throw new invalid_parameter_exception('Invalid key points state');
                }
                return $value;
            },
        ],
    ];
}

/**
 * Get icon mapping for font-awesome.
 *
 * @return array Icon id => Font Awesome class.
 */
function mod_googlemeet_get_fontawesome_icon_map() {
    return [
        'mod_googlemeet:logout' => 'fa-sign-out',
        'mod_googlemeet:play' => 'fa-play',
    ];
}

// Secondary navigation entries.

/**
 * Add the practice and report pages to the activity (secondary) navigation.
 *
 * @param settings_navigation $settingsnav Settings navigation.
 * @param navigation_node $googlemeetnode The activity node.
 * @return void
 */
function googlemeet_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $googlemeetnode): void {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);

    if (has_capability('mod/googlemeet:view', $context) && !has_capability('mod/googlemeet:managequestions', $context)) {
        $googlemeetnode->add(
            get_string('practice_page_title', 'googlemeet'),
            new moodle_url('/mod/googlemeet/practice.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_googlemeet_practice',
            new pix_icon('i/questions', '')
        );
    }
    if (has_capability('mod/googlemeet:viewreports', $context)) {
        $googlemeetnode->add(
            get_string('report_title', 'googlemeet'),
            new moodle_url('/mod/googlemeet/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_googlemeet_report',
            new pix_icon('i/report', '')
        );
    }
    // Attendance read from Google Meet: shown when the site enables it; attendance.php explains a
    // missing activity opt-in.
    if (has_capability('mod/googlemeet:viewreports', $context) && \mod_googlemeet\local\attendance\scope::site_enabled()) {
        $googlemeetnode->add(
            get_string('attendance_title', 'googlemeet'),
            new moodle_url('/mod/googlemeet/attendance.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_googlemeet_attendance',
            new pix_icon('i/users', '')
        );
    }
}

/**
 * Course reset form elements.
 *
 * @param MoodleQuickForm $mform Reset form.
 * @return void
 */
function googlemeet_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'googlemeetheader', get_string('modulenameplural', 'googlemeet'));
    $mform->addElement('advcheckbox', 'reset_googlemeet_userdata', get_string('reset_userdata', 'googlemeet'));
    $mform->addHelpButton('reset_googlemeet_userdata', 'reset_userdata', 'googlemeet');
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course Course.
 * @return array
 */
function googlemeet_reset_course_form_defaults($course) {
    return ['reset_googlemeet_userdata' => 1];
}

/**
 * Course reset: delete the students' data of every Google Meet activity of the course.
 *
 * Recordings, AI analysis and practice questions are course content and are kept.
 *
 * @param stdClass $data Reset form data.
 * @return array Status rows.
 */
function googlemeet_reset_userdata($data) {
    global $DB;

    $status = [];
    if (empty($data->reset_googlemeet_userdata)) {
        return $status;
    }
    $ids = $DB->get_fieldset_select('googlemeet', 'id', 'course = ?', [$data->courseid]);
    \mod_googlemeet\local\recording_cleanup::delete_user_data($ids);
    $status[] = [
        'component' => get_string('modulenameplural', 'googlemeet'),
        'item' => get_string('reset_userdata', 'googlemeet'),
        'error' => false,
    ];
    return $status;
}
