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

namespace local_embedpoll\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_embedpoll\poll;

/**
 * Cast or change a vote in an embedded poll.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vote extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pollid' => new external_value(PARAM_INT, 'Poll id'),
            'optionid' => new external_value(PARAM_INT, 'The id of the chosen option'),
        ]);
    }

    /**
     * Record the vote and return the now-revealed results for the current user.
     *
     * @param int $pollid The poll.
     * @param int $optionid The chosen option.
     * @return array
     */
    public static function execute(int $pollid, int $optionid): array {
        global $DB, $USER;

        ['pollid' => $pollid, 'optionid' => $optionid] = self::validate_parameters(self::execute_parameters(), [
            'pollid' => $pollid,
            'optionid' => $optionid,
        ]);

        $row = $DB->get_record('local_embedpoll', ['id' => $pollid], '*', MUST_EXIST);

        // A draft belongs to an activity still being added, and is never shown on a page.
        if (!(int)$row->cmid && !(int)$row->sectionid) {
            throw new \invalid_parameter_exception('That poll cannot be voted in yet.');
        }

        $context = poll::context_for($row);
        self::validate_context($context);
        require_capability(poll::CAP_VOTE, $context);

        poll::record_vote($row, (int)$USER->id, $optionid);

        return poll::export_for_template($row, (int)$USER->id);
    }

    /**
     * Return value definition. Mirrors local_embedpoll\poll::export_for_template().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'pollid' => new external_value(PARAM_INT, 'Poll id'),
            'contextid' => new external_value(PARAM_INT, 'Context id'),
            'compact' => new external_value(PARAM_BOOL, 'Whether the options are shown on one line'),
            'canvote' => new external_value(PARAM_BOOL, 'Whether the user may vote'),
            'canviewresults' => new external_value(PARAM_BOOL, 'Whether the user always sees results'),
            'hasvoted' => new external_value(PARAM_BOOL, 'Whether the user has voted'),
            'showresults' => new external_value(PARAM_BOOL, 'Whether results are revealed to the user'),
            'showpercent' => new external_value(PARAM_BOOL, 'Whether each option shows its percentage'),
            'showcount' => new external_value(PARAM_BOOL, 'Whether each option shows its number of votes'),
            'total' => new external_value(PARAM_INT, 'Total number of votes'),
            'totaltext' => new external_value(PARAM_TEXT, 'Localised, pluralised total-votes label'),
            'hasvotes' => new external_value(PARAM_BOOL, 'Whether there is at least one vote'),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'optionid' => new external_value(PARAM_INT, 'The option\'s id'),
                    'label' => new external_value(PARAM_RAW, 'Option label'),
                    'count' => new external_value(PARAM_INT, 'Votes for this option'),
                    'counttext' => new external_value(PARAM_TEXT, 'Localised, pluralised votes for this option'),
                    'percent' => new external_value(PARAM_INT, 'Percentage of votes for this option'),
                    'selected' => new external_value(PARAM_BOOL, 'Whether this is the user\'s choice'),
                ])
            ),
        ]);
    }
}
