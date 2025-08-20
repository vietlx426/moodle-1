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

namespace core_search;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/testable_core_search.php');
require_once(__DIR__ . '/fixtures/mock_search_area.php');

/**
 * Search engine base unit tests.
 *
 * @package     core_search
 * @category    phpunit
 * @copyright   2015 David Monllao {@link http://www.davidmonllao.com}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableglobalsearch', true);

        // Set \core_search::instance to the mock_search_engine as we don't require the search engine to be working to test this.
        $search = \testable_core_search::instance();
    }

    /**
     * Engine basic info.
     *
     * @return void
     */
    public function test_engine_info(): void {
        $engine = new \mock_search\engine();

        $this->assertEquals('mock_search', $engine->get_plugin_name());

        // Resolves to the default one.
        $this->assertEquals('\\core_search\\document', $engine->get_document_classname());
    }

    /**
     * Test engine caches.
     *
     * @return void
     */
    public function test_engine_caches(): void {
        global $DB;

        $engine = new \mock_search\engine();

        $course1 = self::getDataGenerator()->create_course();

        $this->assertEquals($course1->id, $engine->get_course($course1->id)->id);
        $dbreads = $DB->perf_get_reads();
        $engine->get_course($course1->id);
        $this->assertEquals($dbreads, $DB->perf_get_reads());
        $fakearea1 = \core_search\manager::generate_areaid('plugintype_unexisting', 'fakearea');
        $fakearea2 = \core_search\manager::generate_areaid('mod_unexisting', 'morefake');
        $this->assertFalse($engine->get_search_area($fakearea1));
        $this->assertFalse($engine->get_search_area($fakearea2));
        $this->assertFalse($engine->get_search_area($fakearea2));

        $areaid = \core_search\manager::generate_areaid('mod_forum', 'post');
        $this->assertInstanceOf('\\mod_forum\\search\\post', $engine->get_search_area($areaid));
        $dbreads = $DB->perf_get_reads();
        $this->assertInstanceOf('\\mod_forum\\search\\post', $engine->get_search_area($areaid));
        $this->assertEquals($dbreads, $DB->perf_get_reads());

    }

    /**
     * Tests the core functions related to schema updates.
     */
    public function test_engine_schema_modification(): void {
        // Apply a schema update starting from no version.
        $engine = new \mock_search\engine();
        $engine->check_latest_schema();
        $updates = $engine->get_and_clear_schema_updates();
        $this->assertCount(1, $updates);
        $this->assertEquals(0, $updates[0][0]);
        $this->assertEquals(\core_search\document::SCHEMA_VERSION, $updates[0][1]);

        // Store older version and check that.
        $engine->record_applied_schema_version(1066101400);

        $engine = new \mock_search\engine();
        $engine->check_latest_schema();
        $updates = $engine->get_and_clear_schema_updates();
        $this->assertCount(1, $updates);
        $this->assertEquals(1066101400, $updates[0][0]);
        $this->assertEquals(\core_search\document::SCHEMA_VERSION, $updates[0][1]);

        // Store current version and check no updates.
        $engine->record_applied_schema_version(\core_search\document::SCHEMA_VERSION);

        $engine = new \mock_search\engine();
        $engine->check_latest_schema();
        $updates = $engine->get_and_clear_schema_updates();
        $this->assertCount(0, $updates);
    }

    /**
     * Tests the get_supported_orders stub function.
     */
    public function test_get_supported_orders(): void {
        $engine = new \mock_search\engine();
        $orders = $engine->get_supported_orders(\context_system::instance());
        $this->assertCount(1, $orders);
        $this->assertArrayHasKey('relevance', $orders);
    }

    /**
     * Test that search engine sets an icon before render a document.
     */
    public function test_engine_sets_doc_icon(): void {
        $generator = self::getDataGenerator()->get_plugin_generator('core_search');
        $generator->setup();

        $area = new \core_mocksearch\search\mock_search_area();
        $engine = new \mock_search\engine();

        $record = $generator->create_record();
        $docdata = $area->get_document($record)->export_for_engine();

        $doc = $engine->to_document($area, $docdata);

        $this->assertNotNull($doc->get_doc_icon());

        $generator->teardown();
    }

    /**
     * Test get_index_stats default returns false for engines that do not support it.
     *
     * @covers \core_search\engine::get_index_stats
     */
    public function test_get_index_stats_default(): void {
        $engine = new \mock_search\engine();
        $this->assertFalse(
            $engine->get_index_stats(),
            'Base engine should return false from get_index_stats() by default'
        );
    }

    /**
     * Test get_threshold reads the indexsizelimit config key for the engine plugin.
     *
     * @covers \core_search\engine::get_threshold
     */
    public function test_get_threshold(): void {
        $engine = new \mock_search\engine();

        // No config set: should return 0.
        $this->assertSame(0, $engine->get_threshold());

        // Set a limit and verify it is returned.
        set_config('indexsizelimit', 1073741824, 'mock_search');
        $this->assertSame(1073741824, $engine->get_threshold());

        // Unset the config: should return 0 again.
        set_config('indexsizelimit', '', 'mock_search');
        $this->assertSame(0, $engine->get_threshold());
    }

    /**
     * Test is_over_threshold returns false when no stats are available or no limit set.
     *
     * @covers \core_search\engine::is_over_threshold
     */
    public function test_is_over_threshold_base_defaults(): void {
        $engine = new \mock_search\engine();

        // No threshold configured: always false.
        $this->assertFalse($engine->is_over_threshold());

        // Zero threshold: always false.
        $this->assertFalse($engine->is_over_threshold(0));

        // Negative threshold: always false.
        $this->assertFalse($engine->is_over_threshold(-1));

        // Positive threshold but engine has no stats: false.
        $this->assertFalse($engine->is_over_threshold(1000));
    }

    /**
     * Test is_approaching_threshold returns false when no stats are available or no limit set.
     *
     * @covers \core_search\engine::is_approaching_threshold
     */
    public function test_is_approaching_threshold_base_defaults(): void {
        $engine = new \mock_search\engine();

        // No threshold configured: always false.
        $this->assertFalse($engine->is_approaching_threshold());

        // Zero threshold: always false.
        $this->assertFalse($engine->is_approaching_threshold(0));

        // Negative threshold: always false.
        $this->assertFalse($engine->is_approaching_threshold(-1));

        // Positive threshold but engine has no stats: false.
        $this->assertFalse($engine->is_approaching_threshold(1000));
    }
}
