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
 * Backup of embedded polls, attached to every activity and every section.
 *
 * @package    local_embedpoll
 * @category   backup
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds an activity's polls to its module.xml, and a section's to its section.xml.
 *
 * This is why polls live in a local plugin rather than in the filter: core only lets format,
 * report, plagiarism, local and tool plugins add data to another module's backup. Riding along
 * here covers course backup and restore, import, course copy, duplicating an activity and the
 * recycle bin, with nothing else to wire up. Duplicating a section is not a backup; see
 * local_embedpoll\api::claim_section_summary().
 *
 * embedkey is carried verbatim: it is what the token in the restored text matches on. Votes go
 * only with user data, so a course copied for its next run - or a duplicated activity - starts
 * with fresh polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_embedpoll_plugin extends backup_local_plugin {
    /**
     * Polls at the module level.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        return $this->define_polls(['cmid' => backup::VAR_MODID]);
    }

    /**
     * Polls at the section level.
     *
     * @return backup_plugin_element
     */
    protected function define_section_plugin_structure() {
        return $this->define_polls(['courseid' => backup::VAR_COURSEID, 'sectionid' => backup::VAR_SECTIONID]);
    }

    /**
     * The polls matching a source, with their votes when user data is included.
     *
     * @param array $source The source table's conditions.
     * @return backup_plugin_element
     */
    protected function define_polls(array $source) {
        $plugin = $this->get_plugin_element();

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $polls = new backup_nested_element('polls');
        $poll = new backup_nested_element('poll', ['id'], [
            'embedkey',
            'options',
            'timecreated',
            'timemodified',
        ]);
        $votes = new backup_nested_element('votes');
        $vote = new backup_nested_element('vote', ['id'], [
            'userid',
            'optionid',
            'timecreated',
            'timemodified',
        ]);

        $plugin->add_child($wrapper);
        $wrapper->add_child($polls);
        $polls->add_child($poll);
        $poll->add_child($votes);
        $votes->add_child($vote);

        $poll->set_source_table('local_embedpoll', $source);
        if ($this->get_setting_value('userinfo')) {
            $vote->set_source_table('local_embedpoll_vote', ['pollid' => backup::VAR_PARENTID]);
        }
        $vote->annotate_ids('user', 'userid');

        return $plugin;
    }
}
