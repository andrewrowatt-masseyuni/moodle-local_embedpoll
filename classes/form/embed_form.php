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

namespace local_embedpoll\form;

use core_form\dynamic_form;
use local_embedpoll\api;
use local_embedpoll\poll;

/**
 * Write the options of one embedded poll.
 *
 * Opened by tiny_embedpoll in a modal, to add a poll or to edit one already in the text.
 *
 * The options are written one per line. An option keeps its votes for as long as its text is
 * unchanged, wherever it moves to; rewording or removing an option discards the votes cast for it.
 * A poll that already has votes opens with a notice saying so. Ticking "Compact" shows the options
 * side by side, on one line, rather than one per line. "Results for each option" picks what each
 * option shows once results are revealed: its percentage, its number of votes, or nothing.
 *
 * Arguments: contextid (the editor's context), and optionally sectionid (the section whose summary
 * is being edited, in a course context) and key (the poll being edited).
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embed_form extends dynamic_form {
    /** @var \stdClass|null|false The poll being edited, null for none, or false before it is looked up. */
    protected $row = false;

    /**
     * The editor's context: an activity, or the course for a section summary or while an activity
     * is being added.
     *
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        $context = \context::instance_by_id($this->optional_param('contextid', 0, PARAM_INT));
        // Throws for any context a poll cannot belong to, or a section not in the course.
        api::embed_target($context, $this->get_sectionid());

        return $context;
    }

    /**
     * The section whose summary is being edited, or 0.
     *
     * @return int
     */
    protected function get_sectionid(): int {
        return $this->optional_param('sectionid', 0, PARAM_INT);
    }

    /**
     * The poll being edited, if there is one here.
     *
     * @return \stdClass|null
     */
    protected function get_row(): ?\stdClass {
        if ($this->row === false) {
            $key = $this->optional_param('key', '', PARAM_ALPHANUM);
            $this->row = $key !== ''
                ? api::get_embed($this->get_context_for_dynamic_submission(), $key, $this->get_sectionid())
                : null;
        }

        return $this->row;
    }

    /**
     * Only people who may manage polls may write them.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability(poll::CAP_MANAGE, $this->get_context_for_dynamic_submission());
    }

    /**
     * The page this form is notionally on.
     *
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return $this->get_context_for_dynamic_submission()->get_url();
    }

    /**
     * The form.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'contextid');
        $mform->setType('contextid', PARAM_INT);

        $mform->addElement('hidden', 'sectionid');
        $mform->setType('sectionid', PARAM_INT);

        $mform->addElement('hidden', 'key');
        $mform->setType('key', PARAM_ALPHANUM);

        $row = $this->get_row();
        $votes = $row ? poll::count_votes($row) : 0;
        if ($votes) {
            $mform->addElement('html', \html_writer::div(
                get_string($votes === 1 ? 'votesnotice' : 'votesnoticeplural', 'local_embedpoll', $votes),
                'alert alert-warning',
                ['data-region' => 'embedpoll-votesnotice']
            ));
        }

        // The help is written under the label rather than behind a help button, so it is read
        // without a click. Behat therefore finds the field by its name, "options".
        $label = get_string('options', 'local_embedpoll') . \html_writer::span(
            get_string('options_inlinehelp', 'local_embedpoll'),
            'd-block small text-muted font-weight-normal mb-1'
        );
        $mform->addElement('textarea', 'options', $label, ['rows' => 8, 'cols' => 50]);
        $mform->setType('options', PARAM_TEXT);
        $mform->addRule('options', null, 'required', null, 'client');

        $mform->addElement(
            'advcheckbox',
            'compact',
            get_string('compact', 'local_embedpoll'),
            get_string('compact_inlinehelp', 'local_embedpoll')
        );
        $mform->setDefault('compact', 0);

        $stats = [];
        foreach (poll::STATS as $value) {
            $stats[$value] = get_string('stats_' . $value, 'local_embedpoll');
        }
        $mform->addElement('select', 'stats', get_string('stats', 'local_embedpoll'), $stats);
        $mform->setDefault('stats', poll::STATS_PERCENT);
    }

    /**
     * Validation.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Errors, keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $error = api::label_error(poll::labels_from_text((string)($data['options'] ?? '')));
        if ($error !== null) {
            $errors['options'] = $error;
        }

        return $errors;
    }

    /**
     * Load the poll being edited.
     */
    public function set_data_for_dynamic_submission(): void {
        $row = $this->get_row();

        $this->set_data([
            'contextid' => $this->get_context_for_dynamic_submission()->id,
            'sectionid' => $this->get_sectionid(),
            'key' => $row ? $row->embedkey : '',
            'options' => $row ? implode("\n", array_column(poll::options($row), 'label')) : '',
            'compact' => $row ? (int)$row->compact : 0,
            'stats' => $row ? $row->stats : poll::STATS_PERCENT,
        ]);
    }

    /**
     * Save the poll.
     *
     * @return array ['key' => the poll's key, for the editor to put in the token]
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();

        $key = api::save_embed(
            $this->get_context_for_dynamic_submission(),
            $data->key !== '' ? $data->key : null,
            poll::labels_from_text((string)$data->options),
            $this->get_sectionid(),
            !empty($data->compact),
            $data->stats
        );

        return ['key' => $key];
    }
}
