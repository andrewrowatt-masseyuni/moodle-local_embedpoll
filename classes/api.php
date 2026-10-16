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

/**
 * Creating, adopting, copying and deleting polls.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var int How long a draft may wait for its activity to be saved before it is purged. */
    public const DRAFT_LIFETIME = DAYSECS;

    /** @var array<int, true> Courses whose section summaries are not to be claimed for now. */
    protected static $heldclaims = [];

    /**
     * Where a poll created from an editor in this context belongs.
     *
     * An editor in a module context - a label, a page, a book chapter, a lesson page, an existing
     * activity's description - makes a poll for that module. An editor in a course context is
     * either a section summary, named by $sectionid, which makes a poll for that section; or a
     * text on the "add an activity" form, where the module does not exist yet, which makes a draft
     * (cm id and section id both 0) that the activity adopts when it is saved.
     *
     * The section id comes from the client, which is why it is checked against the course here.
     *
     * @param \context $context The editor's context.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return int[] [course id, cm id or 0, section id or 0]
     * @throws \invalid_parameter_exception For any other context, or a section not in the course.
     */
    public static function embed_target(\context $context, int $sectionid = 0): array {
        global $DB;

        if ($context instanceof \context_module && !$sectionid) {
            $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);
            return [(int)$cm->course, (int)$cm->id, 0];
        }

        if ($context instanceof \context_course && (int)$context->instanceid !== (int)SITEID) {
            $courseid = (int)$context->instanceid;
            if ($sectionid && !$DB->record_exists('course_sections', ['id' => $sectionid, 'course' => $courseid])) {
                throw new \invalid_parameter_exception('That section is not in this course.');
            }
            return [$courseid, 0, $sectionid];
        }

        throw new \invalid_parameter_exception('A poll can only be embedded in an activity or a section.');
    }

    /**
     * A poll by key, within the context an editor is working in.
     *
     * Always scoped to that context, so a key copied from another activity or section cannot be
     * used to read or edit a poll that belongs somewhere else.
     *
     * @param \context $context The editor's context.
     * @param string $key The poll's key.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return \stdClass|null
     */
    public static function get_embed(\context $context, string $key, int $sectionid = 0): ?\stdClass {
        return self::get_embeds($context, [$key], $sectionid)[$key] ?? null;
    }

    /**
     * Polls by key, within the context an editor is working in: get_embed() for every token in a
     * text at once.
     *
     * @param \context $context The editor's context.
     * @param string[] $keys The polls' keys.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return array<string, \stdClass> Keyed by key. A key with no poll in this context is left out.
     */
    public static function get_embeds(\context $context, array $keys, int $sectionid = 0): array {
        global $DB;

        // Before anything else, so that a context no poll can belong to throws even with no keys.
        [$courseid, $cmid, $sectionid] = self::embed_target($context, $sectionid);
        if (!$keys) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_values($keys), SQL_PARAMS_NAMED);
        $params += ['courseid' => $courseid, 'cmid' => $cmid, 'sectionid' => $sectionid];
        $rows = $DB->get_records_select(
            'local_embedpoll',
            "courseid = :courseid AND cmid = :cmid AND sectionid = :sectionid AND embedkey $insql",
            $params
        );

        $bykey = [];
        foreach ($rows as $row) {
            $bykey[$row->embedkey] = $row;
        }

        return $bykey;
    }

    /**
     * What is wrong with a poll's labels, if anything.
     *
     * @param string[] $labels Trimmed, non-blank labels (see poll::labels_from_text()).
     * @return string|null A message saying what is wrong, or null if nothing is.
     */
    public static function label_error(array $labels): ?string {
        if (count($labels) < poll::MINOPTIONS) {
            return get_string('optionsmin', 'local_embedpoll', poll::MINOPTIONS);
        }

        $seen = [];
        foreach ($labels as $label) {
            if (\core_text::strlen($label) > poll::MAXLENGTH) {
                return get_string('optionstoolong', 'local_embedpoll', (object)[
                    'max' => poll::MAXLENGTH,
                    'option' => shorten_text($label, 40),
                ]);
            }

            // Options that differ only in case would look the same to anyone voting.
            $folded = \core_text::strtolower($label);
            if (isset($seen[$folded])) {
                return get_string('optionsduplicate', 'local_embedpoll', $label);
            }
            $seen[$folded] = true;
        }

        return null;
    }

    /**
     * Create or update a poll.
     *
     * A key that does not match a poll in this context - a token pasted in from another activity,
     * say - gets a new poll with a new key rather than an error. The caller rewrites the token with
     * whatever key comes back.
     *
     * Votes follow an option's text: see poll::merge_options(). The votes for options that are
     * gone are deleted with the change, in one transaction.
     *
     * @param \context $context The editor's context.
     * @param string|null $key The poll to update, or null for a new one.
     * @param string[] $labels The options, trimmed and in display order.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return string The poll's key.
     * @throws \invalid_parameter_exception If the labels are not a valid poll.
     */
    public static function save_embed(\context $context, ?string $key, array $labels, int $sectionid = 0): string {
        global $DB;

        $labels = array_values($labels);
        if ($error = self::label_error($labels)) {
            throw new \invalid_parameter_exception($error);
        }

        [$courseid, $cmid, $sectionid] = self::embed_target($context, $sectionid);
        $now = time();

        $transaction = $DB->start_delegated_transaction();

        $row = ($key !== null && $key !== '') ? self::get_embed($context, $key, $sectionid) : null;
        if (!$row) {
            $row = (object)[
                'courseid' => $courseid,
                'cmid' => $cmid,
                'sectionid' => $sectionid,
                'embedkey' => token::new_key(),
                'options' => '[]',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $row->id = $DB->insert_record('local_embedpoll', $row);
        }

        [$options, $gone] = poll::merge_options(poll::options($row), $labels);
        if ($gone) {
            [$insql, $params] = $DB->get_in_or_equal($gone, SQL_PARAMS_NAMED);
            $params['pollid'] = $row->id;
            $DB->delete_records_select('local_embedpoll_vote', "pollid = :pollid AND optionid $insql", $params);
        }

        $DB->update_record('local_embedpoll', (object)[
            'id' => $row->id,
            'options' => poll::encode_options($options),
            'timemodified' => $now,
        ]);

        $transaction->allow_commit();
        poll::reset_cache();

        return $row->embedkey;
    }

    /**
     * Adopt the drafts whose tokens are in an activity's own text.
     *
     * Called whenever an activity is saved through its settings form. A poll added on the "add an
     * activity" form is made before the activity exists, as a draft in the course; once the
     * activity is saved, this gives it the drafts its text now holds.
     *
     * Every text field of the activity's own record is read, not just its description: a label's
     * text is its description, but a page's is its content, which is on the same form.
     *
     * @param int $cmid The course module.
     */
    public static function adopt_drafts(int $cmid): void {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        if (!$instance) {
            return;
        }

        $keys = [];
        foreach ((array)$instance as $value) {
            if (is_string($value)) {
                array_push($keys, ...token::keys_in($value));
            }
        }
        if (!$keys) {
            return;
        }

        // Section id 0: a section's poll also has cm id 0, and must not be carried off by an
        // activity whose text its token was pasted into.
        [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique($keys)), SQL_PARAMS_NAMED);
        $params['courseid'] = $cm->course;
        $drafts = $DB->get_records_select(
            'local_embedpoll',
            "courseid = :courseid AND cmid = 0 AND sectionid = 0 AND embedkey $insql",
            $params
        );

        foreach ($drafts as $draft) {
            // The activity's own poll with the same key wins; the draft is left for the purge.
            $own = ['courseid' => $cm->course, 'cmid' => $cm->id, 'embedkey' => $draft->embedkey];
            if ($DB->record_exists('local_embedpoll', $own)) {
                continue;
            }
            $DB->update_record('local_embedpoll', (object)['id' => $draft->id, 'cmid' => $cm->id, 'timemodified' => time()]);
        }

        poll::reset_cache();
    }

    /**
     * Give a section its own copy of any poll whose token was copied into its summary from another
     * section.
     *
     * Called whenever a section is updated. Core duplicates a section by copying its summary
     * verbatim (course_format::duplicate_section() - there is no hook), and a teacher can paste one
     * section's token into another. Either way two summaries would share one poll: the filter
     * cannot tell them apart, deleting either section would take the other's poll with it, and
     * votes in one would count in both. So the copy gets a poll of its own - same options, no
     * votes - under a new key, and its summary is rewritten to match.
     *
     * Tokens whose poll is not a section's in this course are left alone: they resolve to nothing,
     * as a token copied between activities does.
     *
     * @param int $sectionid The course section.
     */
    public static function claim_section_summary(int $sectionid): void {
        global $DB;

        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, course, summary');
        if (!$section || isset(self::$heldclaims[(int)$section->course])) {
            return;
        }

        $keys = token::keys_in($section->summary);
        if (!$keys) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $params['courseid'] = $section->course;
        $params['sectionid'] = $sectionid;
        $others = $DB->get_records_select(
            'local_embedpoll',
            "courseid = :courseid AND cmid = 0 AND sectionid > 0 AND sectionid <> :sectionid AND embedkey $insql",
            $params
        );
        if (!$others) {
            return;
        }

        $rekeyed = [];
        foreach ($others as $row) {
            $rekeyed[$row->embedkey] = self::copy_to_section($row, $sectionid);
        }

        // Straight to the table rather than through course_update_section(), which would fire the
        // event that called this. The cache purge is what that would have done.
        $DB->set_field('course_sections', 'summary', token::rekey($section->summary, $rekeyed), ['id' => $sectionid]);
        \course_modinfo::purge_course_section_cache_by_id((int)$section->course, $sectionid);
        rebuild_course_cache((int)$section->course, false, true);
        poll::reset_cache();
    }

    /**
     * Hold off, or resume, claiming section summaries in a course.
     *
     * For restore, which fires the same event while it is still sorting out a section's keys. See
     * restore_local_embedpoll_plugin::define_section_plugin_structure().
     *
     * @param int $courseid The course.
     * @param bool $hold True to hold off, false to resume.
     */
    public static function hold_claims(int $courseid, bool $hold): void {
        if ($hold) {
            self::$heldclaims[$courseid] = true;
        } else {
            unset(self::$heldclaims[$courseid]);
        }
    }

    /**
     * Copy a section's poll into another section of the same course.
     *
     * Votes are not copied: the copy is a new poll.
     *
     * @param \stdClass $row The poll to copy.
     * @param int $sectionid The section the copy belongs to.
     * @return string The copy's key.
     */
    protected static function copy_to_section(\stdClass $row, int $sectionid): string {
        global $DB;

        $now = time();
        $copy = clone $row;
        unset($copy->id);
        $copy->sectionid = $sectionid;
        $copy->embedkey = token::new_key();
        $copy->timecreated = $now;
        $copy->timemodified = $now;
        $DB->insert_record('local_embedpoll', $copy);

        return $copy->embedkey;
    }

    /**
     * Delete every poll belonging to a course module, with its votes.
     *
     * @param int $cmid The course module.
     */
    public static function delete_for_cm(int $cmid): void {
        self::delete_polls('cmid = :cmid', ['cmid' => $cmid]);
    }

    /**
     * Delete a section's polls, or every section's polls in a course, with their votes.
     *
     * Needed when a section is deleted, and when a course's contents are (a restore that deletes
     * the existing content first, say): core deletes those sections without asking anyone.
     *
     * @param int $courseid The course.
     * @param int $sectionid The section, or 0 for every section in the course.
     */
    public static function delete_for_sections(int $courseid, int $sectionid = 0): void {
        $select = 'courseid = :courseid AND cmid = 0 AND sectionid > 0';
        $params = ['courseid' => $courseid];
        if ($sectionid) {
            $select .= ' AND sectionid = :sectionid';
            $params['sectionid'] = $sectionid;
        }

        self::delete_polls($select, $params);
    }

    /**
     * Delete every poll in a course, drafts included, with their votes.
     *
     * Needed because deleting a course removes its modules directly, without the per-module
     * callbacks that delete_for_cm() hangs off.
     *
     * @param int $courseid The course.
     */
    public static function delete_for_course(int $courseid): void {
        self::delete_polls('courseid = :courseid', ['courseid' => $courseid]);
    }

    /**
     * Delete drafts whose activity was never saved.
     *
     * @param int $before Delete drafts last touched before this time.
     * @return int How many were deleted.
     */
    public static function purge_drafts(int $before): int {
        return self::delete_polls('cmid = 0 AND sectionid = 0 AND timemodified < :before', ['before' => $before]);
    }

    /**
     * Delete the polls matching a condition, and every vote cast in them.
     *
     * @param string $select The condition, on local_embedpoll.
     * @param array $params Its parameters.
     * @return int How many polls were deleted.
     */
    protected static function delete_polls(string $select, array $params): int {
        global $DB;

        $ids = $DB->get_fieldset_select('local_embedpoll', 'id', $select, $params);
        if (!$ids) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_embedpoll_vote', "pollid $insql", $inparams);
        $DB->delete_records_select('local_embedpoll', "id $insql", $inparams);
        poll::reset_cache();

        return count($ids);
    }
}
