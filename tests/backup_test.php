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
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for polls riding along with activity and section backup and restore.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_embedpoll_plugin
 * @covers     \restore_local_embedpoll_plugin
 */
final class backup_test extends \advanced_testcase {
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
     * Back a course up and restore it into a new course, or add it to an existing one.
     *
     * @param int $courseid The course.
     * @param bool $userdata Whether to include user data.
     * @param int $targetid A course to add it to, or 0 for a new course.
     * @return int The course restored into.
     */
    private function backup_and_restore(int $courseid, bool $userdata = false, int $targetid = 0): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backup->get_plan()->get_setting('users')->set_value($userdata);
        $backup->execute_plan();
        $results = $backup->get_results();
        $backupid = $backup->get_backupid();
        $backup->destroy();

        $path = make_backup_temp_directory($backupid);
        $results['backup_destination']->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);

        if ($targetid) {
            $newcourseid = $targetid;
        } else {
            $source = get_course($courseid);
            $newcourseid = \restore_dbops::create_new_course($source->fullname, $source->shortname . '_copy', $source->category);
        }

        $restore = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            $targetid ? \backup::TARGET_EXISTING_ADDING : \backup::TARGET_NEW_COURSE
        );
        $restore->get_plan()->get_setting('users')->set_value($userdata);
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();

        return $newcourseid;
    }

    /**
     * A book with two polls, one with two votes.
     *
     * @return array [course, book, voted row, other (compact) row]
     */
    private function make_book(): array {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);

        $voted = $this->generator()->create_poll(['cmid' => $book->cmid, 'options' => ['Yes', 'No']]);
        $other = $this->generator()->create_poll(['cmid' => $book->cmid, 'options' => ['Red', 'Green'], 'compact' => 1]);
        foreach (['Yes', 'No'] as $label) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->generator()->create_vote($voted, (int)$student->id, $label);
        }

        return [$course, $book, $voted, $other];
    }

    /**
     * The polls in a course's only book, keyed by key.
     *
     * @param int $courseid The course.
     * @return \stdClass[]
     */
    private function book_polls(int $courseid): array {
        global $DB;

        $books = get_fast_modinfo($courseid)->get_instances_of('book');
        $cm = reset($books);

        return $DB->get_records('local_embedpoll', ['cmid' => $cm->id], '', 'embedkey, id, courseid, options, compact');
    }

    /**
     * Without user data - a course copied for its next run - polls come across with their keys,
     * options and layout, and no votes.
     */
    public function test_polls_survive_a_course_restore_without_votes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $voted, $other] = $this->make_book();

        $newcourseid = $this->backup_and_restore((int)$course->id);

        $rows = $this->book_polls($newcourseid);
        $this->assertCount(2, $rows);
        // The whole point: the same keys, so the tokens restored in the text still match.
        $this->assertSame($voted->options, $rows[$voted->embedkey]->options);
        $this->assertSame($other->options, $rows[$other->embedkey]->options);
        $this->assertSame(0, (int)$rows[$voted->embedkey]->compact);
        $this->assertSame(1, (int)$rows[$other->embedkey]->compact);
        $this->assertSame($newcourseid, (int)$rows[$voted->embedkey]->courseid);
        $this->assertSame(0, $DB->count_records('local_embedpoll_vote', ['pollid' => $rows[$voted->embedkey]->id]));

        // And the original is untouched.
        $this->assertSame(2, $DB->count_records('local_embedpoll_vote', ['pollid' => $voted->id]));
    }

    /**
     * With user data, the votes come too, against the restored poll.
     */
    public function test_votes_survive_a_course_restore_with_user_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $voted, $other] = $this->make_book();

        $newcourseid = $this->backup_and_restore((int)$course->id, true);

        $rows = $this->book_polls($newcourseid);
        $votes = $DB->get_records('local_embedpoll_vote', ['pollid' => $rows[$voted->embedkey]->id], 'optionid');
        $this->assertSame(
            array_column(poll::options($voted), 'id'),
            array_map('intval', array_column($votes, 'optionid'))
        );
        $this->assertSame(0, $DB->count_records('local_embedpoll_vote', ['pollid' => $rows[$other->embedkey]->id]));
    }

    /**
     * Duplicating an activity copies its polls onto the copy, without votes.
     */
    public function test_duplicating_an_activity_copies_its_polls(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $book, $voted] = $this->make_book();

        $newcm = duplicate_module($course, get_fast_modinfo($course->id)->get_cm($book->cmid));

        $this->assertSame(2, $DB->count_records('local_embedpoll', ['cmid' => $newcm->id]));
        $copy = $DB->get_record('local_embedpoll', ['cmid' => $newcm->id, 'embedkey' => $voted->embedkey], '*', MUST_EXIST);
        $this->assertSame(0, $DB->count_records('local_embedpoll_vote', ['pollid' => $copy->id]));
        $this->assertSame(2, $DB->count_records('local_embedpoll', ['cmid' => $book->cmid]));
    }

    /**
     * Change a section's summary.
     *
     * @param int $courseid The course.
     * @param int $sectionnum The section number.
     * @param string $summary The summary.
     */
    private function set_summary(int $courseid, int $sectionnum, string $summary): void {
        course_update_section($courseid, get_fast_modinfo($courseid)->get_section_info($sectionnum), ['summary' => $summary]);
    }

    /**
     * Section polls come across with their keys and options, against the new sections.
     */
    public function test_section_polls_survive_a_course_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $row = $this->generator()->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(1)->id]);
        $this->set_summary((int)$course->id, 1, '<p>Week one.</p>' . token::html($row->embedkey));

        $newcourseid = $this->backup_and_restore((int)$course->id);

        $modinfo = get_fast_modinfo($newcourseid);
        $restored = $DB->get_record('local_embedpoll', ['courseid' => $newcourseid, 'embedkey' => $row->embedkey], '*', MUST_EXIST);
        $this->assertSame((int)$modinfo->get_section_info(1)->id, (int)$restored->sectionid);
        $this->assertSame(0, (int)$restored->cmid);
        $this->assertSame($row->options, $restored->options);
    }

    /**
     * Added to a course that already uses a key elsewhere, a section's poll takes a new one.
     */
    public function test_section_polls_merged_into_a_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $row = $this->generator()->create_poll(['sectionid' => get_fast_modinfo($course)->get_section_info(1)->id]);
        $this->set_summary((int)$course->id, 1, token::html($row->embedkey));

        // The target: section 1 empty, so it takes the backup's summary; section 2 already holds a
        // poll with the key the backup's section 1 uses.
        $target = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $theirs = $this->generator()->create_poll([
            'sectionid' => get_fast_modinfo($target)->get_section_info(2)->id,
            'embedkey' => $row->embedkey,
        ]);
        $this->set_summary((int)$target->id, 2, token::html($row->embedkey));

        $this->backup_and_restore((int)$course->id, false, (int)$target->id);

        $modinfo = get_fast_modinfo($target->id);
        $keys = token::keys_in($modinfo->get_section_info(1)->summary);
        $this->assertCount(1, $keys);
        $this->assertNotSame($row->embedkey, $keys[0]);
        $restored = $DB->get_record('local_embedpoll', ['courseid' => $target->id, 'embedkey' => $keys[0]], '*', MUST_EXIST);
        $this->assertSame((int)$modinfo->get_section_info(1)->id, (int)$restored->sectionid);

        // Section 2's poll is untouched.
        $this->assertSame([$row->embedkey], token::keys_in($modinfo->get_section_info(2)->summary));
        $this->assertSame((int)$theirs->id, (int)$DB->get_field('local_embedpoll', 'id', [
            'courseid' => $target->id,
            'embedkey' => $row->embedkey,
        ]));
    }
}
