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
 * Upgrade steps for embedded polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_local_embedpoll_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026101701) {
        // Every existing poll keeps its options one per line, the default.
        $table = new xmldb_table('local_embedpoll');
        $field = new xmldb_field('compact', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'options');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101701, 'local', 'embedpoll');
    }

    if ($oldversion < 2026101702) {
        // Every existing poll keeps showing a percentage beside each option, the default.
        $table = new xmldb_table('local_embedpoll');
        $field = new xmldb_field('stats', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'percent', 'compact');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101702, 'local', 'embedpoll');
    }

    return true;
}
