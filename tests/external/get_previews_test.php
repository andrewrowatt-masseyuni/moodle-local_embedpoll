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

namespace local_embedpoll\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

use core_external\external_api;
use local_embedpoll\poll;
use local_embedpoll\token;

/**
 * Tests for the editor's previews.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\external\get_previews
 * @covers     \local_embedpoll\output\widget
 */
final class get_previews_test extends \externallib_advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        poll::reset_cache();
    }

    /**
     * Call the function and clean its return value.
     *
     * @param \context $context The editor's context.
     * @param string[] $keys The keys.
     * @param int $sectionid The section, or 0.
     * @return array Keyed by key.
     */
    private function previews(\context $context, array $keys, int $sectionid = 0): array {
        $result = external_api::clean_returnvalue(
            get_previews::execute_returns(),
            get_previews::execute($context->id, $keys, $sectionid)
        );

        return array_column($result, 'html', 'key');
    }

    /**
     * Each poll previews as the page shows it to the same teacher, results and all, but disabled;
     * a key with no poll here says so.
     */
    public function test_previews(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_embedpoll');
        $row = $generator->create_poll(['cmid' => $page->cmid, 'options' => ['Uniquered', 'Uniquegreen']]);
        $generator->create_vote($row, (int)$this->getDataGenerator()->create_and_enrol($course, 'student')->id, 'Uniquered');
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $previews = $this->previews(\context_module::instance($page->cmid), [$row->embedkey, '0123456789abcdef', $row->embedkey]);

        $this->assertSame([$row->embedkey, '0123456789abcdef'], array_keys($previews));
        $this->assertStringContainsString('Uniquered', $previews[$row->embedkey]);
        $this->assertStringContainsString('1 vote', $previews[$row->embedkey]);
        $this->assertStringContainsString('disabled', $previews[$row->embedkey]);
        $this->assertStringNotContainsString(token::ATTRIBUTE, $previews[$row->embedkey]);
        $this->assertStringContainsString('embedpoll-notfound', $previews['0123456789abcdef']);
    }

    /**
     * Students do not get previews.
     */
    public function test_students_are_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $this->expectException(\required_capability_exception::class);
        $this->previews(\context_module::instance($page->cmid), []);
    }

    /**
     * Drafts preview on the "add an activity" form, for those who may manage polls only.
     */
    public function test_drafts_need_manage(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $draft = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll([
            'courseid' => $course->id,
            'options' => ['Uniquered', 'Uniquegreen'],
        ]);
        $context = \context_course::instance($course->id);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->assertStringContainsString('Uniquered', $this->previews($context, [$draft->embedkey])[$draft->embedkey]);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));
        $this->expectException(\required_capability_exception::class);
        $this->previews($context, [$draft->embedkey]);
    }

    /**
     * A section's poll previews in its section's editor, and is not a draft.
     */
    public function test_section_previews(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $section = get_fast_modinfo($course)->get_section_info(1);
        $row = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll([
            'sectionid' => $section->id,
            'options' => ['Uniquered', 'Uniquegreen'],
        ]);
        $context = \context_course::instance($course->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $previews = $this->previews($context, [$row->embedkey], (int)$section->id);
        $this->assertStringContainsString('Uniquered', $previews[$row->embedkey]);
    }
}
