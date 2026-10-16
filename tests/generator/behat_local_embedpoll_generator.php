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
 * Behat data generator for embedded polls.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_embedpoll_generator extends behat_generator_base {
    /**
     * Entities this generator can create.
     *
     * "local_embedpoll > polls": a poll for the activity with the given idnumber. Put the matching
     * token - <div class="embedpoll-embed" data-embedpoll="KEY"></div> - into the activity's text,
     * a chapter or a page for it to be shown. Options are separated by commas.
     *
     * "local_embedpoll > section polls": a poll for the section with the given number in the course
     * with the given shortname. Its summary should hold the token; give "summary" to set it.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'polls' => [
                'singular' => 'poll',
                'datagenerator' => 'poll',
                'required' => ['activity', 'embedkey', 'options'],
                'switchids' => ['activity' => 'cmid'],
            ],
            'section polls' => [
                'singular' => 'section poll',
                'datagenerator' => 'section_poll',
                'required' => ['course', 'section', 'embedkey', 'options'],
                'switchids' => ['course' => 'courseid'],
            ],
        ];
    }
}
