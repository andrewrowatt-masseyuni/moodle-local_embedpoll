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

namespace local_embedpoll\output;

use local_embedpoll\poll;

/**
 * One poll, wherever it appears: on the page, or previewed in the editor.
 *
 * Both render the same template from the same data (poll::export_for_template()), so the editor
 * shows the poll as the page will - with results, for a teacher who may see them - except that the
 * preview can never be voted in.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class widget {
    /**
     * Render a poll for the current user, or nothing if it has no options.
     *
     * The output never contains the token attribute, which is what makes a second filter pass over
     * already-filtered text a no-op. mod_lesson does exactly that to page contents shown as
     * feedback.
     *
     * @param \stdClass $row The local_embedpoll row.
     * @return string HTML.
     */
    public static function render_row(\stdClass $row): string {
        global $OUTPUT, $USER;

        if (!poll::options($row)) {
            return '';
        }

        return $OUTPUT->render_from_template('local_embedpoll/poll', poll::export_for_template($row, (int)$USER->id));
    }

    /**
     * Render a poll as the editor previews it.
     *
     * Unlike render_row(), never renders nothing. The preview is what a teacher clicks to edit the
     * poll, so a token with no poll here says so instead.
     *
     * @param \stdClass|null $row The local_embedpoll row, or null for a token with no poll in this context.
     * @return string HTML.
     */
    public static function render_preview(?\stdClass $row): string {
        global $OUTPUT, $USER;

        if (!$row || !poll::options($row)) {
            // Dressed as filter_embedpoll's own notice, which is what a teacher will see on the page.
            return \html_writer::div(
                get_string('previewnotfound', 'local_embedpoll'),
                'alert alert-warning embedpoll-notfound',
                ['role' => 'note']
            );
        }

        return $OUTPUT->render_from_template('local_embedpoll/poll', poll::export_for_template($row, (int)$USER->id, true));
    }
}
