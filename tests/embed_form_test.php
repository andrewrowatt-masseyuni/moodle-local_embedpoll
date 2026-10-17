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

namespace local_embedpoll;

use core_form\external\dynamic_form;
use local_embedpoll\form\embed_form;

/**
 * Tests for the poll modal form.
 *
 * Driven through core_form's own dynamic_form web service, which is the path the editor's modal
 * takes: it validates the context and checks access before the form is built.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\form\embed_form
 */
final class embed_form_test extends \advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        poll::reset_cache();
    }

    /**
     * Submit the form as the modal would.
     *
     * @param array $data The form fields.
     * @return array The web service result.
     */
    private function submit(array $data): array {
        // The mock adds the submission marker and puts the sesskey where confirm_sesskey() looks.
        return dynamic_form::execute(embed_form::class, $this->query(embed_form::mock_ajax_submit($data)));
    }

    /**
     * Encode form data the way the modal does.
     *
     * The separator is explicit because Moodle sets arg_separator.output to &amp;, which
     * parse_str() on the other side would read as part of the next key.
     *
     * @param array $data The form data.
     * @return string
     */
    private function query(array $data): string {
        return http_build_query($data, '', '&');
    }

    /**
     * Open the form without submitting it, as the modal does first.
     *
     * @param array $args The modal's arguments.
     * @return string The form HTML.
     */
    private function open(array $args): string {
        return dynamic_form::execute(embed_form::class, $this->query($args))['html'];
    }

    /**
     * A course with a book, and an editing teacher on it.
     *
     * @return array [course, book context]
     */
    private function setup_book(): array {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        return [$course, \context_module::instance($book->cmid)];
    }

    /**
     * Typing options, one per line, creates a poll.
     */
    public function test_creates_a_poll(): void {
        global $DB;

        $this->resetAfterTest();
        [, $context] = $this->setup_book();

        $result = $this->submit(['contextid' => $context->id, 'key' => '', 'options' => "Red\n\n  Green  \nBlue"]);

        $this->assertTrue($result['submitted']);
        $key = json_decode($result['data'])->key;
        $row = $DB->get_record('local_embedpoll', ['cmid' => $context->instanceid, 'embedkey' => $key], '*', MUST_EXIST);
        $this->assertSame(['Red', 'Green', 'Blue'], array_column(poll::options($row), 'label'));
        $this->assertSame(0, (int)$row->compact);
    }

    /**
     * "Compact" starts unticked; ticked, it saves the poll as compact, and the poll reopens with
     * it ticked.
     */
    public function test_compact(): void {
        global $DB;

        $this->resetAfterTest();
        [, $context] = $this->setup_book();
        $ticked = '~<input(?=[^>]*name="compact")(?=[^>]*type="checkbox")(?=[^>]*checked)[^>]*>~';

        $this->assertDoesNotMatchRegularExpression($ticked, $this->open(['contextid' => $context->id]));

        $result = $this->submit(['contextid' => $context->id, 'key' => '', 'options' => "Yes\nNo", 'compact' => 1]);

        $key = json_decode($result['data'])->key;
        $this->assertSame(1, (int)$DB->get_field('local_embedpoll', 'compact', ['embedkey' => $key]));
        $this->assertMatchesRegularExpression($ticked, $this->open(['contextid' => $context->id, 'key' => $key]));
    }

    /**
     * Options that do not make a poll are refused, and nothing is saved.
     */
    public function test_bad_options_are_refused(): void {
        global $DB;

        $this->resetAfterTest();
        [, $context] = $this->setup_book();

        foreach (["Only one", "Yes\nYES", ''] as $options) {
            $result = $this->submit(['contextid' => $context->id, 'key' => '', 'options' => $options]);
            $this->assertFalse($result['submitted'], $options);
        }
        $this->assertSame(0, $DB->count_records('local_embedpoll'));
    }

    /**
     * Editing a poll opens with its options, one per line, and saves under the same key.
     */
    public function test_edits_a_poll(): void {
        $this->resetAfterTest();
        [, $context] = $this->setup_book();
        $row = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll([
            'cmid' => $context->instanceid,
            'options' => ['Red', 'Green'],
        ]);

        $html = $this->open(['contextid' => $context->id, 'key' => $row->embedkey]);
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="options"[^>]*>\s*Red\RGreen</textarea>~', $html);
        // No votes yet, so nothing to warn about.
        $this->assertStringNotContainsString('embedpoll-votesnotice', $html);

        $result = $this->submit(['contextid' => $context->id, 'key' => $row->embedkey, 'options' => "Green\nRed\nBlue"]);

        $this->assertSame($row->embedkey, json_decode($result['data'])->key);
    }

    /**
     * A poll with votes opens with a warning about what editing does to them.
     */
    public function test_warns_about_votes(): void {
        $this->resetAfterTest();
        [$course, $context] = $this->setup_book();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_embedpoll');
        $row = $generator->create_poll(['cmid' => $context->instanceid]);
        $generator->create_vote($row, (int)$this->getDataGenerator()->create_and_enrol($course, 'student')->id, 'Red');
        $generator->create_vote($row, (int)$this->getDataGenerator()->create_and_enrol($course, 'student')->id, 'Blue');

        $html = $this->open(['contextid' => $context->id, 'key' => $row->embedkey]);

        $this->assertStringContainsString('embedpoll-votesnotice', $html);
        $this->assertStringContainsString('This poll has 2 votes.', $html);
    }

    /**
     * A non-editing teacher cannot open the form.
     */
    public function test_needs_manage(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $this->expectException(\required_capability_exception::class);
        $this->open(['contextid' => \context_module::instance($book->cmid)->id]);
    }

    /**
     * The form cannot be pointed at a context no poll can belong to.
     */
    public function test_refuses_other_contexts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        $this->open(['contextid' => \context_system::instance()->id]);
    }

    /**
     * From a section summary's editor, the form makes a poll for that section.
     */
    public function test_section_summary_poll(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $section = get_fast_modinfo($course)->get_section_info(1);
        $context = \context_course::instance($course->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $result = $this->submit([
            'contextid' => $context->id,
            'sectionid' => $section->id,
            'key' => '',
            'options' => "Yes\nNo",
        ]);

        $key = json_decode($result['data'])->key;
        $this->assertSame((int)$section->id, (int)$DB->get_field('local_embedpoll', 'sectionid', ['embedkey' => $key]));
    }
}
