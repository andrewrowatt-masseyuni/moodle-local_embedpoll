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

use core_course\hook\before_course_deleted;

/**
 * Hook callbacks for embedded polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Delete a course's polls before the course goes.
     *
     * Course deletion removes modules without the per-module delete callbacks, so
     * local_embedpoll_pre_course_module_delete() never sees them.
     *
     * @param before_course_deleted $hook The hook.
     */
    public static function before_course_deleted(before_course_deleted $hook): void {
        api::delete_for_course((int)$hook->course->id);
    }
}
