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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for creating, adopting, copying and deleting polls.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\api
 * @covers     \local_embedpoll\observer
 * @covers     \local_embedpoll\hook_callbacks
 * @covers     ::local_embedpoll_coursemodule_edit_post_actions
 * @covers     ::local_embedpoll_pre_course_module_delete
 */
final class api_test extends \advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        poll::reset_cache();
    }

    /**
     * The generator.
     *
     * @return \local_embedpoll_generator
     */
    private function generator(): \local_embedpoll_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_embedpoll');
    }

    /**
     * A poll's labels, in order.
     *
     * @param string $key The poll's key.
     * @return string[]
     */
    private function labels(string $key): array {
        global $DB;

        return array_column(poll::options($DB->get_record('local_embedpoll', ['embedkey' => $key], '*', MUST_EXIST)), 'label');
    }

    /**
     * A new poll in an activity, then edited.
     */
    public function test_save_and_edit(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);

        $key = api::save_embed($context, null, ['Red', 'Green']);

        $row = $DB->get_record('local_embedpoll', ['embedkey' => $key], '*', MUST_EXIST);
        $this->assertSame((int)$course->id, (int)$row->courseid);
        $this->assertSame((int)$page->cmid, (int)$row->cmid);
        $this->assertSame(0, (int)$row->sectionid);
        $this->assertSame(['Red', 'Green'], $this->labels($key));

        $this->assertSame($key, api::save_embed($context, $key, ['Green', 'Red', 'Blue']));
        $this->assertSame(['Green', 'Red', 'Blue'], $this->labels($key));
        $this->assertSame(1, $DB->count_records('local_embedpoll'));
    }

    /**
     * A poll is compact only when it is saved as compact.
     */
    public function test_save_compact(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);

        $key = api::save_embed($context, null, ['Yes', 'No']);
        $this->assertSame(0, (int)$DB->get_field('local_embedpoll', 'compact', ['embedkey' => $key]));

        api::save_embed($context, $key, ['Yes', 'No'], 0, true);
        $this->assertSame(1, (int)$DB->get_field('local_embedpoll', 'compact', ['embedkey' => $key]));

        api::save_embed($context, $key, ['Yes', 'No']);
        $this->assertSame(0, (int)$DB->get_field('local_embedpoll', 'compact', ['embedkey' => $key]));
    }

    /**
     * Votes follow an option's text: reordering and adding keep them, rewording and removing drop
     * only that option's.
     */
    public function test_votes_follow_option_text(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $key = api::save_embed($context, null, ['Red', 'Green', 'Blue']);
        $row = $DB->get_record('local_embedpoll', ['embedkey' => $key], '*', MUST_EXIST);

        $voters = [];
        foreach (['Red', 'Red', 'Green', 'Blue'] as $label) {
            $voter = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->generator()->create_vote($row, (int)$voter->id, $label);
            $voters[$label][] = $voter;
        }

        // Green reworded, Blue removed, Red moved, one added.
        api::save_embed($context, $key, ['Grey', 'Red', 'Turquoise']);
        $row = $DB->get_record('local_embedpoll', ['id' => $row->id], '*', MUST_EXIST);

        $data = poll::export_for_template($row, (int)$voters['Red'][0]->id);
        $this->assertSame(['Grey', 'Red', 'Turquoise'], array_column($data['items'], 'label'));
        $this->assertSame([0, 2, 0], array_column($data['items'], 'count'));
        $this->assertTrue($data['items'][1]['selected']);
        $this->assertSame(2, $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id]));

        // Those whose option went have not voted any more, and can vote again.
        $this->assertFalse(poll::export_for_template($row, (int)$voters['Green'][0]->id)['hasvoted']);
    }

    /**
     * Labels that do not make a poll are refused.
     */
    public function test_label_errors(): void {
        $this->assertNull(api::label_error(['Yes', 'No']));
        $this->assertNotNull(api::label_error(['Only one']));
        $this->assertNotNull(api::label_error([]));
        $this->assertNotNull(api::label_error(['Yes', 'yes']));
        $this->assertNotNull(api::label_error(['Yes', str_repeat('x', poll::MAXLENGTH + 1)]));
        $this->assertNull(api::label_error(['Yes', str_repeat('é', poll::MAXLENGTH)]));
    }

    /**
     * Saving labels that do not make a poll throws, and writes nothing.
     */
    public function test_saving_bad_labels_throws(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        try {
            api::save_embed(\context_module::instance($page->cmid), null, ['Only one']);
            $this->fail('Expected an exception');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_embedpoll'));
        }
    }

    /**
     * A key from another activity gets a new poll, with the same labels, in this one.
     */
    public function test_a_foreign_key_gets_a_new_poll(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $foreign = $this->generator()->create_poll(['cmid' => $first->cmid]);

        $key = api::save_embed(\context_module::instance($second->cmid), $foreign->embedkey, ['Yes', 'No']);

        $this->assertNotSame($foreign->embedkey, $key);
        $this->assertSame((int)$second->cmid, (int)$DB->get_field('local_embedpoll', 'cmid', ['embedkey' => $key]));
        $this->assertSame(['Red', 'Green', 'Blue'], $this->labels($foreign->embedkey));
    }

    /**
     * Where a poll made in each kind of editor belongs.
     */
    public function test_embed_target(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $section = get_fast_modinfo($course)->get_section_info(1);
        $coursecontext = \context_course::instance($course->id);

        $this->assertSame([(int)$course->id, (int)$page->cmid, 0], api::embed_target(\context_module::instance($page->cmid)));
        $this->assertSame([(int)$course->id, 0, 0], api::embed_target($coursecontext));
        $this->assertSame([(int)$course->id, 0, (int)$section->id], api::embed_target($coursecontext, (int)$section->id));
    }

    /**
     * Not the front page, a category, the site, or a section in another course.
     */
    public function test_embed_target_refuses_elsewhere(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $foreignsection = (int)get_fast_modinfo($other)->get_section_info(1)->id;

        $contexts = [
            [\context_course::instance(SITEID), 0],
            [\context_system::instance(), 0],
            [\context_coursecat::instance($course->category), 0],
            [\context_course::instance($course->id), $foreignsection],
        ];
        foreach ($contexts as [$context, $sectionid]) {
            try {
                api::embed_target($context, $sectionid);
                $this->fail('Expected an exception for ' . $context->get_context_name());
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
    }

    /**
     * A draft made while a label was being added is adopted when it is saved.
     */
    public function test_a_draft_in_a_description_is_adopted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $key = api::save_embed(\context_course::instance($course->id), null, ['Yes', 'No']);
        $this->assertSame(0, (int)$DB->get_field('local_embedpoll', 'cmid', ['embedkey' => $key]));

        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => '<p>Vote:</p>' . token::html($key),
        ]);

        $this->assertSame((int)$label->cmid, (int)$DB->get_field('local_embedpoll', 'cmid', ['embedkey' => $key]));
    }

    /**
     * ...and in any other text of the activity's own: a page's content is on the same form.
     */
    public function test_a_draft_in_page_content_is_adopted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $key = api::save_embed(\context_course::instance($course->id), null, ['Yes', 'No']);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Vote:</p>' . token::html($key),
        ]);

        $this->assertSame((int)$page->cmid, (int)$DB->get_field('local_embedpoll', 'cmid', ['embedkey' => $key]));
    }

    /**
     * A draft in another course is not adopted, nor a section's poll, even with the right key.
     */
    public function test_only_this_courses_drafts_are_adopted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $elsewhere = $this->getDataGenerator()->create_course();
        $draft = $this->generator()->create_poll(['courseid' => $elsewhere->id]);
        $course = $this->getDataGenerator()->create_course();
        $section = $this->generator()->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(1)->id]);

        $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => token::html($draft->embedkey) . token::html($section->embedkey),
        ]);

        $this->assertSame(0, (int)$DB->get_field('local_embedpoll', 'cmid', ['id' => $draft->id]));
        $this->assertSame(0, (int)$DB->get_field('local_embedpoll', 'cmid', ['id' => $section->id]));
    }

    /**
     * Abandoned drafts are purged; recent ones, adopted polls and sections' polls are not.
     */
    public function test_purge_drafts(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $old = $this->generator()->create_poll(['courseid' => $course->id, 'timemodified' => time() - 2 * DAYSECS]);
        $recent = $this->generator()->create_poll(['courseid' => $course->id]);
        $adopted = $this->generator()->create_poll(['cmid' => $page->cmid, 'timemodified' => time() - 2 * DAYSECS]);
        $section = $this->generator()->create_poll([
            'sectionid' => get_fast_modinfo($course)->get_section_info(1)->id,
            'timemodified' => time() - 2 * DAYSECS,
        ]);

        $this->assertSame(1, api::purge_drafts(time() - api::DRAFT_LIFETIME));

        $this->assertFalse($DB->record_exists('local_embedpoll', ['id' => $old->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $recent->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $adopted->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $section->id]));
    }

    /**
     * Deleting an activity deletes its polls and their votes.
     */
    public function test_deleting_an_activity_cleans_up(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $keep = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $row = $this->generator()->create_poll(['cmid' => $page->cmid]);
        $kept = $this->generator()->create_poll(['cmid' => $keep->cmid]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->generator()->create_vote($row, (int)$student->id, 'Red');
        $this->generator()->create_vote($kept, (int)$student->id, 'Red');

        course_delete_module($page->cmid);

        $this->assertFalse($DB->record_exists('local_embedpoll', ['id' => $row->id]));
        $this->assertFalse($DB->record_exists('local_embedpoll_vote', ['pollid' => $row->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $kept->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll_vote', ['pollid' => $kept->id]));
    }

    /**
     * Deleting a course deletes its polls, drafts included, and their votes.
     */
    public function test_deleting_a_course_cleans_up(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $otherpage = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $row = $this->generator()->create_poll(['cmid' => $page->cmid]);
        $this->generator()->create_poll(['courseid' => $course->id]);
        $this->generator()->create_poll(['cmid' => $otherpage->cmid]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->generator()->create_vote($row, (int)$student->id, 'Red');

        delete_course($course, false);

        $this->assertSame(0, $DB->count_records('local_embedpoll', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id]));
        $this->assertSame(1, $DB->count_records('local_embedpoll', ['courseid' => $other->id]));
    }

    /**
     * A course whose section 1 summary holds a poll with a vote.
     *
     * @return array [course, section 1 info, row]
     */
    private function make_section(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $row = $this->generator()->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(1)->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->generator()->create_vote($row, (int)$student->id, 'Green');

        $this->set_summary($course, 1, '<p>Week one.</p>' . token::html($row->embedkey));

        return [$course, get_fast_modinfo($course)->get_section_info(1), $row];
    }

    /**
     * Change a section's summary as the section form does, firing the event.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @param string $summary The new summary.
     */
    private function set_summary(\stdClass $course, int $sectionnum, string $summary): void {
        $section = get_fast_modinfo($course)->get_section_info($sectionnum);
        course_update_section($course, $section, ['summary' => $summary, 'summaryformat' => FORMAT_HTML]);
    }

    /**
     * A poll saved from a section's editor belongs to the section, not to a draft.
     */
    public function test_saving_in_a_section(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $section = get_fast_modinfo($course)->get_section_info(1);

        $key = api::save_embed(\context_course::instance($course->id), null, ['Yes', 'No'], (int)$section->id);

        $row = $DB->get_record('local_embedpoll', ['embedkey' => $key], '*', MUST_EXIST);
        $this->assertSame(0, (int)$row->cmid);
        $this->assertSame((int)$section->id, (int)$row->sectionid);
        $this->assertSame([$key], array_keys(poll::for_sections((int)$course->id)));
    }

    /**
     * Duplicating a section gives the copy a poll of its own, with the same options and no votes.
     */
    public function test_duplicating_a_section_copies_its_polls(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $section, $row] = $this->make_section();

        $copy = course_get_format($course)->duplicate_section($section);

        $keys = token::keys_in($copy->summary);
        $this->assertCount(1, $keys);
        $this->assertNotSame($row->embedkey, $keys[0]);
        $this->assertStringStartsWith('<p>Week one.</p>', $copy->summary);

        $new = $DB->get_record('local_embedpoll', ['embedkey' => $keys[0]], '*', MUST_EXIST);
        $this->assertSame((int)$copy->id, (int)$new->sectionid);
        $this->assertSame($row->options, $new->options);
        $this->assertSame(0, $DB->count_records('local_embedpoll_vote', ['pollid' => $new->id]));

        // The original is untouched.
        $this->assertSame(
            [$row->embedkey],
            token::keys_in(get_fast_modinfo($course)->get_section_info_by_id($section->id)->summary)
        );
        $this->assertSame(1, $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id]));
    }

    /**
     * A token pasted from one section into another becomes a copy, as a duplicate does.
     */
    public function test_pasting_a_token_into_another_section_copies_it(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $row] = $this->make_section();

        $this->set_summary($course, 2, token::html($row->embedkey) . '<p>Week two.</p>');

        $section2 = get_fast_modinfo($course)->get_section_info(2);
        $keys = token::keys_in($section2->summary);
        $this->assertCount(1, $keys);
        $this->assertNotSame($row->embedkey, $keys[0]);
        $this->assertSame(
            (int)$section2->id,
            (int)$DB->get_field('local_embedpoll', 'sectionid', ['embedkey' => $keys[0]], MUST_EXIST)
        );
        $this->assertSame(2, $DB->count_records('local_embedpoll', ['courseid' => $course->id]));
    }

    /**
     * Saving a section that holds only its own polls changes nothing.
     */
    public function test_saving_a_section_again_changes_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $section, $row] = $this->make_section();

        $this->set_summary($course, 1, $section->summary . '<p>More.</p>');

        $this->assertSame([$row->embedkey], token::keys_in(get_fast_modinfo($course)->get_section_info(1)->summary));
        $this->assertSame(1, $DB->count_records('local_embedpoll', ['courseid' => $course->id]));
    }

    /**
     * Deleting a section deletes its polls and their votes.
     */
    public function test_deleting_a_section_cleans_up(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $row] = $this->make_section();
        $kept = $this->generator()->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(2)->id]);

        course_delete_section($course, 1);

        $this->assertFalse($DB->record_exists('local_embedpoll', ['id' => $row->id]));
        $this->assertFalse($DB->record_exists('local_embedpoll_vote', ['pollid' => $row->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $kept->id]));
    }

    /**
     * Deleting a course's contents deletes its sections' polls, but not its drafts or other courses'.
     */
    public function test_deleting_course_contents_cleans_up_sections(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $row] = $this->make_section();
        $draft = $this->generator()->create_poll(['courseid' => $course->id]);
        [, , $other] = $this->make_section();

        remove_course_contents($course->id, false);

        $this->assertFalse($DB->record_exists('local_embedpoll', ['id' => $row->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $draft->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll', ['id' => $other->id]));
    }
}
