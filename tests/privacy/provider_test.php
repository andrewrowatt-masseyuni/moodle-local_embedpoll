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

namespace local_embedpoll\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_embedpoll\poll;

/**
 * Tests for the privacy provider.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        poll::reset_cache();
    }

    /**
     * A course with a poll in a page and one in a section, and two students who voted in both.
     *
     * @return array [module context, course context, first student, second student, page row, section row]
     */
    private function make_votes(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_embedpoll');
        $pagepoll = $generator->create_poll(['cmid' => $page->cmid]);
        $sectionpoll = $generator->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(1)->id]);

        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        foreach ([$first, $second] as $student) {
            $generator->create_vote($pagepoll, (int)$student->id, 'Green');
            $generator->create_vote($sectionpoll, (int)$student->id, 'Blue');
        }

        return [
            \context_module::instance($page->cmid),
            \context_course::instance($course->id),
            $first,
            $second,
            $pagepoll,
            $sectionpoll,
        ];
    }

    /**
     * A vote belongs to its poll's context: the activity's, or the course's for a section's poll.
     */
    public function test_contexts_and_users(): void {
        $this->resetAfterTest();
        [$modulecontext, $coursecontext, $first, $second] = $this->make_votes();

        $contexts = array_map('intval', provider::get_contexts_for_userid($first->id)->get_contextids());
        sort($contexts);
        $expected = [(int)$coursecontext->id, (int)$modulecontext->id];
        sort($expected);
        $this->assertSame($expected, $contexts);

        foreach ([$modulecontext, $coursecontext] as $context) {
            $userlist = new userlist($context, 'local_embedpoll');
            provider::get_users_in_context($userlist);
            $userids = array_map('intval', $userlist->get_userids());
            sort($userids);
            $this->assertSame([(int)$first->id, (int)$second->id], $userids);
        }
    }

    /**
     * Export writes the option the user chose, by its label.
     */
    public function test_export(): void {
        $this->resetAfterTest();
        [$modulecontext, $coursecontext, $first, , $pagepoll, $sectionpoll] = $this->make_votes();

        $this->export_context_data_for_user($first->id, $modulecontext, 'local_embedpoll');
        $this->export_context_data_for_user($first->id, $coursecontext, 'local_embedpoll');

        $name = get_string('pluginname', 'local_embedpoll');
        $this->assertSame('Green', writer::with_context($modulecontext)->get_data([$name, $pagepoll->id])->choice);
        $this->assertSame('Blue', writer::with_context($coursecontext)->get_data([$name, $sectionpoll->id])->choice);
    }

    /**
     * Deleting one user's data leaves the other's.
     */
    public function test_delete_for_user(): void {
        global $DB;

        $this->resetAfterTest();
        [$modulecontext, , $first, $second, $pagepoll, $sectionpoll] = $this->make_votes();

        provider::delete_data_for_user(new approved_contextlist($first, 'local_embedpoll', [$modulecontext->id]));

        $this->assertFalse($DB->record_exists('local_embedpoll_vote', ['pollid' => $pagepoll->id, 'userid' => $first->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll_vote', ['pollid' => $pagepoll->id, 'userid' => $second->id]));
        $this->assertTrue($DB->record_exists('local_embedpoll_vote', ['pollid' => $sectionpoll->id, 'userid' => $first->id]));
    }

    /**
     * Deleting everyone's data in a context deletes only that context's votes.
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;

        $this->resetAfterTest();
        [, $coursecontext, , , $pagepoll, $sectionpoll] = $this->make_votes();

        provider::delete_data_for_all_users_in_context($coursecontext);

        $this->assertSame(0, $DB->count_records('local_embedpoll_vote', ['pollid' => $sectionpoll->id]));
        $this->assertSame(2, $DB->count_records('local_embedpoll_vote', ['pollid' => $pagepoll->id]));
    }

    /**
     * Deleting some users' data in a context leaves everyone else's.
     */
    public function test_delete_for_users(): void {
        global $DB;

        $this->resetAfterTest();
        [$modulecontext, , $first, $second, $pagepoll] = $this->make_votes();

        provider::delete_data_for_users(new approved_userlist($modulecontext, 'local_embedpoll', [$second->id]));

        $this->assertTrue($DB->record_exists('local_embedpoll_vote', ['pollid' => $pagepoll->id, 'userid' => $first->id]));
        $this->assertFalse($DB->record_exists('local_embedpoll_vote', ['pollid' => $pagepoll->id, 'userid' => $second->id]));
    }
}
