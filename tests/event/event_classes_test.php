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

namespace mod_googlemeet\event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each write event validates, triggers, describes itself and declares its restore mappings.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_updated::class)]
#[CoversClass(recording_trashed::class)]
#[CoversClass(recording_restored::class)]
#[CoversClass(recording_deleted::class)]
#[CoversClass(ai_analysis_updated::class)]
#[CoversClass(ai_analysis_reviewed::class)]
#[CoversClass(sync_requested::class)]
#[CoversClass(questions_changed::class)]
final class event_classes_test extends \advanced_testcase {
    /**
     * Event classes with a valid `other`, the object they point at, and an `other` validation must reject.
     *
     * @return array
     */
    public static function event_provider(): array {
        return [
            'recording_updated' => ['recording_updated', 'recording', ['field' => 'name'], ['field' => 'notes']],
            'recording_trashed' => ['recording_trashed', 'recording', ['bulk' => false], []],
            'recording_restored' => ['recording_restored', 'recording', [], null],
            'recording_deleted' => ['recording_deleted', 'recording', [], null],
            'ai_analysis_updated' => ['ai_analysis_updated', 'analysis', ['action' => 'saved'], ['action' => 'deleted']],
            'ai_analysis_reviewed' => ['ai_analysis_reviewed', 'analysis', ['all' => true], []],
            'sync_requested' => ['sync_requested', null, [], null],
            'questions_changed' => ['questions_changed', 'recording', ['action' => 'queued', 'count' => 5],
                ['action' => 'queued']],
        ];
    }

    /**
     * Create, trigger and read back one event in isolation.
     *
     * @param string $name Event class short name.
     * @param string|null $object 'recording', 'analysis' or null (no object).
     * @param array $other Valid other (recordingid is added for analysis events).
     * @param array|null $badother An other that validate_data() must reject, null when every other is valid.
     */
    #[DataProvider('event_provider')]
    public function test_event(string $name, ?string $object, array $other, ?array $badother): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $recording = $generator->create_recording(['googlemeetid' => $gm->id]);
        $analysis = $generator->create_ai_analysis(['recordingid' => $recording->id]);

        $class = '\\mod_googlemeet\\event\\' . $name;
        $data = ['context' => $context];
        if ($object === 'recording') {
            $data['objectid'] = $recording->id;
        } else if ($object === 'analysis') {
            $data['objectid'] = $analysis->id;
            $other['recordingid'] = (int)$recording->id;
            if ($badother !== null) {
                $badother['recordingid'] = (int)$recording->id;
            }
        }
        if ($other) {
            $data['other'] = $other;
        }

        $sink = $this->redirectEvents();
        $event = $class::create($data);
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf($class, $events[0]);
        $this->assertEquals($context, $event->get_context());
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertNotEmpty($class::get_name());
        $this->assertStringContainsString("'" . get_admin()->id . "'", $event->get_description());
        $this->assertStringContainsString("'{$cm->id}'", $event->get_description());
        $this->assertInstanceOf(\moodle_url::class, $event->get_url());
        $this->assertSame($object === null, $event->objecttable === null);

        // The restore mappings are declared by the plugin, not inherited from the base (which only warns).
        $this->assertNotSame(\core\event\base::class, (new \ReflectionMethod($class, 'get_objectid_mapping'))->class);
        $this->assertNotSame(\core\event\base::class, (new \ReflectionMethod($class, 'get_other_mapping'))->class);
        $this->assertNotFalse($class::get_objectid_mapping());
        $this->assertIsArray($class::get_other_mapping());
        $this->assertDebuggingNotCalled();

        if ($badother !== null) {
            $this->expectException(\coding_exception::class);
            $class::create(['other' => $badother] + $data);
        }
    }
}
