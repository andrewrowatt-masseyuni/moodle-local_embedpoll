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

/**
 * Restore of embedded polls, attached to every activity and every section.
 *
 * @package    local_embedpoll
 * @category   backup
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores an activity's polls from its module.xml, and a section's from its section.xml.
 *
 * A vote is restored into the poll it was backed up under, which is the last poll processed: votes
 * are nested in their poll, and the restore reads them in document order. A poll that is skipped
 * takes its votes with it.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_embedpoll_plugin extends restore_local_plugin {
    /** @var int The poll the next votes belong to, or 0 if they belong to a poll that was skipped. */
    protected $pollid = 0;

    /**
     * Paths at the module level.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return $this->paths('poll');
    }

    /**
     * Paths at the section level.
     *
     * Also holds off local_embedpoll\api::claim_section_summary() for the course until the section
     * is done. Merging into an existing section fires course_section_updated before this plugin has
     * seen the section's polls, and a claim then would give the section a copy of whichever of the
     * course's polls already has the key, rather than the poll in the backup. This is the last
     * moment before that event; process_local_embedpoll_sectionpoll() sorts the keys out.
     *
     * @return restore_path_element[]
     */
    protected function define_section_plugin_structure() {
        \local_embedpoll\api::hold_claims($this->task->get_courseid(), true);

        return $this->paths('sectionpoll');
    }

    /**
     * The paths for polls, and their votes when user data is being restored.
     *
     * @param string $pollname The name the polls are processed under.
     * @return restore_path_element[]
     */
    protected function paths(string $pollname): array {
        $paths = [new restore_path_element($this->get_namefor($pollname), $this->get_pathfor('/polls/poll'))];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element($this->get_namefor('vote'), $this->get_pathfor('/polls/poll/votes/vote'));
        }

        return $paths;
    }

    /**
     * Restore one poll against the new course module.
     *
     * @param array|stdClass $data The poll as backed up.
     */
    public function process_local_embedpoll_poll($data) {
        global $DB;

        $data = (object)$data;
        $this->pollid = 0;

        $data->courseid = $this->task->get_courseid();
        $data->cmid = $this->task->get_moduleid();
        $data->sectionid = 0;

        // A key already used in this module would break the unique index. It cannot happen with a
        // fresh module, but a restore merged into an existing one is not worth a fatal error: the
        // existing poll wins, and the token resolves to it.
        $taken = ['courseid' => $data->courseid, 'cmid' => $data->cmid, 'embedkey' => $data->embedkey];
        if ($DB->record_exists('local_embedpoll', $taken)) {
            return;
        }

        $this->pollid = $this->insert_poll($data);
    }

    /**
     * Restore one poll against the new, or merged-into, section.
     *
     * The section's summary is already in place, so it decides:
     *
     * * a key the summary does not hold is skipped. That is a merge into an existing section that
     *   kept its own summary, where the poll would have no token to show it;
     * * a key already used by this section's own poll is skipped, and the token resolves to it;
     * * a key used anywhere else in the course - restoring a section back into the course it came
     *   from - would break the unique index and give two sections one key, which the filter cannot
     *   tell apart. The restored poll takes a new key, and the summary follows it.
     *
     * @param array|stdClass $data The poll as backed up.
     */
    public function process_local_embedpoll_sectionpoll($data) {
        global $DB;

        $data = (object)$data;
        $this->pollid = 0;

        $data->courseid = $this->task->get_courseid();
        $data->cmid = 0;
        $data->sectionid = $this->task->get_sectionid();

        $summary = (string)$DB->get_field('course_sections', 'summary', ['id' => $data->sectionid]);
        if (!in_array($data->embedkey, \local_embedpoll\token::keys_in($summary), true)) {
            return;
        }

        $taken = $DB->get_field('local_embedpoll', 'sectionid', [
            'courseid' => $data->courseid,
            'cmid' => 0,
            'embedkey' => $data->embedkey,
        ]);
        if ($taken !== false && (int)$taken === (int)$data->sectionid) {
            return;
        }
        if ($taken !== false) {
            $newkey = \local_embedpoll\token::new_key();
            $summary = \local_embedpoll\token::rekey($summary, [$data->embedkey => $newkey]);
            $DB->set_field('course_sections', 'summary', $summary, ['id' => $data->sectionid]);
            $data->embedkey = $newkey;
        }

        $this->pollid = $this->insert_poll($data);
    }

    /**
     * Restore one vote into the poll just restored.
     *
     * @param array|stdClass $data The vote as backed up.
     */
    public function process_local_embedpoll_vote($data) {
        global $DB;

        $data = (object)$data;
        $userid = $this->get_mappingid('user', $data->userid);
        if (!$this->pollid || !$userid) {
            return;
        }

        $DB->insert_record('local_embedpoll_vote', (object)[
            'pollid' => $this->pollid,
            'userid' => $userid,
            'optionid' => $data->optionid,
            'timecreated' => $data->timecreated,
            'timemodified' => $data->timemodified,
        ]);
    }

    /**
     * Insert a restored poll.
     *
     * @param stdClass $data The poll, placed.
     * @return int Its new id.
     */
    protected function insert_poll(stdClass $data): int {
        global $DB;

        unset($data->id);
        $id = $DB->insert_record('local_embedpoll', $data);
        \local_embedpoll\poll::reset_cache();

        return $id;
    }

    /**
     * The section is done: let claims through again.
     */
    public function after_execute_section() {
        \local_embedpoll\api::hold_claims($this->task->get_courseid(), false);
    }
}
