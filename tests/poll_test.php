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

/**
 * Tests for a poll's options, votes and results.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\poll
 */
final class poll_test extends \advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        poll::reset_cache();
    }

    /**
     * A page with a poll, and a student enrolled in its course.
     *
     * @param string[] $options The poll's options.
     * @return array [course, row, student]
     */
    private function make_poll(array $options = ['Red', 'Green', 'Blue']): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $row = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll([
            'cmid' => $page->cmid,
            'options' => $options,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        return [$course, $row, $student];
    }

    /**
     * The id of an option, by its label.
     *
     * @param \stdClass $row The poll.
     * @param string $label The label.
     * @return int
     */
    private function optionid(\stdClass $row, string $label): int {
        return array_column(poll::options($row), 'id', 'label')[$label];
    }

    /**
     * The plugin is installed.
     */
    public function test_plugin_installed(): void {
        $this->assertNotEmpty(get_config('local_embedpoll', 'version'));
    }

    /**
     * Lines become labels: trimmed, blank lines dropped, whatever the line endings.
     */
    public function test_labels_from_text(): void {
        $this->assertSame(['Red', 'Green', 'Blue'], poll::labels_from_text("  Red \r\n\nGreen\n   \nBlue\r"));
        $this->assertSame([], poll::labels_from_text(" \n "));
    }

    /**
     * New options are numbered from 1.
     */
    public function test_merge_into_nothing(): void {
        [$options, $gone] = poll::merge_options([], ['Red', 'Green']);

        $this->assertSame([['id' => 1, 'label' => 'Red'], ['id' => 2, 'label' => 'Green']], $options);
        $this->assertSame([], $gone);
    }

    /**
     * An option keeps its id while its text is unchanged, wherever it moves; a new or reworded
     * option gets an id no option has had; and the ids of options that are gone are reported.
     */
    public function test_merge_keeps_ids_by_text(): void {
        $existing = [['id' => 1, 'label' => 'Red'], ['id' => 2, 'label' => 'Green'], ['id' => 3, 'label' => 'Blue']];

        [$options, $gone] = poll::merge_options($existing, ['Blue', 'Purple', 'Red']);

        $this->assertSame([
            ['id' => 3, 'label' => 'Blue'],
            ['id' => 4, 'label' => 'Purple'],
            ['id' => 1, 'label' => 'Red'],
        ], $options);
        $this->assertSame([2], $gone);
    }

    /**
     * Stored options that are not options are ignored rather than trusted.
     */
    public function test_options_ignores_bad_json(): void {
        $this->assertSame([], poll::options((object)['options' => 'not json']));
        $this->assertSame(
            [['id' => 2, 'label' => 'Ok']],
            poll::options((object)['options' => '[{"id":0,"label":"No id"},{"label":"x"},{"id":2,"label":"Ok"}]'])
        );
    }

    /**
     * Results are not sent until the student votes, and then they are.
     */
    public function test_results_hidden_until_student_votes(): void {
        global $DB;

        $this->resetAfterTest();
        [, $row, $student] = $this->make_poll();

        $before = poll::export_for_template($row, (int)$student->id);
        $this->assertTrue($before['canvote']);
        $this->assertFalse($before['canviewresults']);
        $this->assertFalse($before['showresults']);
        $this->assertSame(0, $before['total']);

        poll::record_vote($row, (int)$student->id, $this->optionid($row, 'Green'));

        $after = poll::export_for_template($row, (int)$student->id);
        $this->assertTrue($after['showresults']);
        $this->assertSame(1, $after['total']);
        $this->assertSame('1 vote', $after['totaltext']);
        $this->assertTrue($after['items'][1]['selected']);
        $this->assertSame(100, $after['items'][1]['percent']);
        $this->assertSame(1, $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id]));
    }

    /**
     * A second vote replaces the first.
     */
    public function test_changing_vote_replaces_the_previous_one(): void {
        global $DB;

        $this->resetAfterTest();
        [, $row, $student] = $this->make_poll();

        poll::record_vote($row, (int)$student->id, $this->optionid($row, 'Red'));
        poll::record_vote($row, (int)$student->id, $this->optionid($row, 'Blue'));

        $this->assertSame(1, $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id, 'userid' => $student->id]));
        $data = poll::export_for_template($row, (int)$student->id);
        $this->assertTrue($data['items'][2]['selected']);
        $this->assertFalse($data['items'][0]['selected']);
        $this->assertSame(1, $data['total']);
    }

    /**
     * Teachers see results without voting, and cannot vote.
     */
    public function test_teacher_always_sees_results_without_voting(): void {
        $this->resetAfterTest();
        [$course, $row, $student] = $this->make_poll();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        poll::record_vote($row, (int)$student->id, $this->optionid($row, 'Red'));

        $data = poll::export_for_template($row, (int)$teacher->id);

        $this->assertTrue($data['canviewresults']);
        $this->assertFalse($data['canvote']);
        $this->assertTrue($data['showresults']);
        $this->assertSame(1, $data['total']);
        $this->assertSame(100, $data['items'][0]['percent']);
    }

    /**
     * A poll shows its options one per line unless it is compact.
     */
    public function test_compact_is_exported(): void {
        $this->resetAfterTest();
        [$course, $row, $student] = $this->make_poll();
        $compact = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll([
            'cmid' => $row->cmid,
            'compact' => 1,
        ]);

        $polls = poll::for_cm((int)$course->id, (int)$row->cmid);

        $this->assertFalse(poll::export_for_template($polls[$row->embedkey], (int)$student->id)['compact']);
        $this->assertTrue(poll::export_for_template($polls[$compact->embedkey], (int)$student->id)['compact']);
    }

    /**
     * The editor's preview can never be voted in, whoever looks at it.
     */
    public function test_preview_cannot_be_voted_in(): void {
        $this->resetAfterTest();
        [, $row, $student] = $this->make_poll();

        $this->assertFalse(poll::export_for_template($row, (int)$student->id, true)['canvote']);
    }

    /**
     * An option the poll does not have is refused.
     */
    public function test_invalid_option_is_rejected(): void {
        $this->resetAfterTest();
        [, $row, $student] = $this->make_poll(['Red', 'Green']);

        $this->expectException(\moodle_exception::class);
        poll::record_vote($row, (int)$student->id, 99);
    }

    /**
     * A course's polls are read in one go, and split between activities and sections.
     */
    public function test_for_course_splits_activities_and_sections(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_embedpoll');
        $activity = $generator->create_poll(['cmid' => $page->cmid]);
        $section = $generator->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(1)->id]);
        $generator->create_poll(['courseid' => $course->id]);

        $this->assertSame([$activity->embedkey], array_keys(poll::for_cm((int)$course->id, (int)$page->cmid)));
        $this->assertSame([$section->embedkey], array_keys(poll::for_sections((int)$course->id)));
    }
}
