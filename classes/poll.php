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
 * Finds polls, and is the single source of truth for their options, votes and results.
 *
 * The filter (initial render), the vote web service (re-render after a vote) and the editor's
 * preview all export a poll through export_for_template(), so results are resolved identically
 * in all three.
 *
 * Each option has an id of the poll's own, and a vote names the option by that id rather than by
 * its position. So reordering options, or adding one, keeps every vote; rewording or removing an
 * option discards only the votes cast for it (see merge_options()).
 *
 * Rows are read a whole course at a time - activities' and sections' polls together - and held for
 * the request. The filter asks about one activity or section summary at a time, and a course page
 * may run it over a dozen of each; paying one query per course keeps that flat.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll {
    /** @var string Capability required to cast or change a vote. */
    public const CAP_VOTE = 'local/embedpoll:vote';

    /** @var string Capability granting permanent visibility of results. */
    public const CAP_VIEWRESULTS = 'local/embedpoll:viewresults';

    /** @var string Capability required to add, edit, move and delete polls. */
    public const CAP_MANAGE = 'local/embedpoll:manage';

    /** @var int The fewest options a poll may have. */
    public const MINOPTIONS = 2;

    /** @var int The longest an option's label may be, in characters. */
    public const MAXLENGTH = 255;

    /** @var string Show each option's share of the votes, as a percentage. */
    public const STATS_PERCENT = 'percent';

    /** @var string Show how many votes each option has. */
    public const STATS_COUNT = 'count';

    /** @var string Show no figure beside each option. */
    public const STATS_NONE = 'none';

    /** @var string[] What a poll may show beside each option once results are revealed. */
    public const STATS = [self::STATS_PERCENT, self::STATS_COUNT, self::STATS_NONE];

    /** @var string The columns every lookup returns. */
    protected const FIELDS = 'id, courseid, cmid, sectionid, embedkey, options, compact, stats';

    /**
     * Rows keyed by course id, then 'cms' => cm id => key, and 'sections' => key.
     *
     * @var array<int, array{cms: array<int, array<string, \stdClass>>, sections: array<string, \stdClass>}>
     */
    protected static $cache = [];

    /**
     * Every poll in a course's activities, keyed by cm id and then by key.
     *
     * @param int $courseid The course.
     * @return array<int, array<string, \stdClass>>
     */
    public static function for_course(int $courseid): array {
        return self::load($courseid)['cms'];
    }

    /**
     * One activity's polls, keyed by key.
     *
     * @param int $courseid The activity's course. Passed in rather than looked up because every
     *     caller already has it, and a lookup here would cost the query this class exists to save.
     * @param int $cmid The course module.
     * @return array<string, \stdClass>
     */
    public static function for_cm(int $courseid, int $cmid): array {
        return self::for_course($courseid)[$cmid] ?? [];
    }

    /**
     * Every poll in a course's section summaries, keyed by key.
     *
     * Keyed by course rather than by section because that is all the filter knows: a section
     * summary is formatted in its course's context, which says nothing of which section it is.
     * api::claim_section_summary() is what keeps a section from showing another's poll.
     *
     * @param int $courseid The course.
     * @return array<string, \stdClass>
     */
    public static function for_sections(int $courseid): array {
        return self::load($courseid)['sections'];
    }

    /**
     * Read a course's polls, drafts aside, once per request.
     *
     * @param int $courseid The course.
     * @return array{cms: array<int, array<string, \stdClass>>, sections: array<string, \stdClass>}
     */
    protected static function load(int $courseid): array {
        global $DB;

        if (isset(self::$cache[$courseid])) {
            return self::$cache[$courseid];
        }

        $rows = $DB->get_records_select(
            'local_embedpoll',
            'courseid = :courseid AND (cmid > 0 OR sectionid > 0)',
            ['courseid' => $courseid],
            'id',
            self::FIELDS
        );

        $polls = ['cms' => [], 'sections' => []];
        foreach ($rows as $row) {
            if ((int)$row->cmid > 0) {
                $polls['cms'][(int)$row->cmid][$row->embedkey] = $row;
            } else {
                $polls['sections'][$row->embedkey] = $row;
            }
        }

        return self::$cache[$courseid] = $polls;
    }

    /**
     * Forget what was read for this request.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }

    /**
     * The context a poll belongs to: its activity's, or for a section's poll or a draft, its course's.
     *
     * @param \stdClass $row A local_embedpoll row.
     * @return \context
     */
    public static function context_for(\stdClass $row): \context {
        if ((int)$row->cmid > 0) {
            return \context_module::instance((int)$row->cmid);
        }

        return \context_course::instance((int)$row->courseid);
    }

    /**
     * A poll's options, in display order.
     *
     * @param \stdClass $row A local_embedpoll row.
     * @return array[] Each ['id' => int, 'label' => string].
     */
    public static function options(\stdClass $row): array {
        $decoded = json_decode((string)($row->options ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        foreach ($decoded as $option) {
            if (is_array($option) && isset($option['id'], $option['label']) && (int)$option['id'] > 0) {
                $options[] = ['id' => (int)$option['id'], 'label' => (string)$option['label']];
            }
        }

        return $options;
    }

    /**
     * Options as stored.
     *
     * @param array[] $options Each ['id' => int, 'label' => string], in display order.
     * @return string JSON.
     */
    public static function encode_options(array $options): string {
        return json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
    }

    /**
     * The labels in a piece of text written one option per line: trimmed, blank lines dropped.
     *
     * @param string $text The text.
     * @return string[]
     */
    public static function labels_from_text(string $text): array {
        $labels = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $labels[] = $line;
            }
        }

        return $labels;
    }

    /**
     * Give a poll new labels, keeping the id of every option whose text is unchanged.
     *
     * An option keeps its id - and so its votes - for as long as its text is exactly the same,
     * wherever it moves to. A label that is new, or reworded, gets a new id, higher than any the
     * poll has used, so a vote can never come to name a different option.
     *
     * @param array[] $existing The poll's options now, each ['id' => int, 'label' => string].
     * @param string[] $labels The new labels, in display order.
     * @return array [the new options, as options() returns them; the ids of the options that are gone]
     */
    public static function merge_options(array $existing, array $labels): array {
        $ids = [];
        $next = 1;
        foreach ($existing as $option) {
            $ids[$option['label']] = $option['id'];
            $next = max($next, $option['id'] + 1);
        }

        $options = [];
        foreach ($labels as $label) {
            if (isset($ids[$label])) {
                $id = $ids[$label];
                unset($ids[$label]);
            } else {
                $id = $next++;
            }
            $options[] = ['id' => $id, 'label' => $label];
        }

        return [$options, array_values($ids)];
    }

    /**
     * How many votes a poll has.
     *
     * @param \stdClass $row A local_embedpoll row.
     * @return int
     */
    public static function count_votes(\stdClass $row): int {
        global $DB;

        return $DB->count_records('local_embedpoll_vote', ['pollid' => $row->id]);
    }

    /**
     * Record (or change) a user's vote.
     *
     * @param \stdClass $row A local_embedpoll row.
     * @param int $userid The user.
     * @param int $optionid The id of the chosen option.
     * @throws \moodle_exception If the poll has no such option.
     */
    public static function record_vote(\stdClass $row, int $userid, int $optionid): void {
        global $DB;

        if (!in_array($optionid, array_column(self::options($row), 'id'), true)) {
            throw new \moodle_exception('error_invalidchoice', 'local_embedpoll');
        }

        $now = time();
        $existing = $DB->get_record('local_embedpoll_vote', ['pollid' => $row->id, 'userid' => $userid]);

        if ($existing) {
            if ((int)$existing->optionid !== $optionid) {
                $existing->optionid = $optionid;
                $existing->timemodified = $now;
                $DB->update_record('local_embedpoll_vote', $existing);
            }
            return;
        }

        $DB->insert_record('local_embedpoll_vote', (object)[
            'pollid' => $row->id,
            'userid' => $userid,
            'optionid' => $optionid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Build the mustache context for rendering a poll for one user.
     *
     * Results (counts/percentages) are only ever populated when they are allowed to be revealed
     * to this user, so a student who has not voted never receives the tallies over the wire.
     *
     * The editor's preview shows the poll as the page shows it to the same user, but can never be
     * voted in: its buttons are disabled, which also keeps them out of the tab order.
     *
     * A compact poll shows its options side by side, on one line, rather than one per line. Once
     * results are revealed, each option shows its percentage, its number of votes, or neither, as
     * the poll's stats setting says. Neither changes what is sent: the progress bars and the total
     * still need the tally.
     *
     * @param \stdClass $row A local_embedpoll row.
     * @param int $userid The user.
     * @param bool $preview For the editor's preview rather than the page.
     * @return array Template context.
     */
    public static function export_for_template(\stdClass $row, int $userid, bool $preview = false): array {
        global $DB;

        $context = self::context_for($row);
        $options = self::options($row);

        $canvote = !$preview && has_capability(self::CAP_VOTE, $context, $userid);
        $canviewresults = has_capability(self::CAP_VIEWRESULTS, $context, $userid);

        $voterecord = $DB->get_record('local_embedpoll_vote', ['pollid' => $row->id, 'userid' => $userid]);
        $hasvoted = (bool)$voterecord;
        $myvote = $hasvoted ? (int)$voterecord->optionid : 0;

        $showresults = $hasvoted || $canviewresults;
        $stats = $showresults ? $row->stats : self::STATS_NONE;

        // Aggregate the tally, ignoring any votes for options the poll no longer has.
        $counts = array_fill_keys(array_column($options, 'id'), 0);
        $total = 0;
        if ($showresults) {
            $rows = $DB->get_records_sql(
                "SELECT optionid, COUNT(1) AS num
                   FROM {local_embedpoll_vote}
                  WHERE pollid = :pollid
               GROUP BY optionid",
                ['pollid' => $row->id]
            );
            foreach ($rows as $tally) {
                $optionid = (int)$tally->optionid;
                if (array_key_exists($optionid, $counts)) {
                    $counts[$optionid] = (int)$tally->num;
                    $total += (int)$tally->num;
                }
            }
        }

        $items = [];
        foreach ($options as $option) {
            $count = $counts[$option['id']];
            $items[] = [
                'optionid' => $option['id'],
                'label' => $option['label'],
                'count' => $count,
                'counttext' => self::votes_text($count),
                'percent' => $total > 0 ? (int)round(($count / $total) * 100) : 0,
                'selected' => $option['id'] === $myvote,
            ];
        }

        return [
            'pollid' => (int)$row->id,
            'contextid' => (int)$context->id,
            'compact' => (bool)$row->compact,
            'canvote' => $canvote,
            'canviewresults' => $canviewresults,
            'hasvoted' => $hasvoted,
            'showresults' => $showresults,
            'showpercent' => $stats === self::STATS_PERCENT,
            'showcount' => $stats === self::STATS_COUNT,
            'total' => $total,
            'totaltext' => self::votes_text($total),
            'hasvotes' => $total > 0,
            'items' => $items,
        ];
    }

    /**
     * A number of votes, in words: "1 vote", "5 votes".
     *
     * @param int $count The number of votes.
     * @return string
     */
    protected static function votes_text(int $count): string {
        return get_string($count === 1 ? 'votecount' : 'votecountplural', 'local_embedpoll', $count);
    }
}
