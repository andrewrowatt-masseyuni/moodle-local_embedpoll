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

namespace local_embedpoll\task;

use local_embedpoll\api;

/**
 * Delete poll drafts whose activity was never saved.
 *
 * A draft is made when a poll is embedded in the text of an activity that is still being added,
 * and is adopted when the activity is saved. Cancelling the form leaves it behind.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_drafts extends \core\task\scheduled_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:purgedrafts', 'local_embedpoll');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        $count = api::purge_drafts(time() - api::DRAFT_LIFETIME);
        mtrace("Deleted {$count} abandoned poll drafts.");
    }
}
