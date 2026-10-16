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
 * Web services for embedded polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Both flagged ajax and put in no service: they are called by this plugin's own JS, and
// tiny_embedpoll's, as the logged-in user, never by an external client with a token.
$functions = [
    'local_embedpoll_get_previews' => [
        'classname' => 'local_embedpoll\external\get_previews',
        'description' => 'Render polls as the page shows them, for the editor to preview.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_embedpoll_vote' => [
        'classname' => 'local_embedpoll\external\vote',
        'description' => 'Cast or change the current user\'s vote in a poll and return the revealed results.',
        'type' => 'write',
        'capabilities' => 'local/embedpoll:vote',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
