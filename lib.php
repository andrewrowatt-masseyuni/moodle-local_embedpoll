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
 * Library callbacks for embedded polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_embedpoll\api;

/**
 * After any activity is saved through its settings form, adopt the drafts its text now holds.
 *
 * A poll made on the "add an activity" form - before the activity had an id - is a draft in the
 * course until then.
 *
 * Failures are reported rather than thrown: the activity has already been saved, and a problem with
 * its polls must not turn a successful save into an error page.
 *
 * @param stdClass $data The module info, with ->coursemodule set.
 * @param stdClass $course The course.
 * @return stdClass The module info, unchanged.
 */
function local_embedpoll_coursemodule_edit_post_actions($data, $course) {
    if (empty($data->coursemodule)) {
        return $data;
    }

    try {
        api::adopt_drafts((int)$data->coursemodule);
    } catch (\moodle_exception $e) {
        debugging('Could not adopt polls for course module ' . $data->coursemodule . ': ' .
            $e->getMessage(), DEBUG_DEVELOPER);
    }

    return $data;
}

/**
 * Delete a course module's polls, and their votes, before the module goes.
 *
 * tool_recyclebin's own callback runs first (tool before local), so a recycled activity is backed
 * up with its polls before this deletes them.
 *
 * @param stdClass $cm The course_modules record.
 */
function local_embedpoll_pre_course_module_delete($cm) {
    api::delete_for_cm((int)$cm->id);
}
