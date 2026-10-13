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

use mod_googlemeet\local\recording_format;
use mod_googlemeet\local\recording_search;
use mod_googlemeet\local\recordings;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Search coverage for the recordings list.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_format::class)]
#[CoversClass(recording_search::class)]
#[CoversClass(recordings::class)]
final class search_test extends \advanced_testcase {
    /**
     * Build a minimal recording stdClass for pure filter tests.
     *
     * @param string $name Recording name.
     * @param bool $hasai Whether completed AI content is present.
     * @param array $topics AI topics.
     * @param string $summary AI summary.
     * @return \stdClass
     */
    private function rec(string $name, bool $hasai = false, array $topics = [], string $summary = ''): \stdClass {
        $r = new \stdClass();
        $r->name = $name;
        $r->hasai = $hasai;
        $r->aitopics = $topics;
        $r->aisummary = $summary;
        return $r;
    }

    /**
     * Insert a minimal Google Meet activity record.
     *
     * @return \stdClass
     */
    private function create_googlemeet(): \stdClass {
        global $DB;

        $googlemeet = (object)[
            'course' => 1,
            'name' => 'Search room',
            'originalname' => 'Search room',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ];
        $googlemeet->id = (int)$DB->insert_record('googlemeet', $googlemeet);

        return $googlemeet;
    }

    /**
     * Insert a recording row.
     *
     * @param int $googlemeetid Activity instance id.
     * @param string $driveid Drive file id.
     * @param array $overrides Field overrides.
     * @return int Recording row id.
     */
    private function create_recording(int $googlemeetid, string $driveid, array $overrides = []): int {
        global $DB;

        $now = time();
        $record = (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => $driveid,
            'name' => 'Recording ' . $driveid,
            'createdtime' => $now - 100,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ], $overrides);

        return (int)$DB->insert_record('googlemeet_recordings', $record);
    }

    /**
     * Folding lowercases and strips Spanish accents, and leaves plain text alone.
     */
    public function test_fold_handles_spanish_accents_and_plain_text(): void {
        $this->assertSame('cardiologia nandu unguento arbol', recording_format::fold('CARDIOLOGÍA ÑANDÚ UNGÜENTO Árbol'));
        $this->assertSame('clase 123 sin cambios', recording_format::fold('clase 123 sin cambios'));
    }

    /**
     * A title match is accent-insensitive in both directions and carries no match hint.
     */
    public function test_filter_by_query_matches_names_with_and_without_accents(): void {
        $out = recording_search::filter_recordings_by_query(
            [$this->rec('Clase de Cardiología'), $this->rec('Clase de traumatología')],
            'cardiologia'
        );
        $this->assertCount(1, $out);
        $this->assertSame('Clase de Cardiología', $out[0]->name);
        $this->assertSame('', $out[0]->matchsource);

        $out = recording_search::filter_recordings_by_query(
            [$this->rec('Clase de cardiologia'), $this->rec('Clase de trauma')],
            'cardiología'
        );
        $this->assertCount(1, $out);
        $this->assertSame('Clase de cardiologia', $out[0]->name);
    }

    /**
     * A transcript-only match names its source and returns a snippet around the term.
     */
    public function test_filter_by_query_matches_transcript_only_with_source_and_snippet(): void {
        $recording = $this->rec('Clase sin pista');
        $recording->transcripttext = str_repeat('previo ', 20)
            . 'Hoy revisamos cardiología preventiva en detalle. '
            . str_repeat('despues ', 20);

        $out = recording_search::filter_recordings_by_query([$recording], 'cardiologia');

        $this->assertCount(1, $out);
        $this->assertSame(get_string('search_match_transcript', 'googlemeet'), $out[0]->matchsource);
        $this->assertStringContainsString('cardiología', $out[0]->matchsnippet);
    }

    /**
     * AI summary and topic matches name their source; a display-title match does not.
     */
    public function test_filter_by_query_reports_summary_and_topic_matches(): void {
        $summary = $this->rec('Clase 7', true, ['Derecho sanitario'], 'Repaso de la Ley General de Sanidad y sus competencias.');
        $topic = $this->rec('Clase 8', true, ['Ley de Transparencia'], 'Sin el termino.');
        $title = $this->rec('Clase 9');
        $title->displayname = 'Ley 39/2015';

        $out = recording_search::filter_recordings_by_query([$summary, $topic, $title, $this->rec('Otra')], 'ley', false);

        $this->assertCount(3, $out);
        $this->assertSame(get_string('search_match_summary', 'googlemeet'), $out[0]->matchsource);
        $this->assertStringContainsString('Ley General', $out[0]->matchsnippet);
        $this->assertSame(get_string('search_match_topics', 'googlemeet'), $out[1]->matchsource);
        $this->assertSame('Ley de Transparencia', $out[1]->matchsnippet);
        $this->assertSame('', $out[2]->matchsource, 'A display-title match needs no hint');
    }

    /**
     * A notes-only match names its source and returns a snippet without HTML tags.
     */
    public function test_filter_by_query_matches_notes_only_with_source_and_plain_snippet(): void {
        $recording = $this->rec('Clase sin pista');
        $recording->notestext = '<p>Resumen docente: la arritmía ventricular aparece en las notas.</p>';

        $out = recording_search::filter_recordings_by_query([$recording], 'arritmia');

        $this->assertCount(1, $out);
        $this->assertSame(get_string('search_match_notes', 'googlemeet'), $out[0]->matchsource);
        $this->assertStringContainsString('arritmía', $out[0]->matchsnippet);
        $this->assertStringNotContainsString('<p>', $out[0]->matchsnippet);
    }

    /**
     * A trashed recording's transcript cannot be found, because the listing drops it before the search content loads.
     */
    public function test_deleted_recording_transcript_is_not_searchable_after_listing(): void {
        $this->resetAfterTest();
        $googlemeet = $this->create_googlemeet();

        $this->create_recording($googlemeet->id, 'drive-active', [
            'name' => 'Visible class',
            'transcripttext' => 'Contenido visible sin el termino privado.',
        ]);
        $this->create_recording($googlemeet->id, 'drive-deleted', [
            'name' => 'Deleted class',
            'transcripttext' => 'La transcripción contiene palabraunica.',
            'deleted' => 1,
            'timedeleted' => time(),
        ]);

        $recordings = recordings::list_recordings(['googlemeetid' => $googlemeet->id], false);
        $recordings = recording_search::load_recording_search_content($recordings);
        $out = recording_search::filter_recordings_by_query($recordings, 'palabraunica');

        $this->assertCount(0, $out);
    }

    /**
     * The topic filter compares exact topics accent- and case-insensitively.
     */
    public function test_filter_by_topic_matches_accents(): void {
        $out = recording_search::filter_recordings_by_topic(
            [$this->rec('a', true, ['Cardiología']), $this->rec('b', true, ['Trauma'])],
            'cardiologia'
        );
        $this->assertCount(1, $out);
        $this->assertSame('a', $out[0]->name);

        $out = recording_search::filter_recordings_by_topic([$this->rec('a', true, ['cardiologia'])], 'Cardiología');
        $this->assertCount(1, $out);
    }

    /**
     * The base listing leaves out the large notes and transcript columns.
     */
    public function test_list_recordings_base_query_excludes_notes_and_transcript_fields(): void {
        $this->resetAfterTest();
        $googlemeet = $this->create_googlemeet();

        $this->create_recording($googlemeet->id, 'drive-heavy', [
            'notestext' => '<p>Notas internas con contenido buscable.</p>',
            'transcripttext' => 'Transcripción interna con contenido buscable.',
        ]);

        $recordings = recordings::list_recordings(['googlemeetid' => $googlemeet->id], false);

        $this->assertCount(1, $recordings);
        $this->assertFalse(property_exists($recordings[0], 'notestext'));
        $this->assertFalse(property_exists($recordings[0], 'transcripttext'));
    }
}
