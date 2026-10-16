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

use local_embedpoll\poll;
use local_embedpoll\token;

/**
 * Test data generator for embedded polls.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_embedpoll_generator extends component_generator_base {
    /**
     * Create a poll directly, without going through an editor.
     *
     * @param array|stdClass $record Needs cmid, sectionid, or courseid for a draft. Optional:
     *     embedkey, and options - an array of labels, or a string of them separated by commas.
     * @return stdClass The new row.
     */
    public function create_poll($record): stdClass {
        global $DB;

        $record = (array)$record;
        $now = time();

        if (!empty($record['cmid'])) {
            $record['courseid'] = (int)$DB->get_field('course_modules', 'course', ['id' => $record['cmid']], MUST_EXIST);
        } else if (!empty($record['sectionid'])) {
            $record['courseid'] = (int)$DB->get_field('course_sections', 'course', ['id' => $record['sectionid']], MUST_EXIST);
        }

        $labels = $record['options'] ?? ['Red', 'Green', 'Blue'];
        if (is_string($labels)) {
            $labels = array_values(array_filter(array_map('trim', explode(',', $labels)), 'strlen'));
        }
        [$options] = poll::merge_options([], $labels);

        $row = (object)array_merge([
            'cmid' => 0,
            'sectionid' => 0,
            'embedkey' => token::new_key(),
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record, ['options' => poll::encode_options($options)]);

        $row->id = $DB->insert_record('local_embedpoll', $row);
        poll::reset_cache();

        return $row;
    }

    /**
     * Create a poll for a section, named by course and section number, as Behat does.
     *
     * Core has no generator for section summaries, so this can set one too.
     *
     * @param array|stdClass $record Needs courseid and section (the number). Optional: summary, the
     *     section's new summary, which should hold the token. Otherwise as create_poll().
     * @return stdClass The new row.
     */
    public function create_section_poll($record): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $record = (array)$record;
        $section = get_fast_modinfo($record['courseid'])->get_section_info($record['section'], MUST_EXIST);
        $summary = $record['summary'] ?? null;
        unset($record['section'], $record['summary']);

        $row = $this->create_poll(['sectionid' => $section->id] + $record);

        if ($summary !== null) {
            course_update_section($record['courseid'], $section, ['summary' => $summary, 'summaryformat' => FORMAT_HTML]);
        }

        return $row;
    }

    /**
     * Cast a vote, as the user would.
     *
     * @param stdClass $poll The poll.
     * @param int $userid The user.
     * @param string $label The label of the option they choose.
     */
    public function create_vote(stdClass $poll, int $userid, string $label): void {
        $ids = array_column(poll::options($poll), 'id', 'label');
        poll::record_vote($poll, $userid, $ids[$label]);
    }
}
