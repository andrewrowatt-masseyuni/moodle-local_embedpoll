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

/**
 * Tests for the vote external function.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\external\vote
 */
final class vote_test extends \externallib_advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        poll::reset_cache();
    }

    /**
     * A vote is recorded, and the results come back revealed.
     */
    public function test_vote_records_and_reveals_results(): void {
        $this->resetAfterTest();
        [, $student, $row] = $this->setup_poll();
        $this->setUser($student);

        $result = $this->call_vote((int)$row->id, $this->optionid($row, 'Green'));

        $this->assertSame((int)$row->id, $result['pollid']);
        $this->assertFalse($result['compact']);
        $this->assertTrue($result['showpercent']);
        $this->assertFalse($result['showcount']);
        $this->assertTrue($result['hasvoted']);
        $this->assertTrue($result['showresults']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('1 vote', $result['totaltext']);
        $this->assertTrue($result['items'][1]['selected']);
        $this->assertSame(100, $result['items'][1]['percent']);
    }

    /**
     * A second vote replaces the first.
     */
    public function test_revote_replaces_previous_choice(): void {
        global $DB;

        $this->resetAfterTest();
        [, $student, $row] = $this->setup_poll();
        $this->setUser($student);

        $this->call_vote((int)$row->id, $this->optionid($row, 'Red'));
        $result = $this->call_vote((int)$row->id, $this->optionid($row, 'Blue'));

        $this->assertSame(1, $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id]));
        $this->assertSame(1, $result['total']);
        $this->assertTrue($result['items'][2]['selected']);
        $this->assertFalse($result['items'][0]['selected']);
    }

    /**
     * An option the poll does not have is refused.
     */
    public function test_unknown_option_is_rejected(): void {
        $this->resetAfterTest();
        [, $student, $row] = $this->setup_poll();
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->call_vote((int)$row->id, 99);
    }

    /**
     * Voting needs the capability.
     */
    public function test_vote_requires_capability(): void {
        global $DB;

        $this->resetAfterTest();
        [$context, $student, $row] = $this->setup_poll();
        $this->setUser($student);

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability(poll::CAP_VOTE, CAP_PROHIBIT, $studentrole->id, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\core\exception\required_capability_exception::class);
        $this->call_vote((int)$row->id, $this->optionid($row, 'Red'));
    }

    /**
     * A poll that does not exist is refused.
     */
    public function test_missing_poll_is_rejected(): void {
        $this->resetAfterTest();
        [, $student] = $this->setup_poll();
        $this->setUser($student);

        $this->expectException(\dml_missing_record_exception::class);
        $this->call_vote(-1, 1);
    }

    /**
     * A draft, which belongs to an activity still being added, cannot be voted in.
     */
    public function test_draft_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $draft = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll(['courseid' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $this->expectException(\invalid_parameter_exception::class);
        $this->call_vote((int)$draft->id, $this->optionid($draft, 'Red'));
    }

    /**
     * Invoke the external function and validate the return value against its definition.
     *
     * @param int $pollid
     * @param int $optionid
     * @return array The cleaned return value.
     */
    private function call_vote(int $pollid, int $optionid): array {
        $result = vote::execute($pollid, $optionid);
        return external_api::clean_returnvalue(vote::execute_returns(), $result);
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
     * A page with a poll, and an enrolled student.
     *
     * @return array [module context, student, row]
     */
    private function setup_poll(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $row = $this->getDataGenerator()->get_plugin_generator('local_embedpoll')->create_poll(['cmid' => $page->cmid]);

        return [\context_module::instance($page->cmid), $student, $row];
    }
}
