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

use core\event\course_content_deleted;
use core\event\course_section_deleted;
use core\event\course_section_updated;

/**
 * Event observers for polls in section summaries.
 *
 * Events rather than hooks because core 4.5 has no hooks for sections: duplicating, editing and
 * deleting one all go through core code that only fires these events.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A section was updated: give it its own copy of any poll copied in from another section.
     *
     * This is how a duplicated section gets its own polls. See api::claim_section_summary().
     *
     * @param course_section_updated $event The event.
     */
    public static function course_section_updated(course_section_updated $event): void {
        api::claim_section_summary((int)$event->objectid);
    }

    /**
     * A section was deleted: delete its polls.
     *
     * @param course_section_deleted $event The event.
     */
    public static function course_section_deleted(course_section_deleted $event): void {
        api::delete_for_sections((int)$event->courseid, (int)$event->objectid);
    }

    /**
     * A course's contents were deleted, sections and all, straight from the tables.
     *
     * @param course_content_deleted $event The event.
     */
    public static function course_content_deleted(course_content_deleted $event): void {
        api::delete_for_sections((int)$event->objectid);
    }
}
