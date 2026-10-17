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
 * Site settings for embedded polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_embedpoll', new lang_string('pluginname', 'local_embedpoll'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // A whole number no smaller than poll::MINOPTIONS, which is 2.
        $settings->add(new admin_setting_configtext(
            'local_embedpoll/maxoptions',
            new lang_string('maxoptions', 'local_embedpoll'),
            new lang_string('maxoptions_desc', 'local_embedpoll'),
            \local_embedpoll\poll::DEFAULT_MAXOPTIONS,
            '/^([2-9]|[1-9][0-9]+)$/'
        ));
    }
}
