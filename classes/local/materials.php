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

use context_module;
use moodle_url;

/**
 * Files attached to an activity: the materials of a recording, and the activity attachments saved
 * from the mod form and listed for students on the view page.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class materials {
    /**
     * Get downloadable material files for a recording.
     *
     * @param context_module $context Module context.
     * @param int $recordingid Recording ID.
     * @return array
     */
    public static function get_recording_materials(context_module $context, int $recordingid): array {
        return \mod_googlemeet\local\list_aggregates::materials_by_recording($context, [$recordingid])[$recordingid];
    }

    /**
     * Save the teacher attachment files submitted through the mod form draft area.
     *
     * Files are stored in the module context under the 'attachment' file area (itemid 0).
     *
     * @param object $googlemeet Instance object from the form (needs ->coursemodule and ->attachments).
     * @return void
     */
    public static function save_attachments($googlemeet): void {
        if (empty($googlemeet->coursemodule) || !isset($googlemeet->attachments)) {
            return;
        }
        $context = context_module::instance($googlemeet->coursemodule);
        file_save_draft_area_files(
            $googlemeet->attachments,
            $context->id,
            'mod_googlemeet',
            'attachment',
            0,
            ['subdirs' => 0]
        );
    }

    /**
     * Print the teacher-provided attachment files as a download list for students.
     *
     * Does nothing if the activity has no attachments.
     *
     * @param context_module $context Module context.
     * @return void
     */
    public static function print_attachments(context_module $context): void {
        global $OUTPUT;

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_googlemeet', 'attachment', 0, 'filename', false);
        if (!$files) {
            return;
        }

        $items = [];
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $filename = $file->get_filename();
            $url = moodle_url::make_pluginfile_url(
                $context->id,
                'mod_googlemeet',
                'attachment',
                0,
                $file->get_filepath(),
                $filename,
                true
            );
            $items[] = [
                'url' => $url->out(false),
                'icon' => $OUTPUT->pix_icon(file_file_icon($file), '', 'moodle', ['class' => 'me-2']),
                'filename' => $filename,
                'size' => display_size($file->get_filesize()),
            ];
        }

        echo $OUTPUT->render_from_template('mod_googlemeet/attachments', ['files' => $items]);
    }
}
