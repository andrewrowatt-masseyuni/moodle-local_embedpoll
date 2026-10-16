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
use local_embedpoll\api;
use local_embedpoll\output\widget;
use local_embedpoll\poll;

/**
 * Render polls as the page shows them, for the editor to preview.
 *
 * The editor asks for every token in its text at once, and puts what comes back beside each token
 * rather than in it (see tiny_embedpoll/previews), so none of it is ever saved into the text.
 *
 * Polls are looked up as the poll form looks them up - within the editor's context, by
 * api::get_embeds() - so a token copied in from elsewhere previews as the notice the filter will
 * show there, and a draft on the "add an activity" form previews at all.
 *
 * Anyone the editor plugin is offered to gets previews: a non-editing teacher who cannot edit a
 * poll may still edit the text around it. Drafts are the exception, and need manage: they are
 * only ever seen by whoever is writing them.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_previews extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context of the editor the polls are embedded in'),
            'keys' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUM, 'A poll\'s key, from its token')
            ),
            'sectionid' => new external_value(
                PARAM_INT,
                'The section whose summary the polls are embedded in, for an editor in a course context',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Render the previews.
     *
     * @param int $contextid The editor's context.
     * @param string[] $keys The polls' keys.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return array One preview per distinct key, in the order asked.
     */
    public static function execute(int $contextid, array $keys, int $sectionid = 0): array {
        ['contextid' => $contextid, 'keys' => $keys, 'sectionid' => $sectionid] = self::validate_parameters(
            self::execute_parameters(),
            ['contextid' => $contextid, 'keys' => $keys, 'sectionid' => $sectionid]
        );

        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        [, $cmid, $sectionid] = api::embed_target($context, $sectionid);
        if (!$cmid && !$sectionid) {
            require_capability(poll::CAP_MANAGE, $context);
        } else if (!has_any_capability([poll::CAP_MANAGE, poll::CAP_VIEWRESULTS], $context)) {
            throw new \required_capability_exception($context, poll::CAP_MANAGE, 'nopermissions', '');
        }

        $keys = array_values(array_unique($keys));
        $rows = api::get_embeds($context, $keys, $sectionid);

        $previews = [];
        foreach ($keys as $key) {
            $previews[] = [
                'key' => $key,
                'html' => widget::render_preview($rows[$key] ?? null),
            ];
        }

        return $previews;
    }

    /**
     * Return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUM, 'The poll\'s key'),
                'html' => new external_value(PARAM_RAW, 'The poll as the page shows it, or a notice saying why it will not show'),
            ])
        );
    }
}
