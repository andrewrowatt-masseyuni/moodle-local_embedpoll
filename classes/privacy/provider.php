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

namespace local_embedpoll\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as request_plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_embedpoll\poll;

/**
 * Privacy provider for embedded polls.
 *
 * Polls are course content and name nobody. The only personal data is each user's vote - one per
 * poll - which belongs to the poll's context: its activity's, or its course's for a poll in a
 * section summary.
 *
 * @package    local_embedpoll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, request_plugin_provider {
    /**
     * Describe the types of data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_embedpoll_vote', [
            'pollid' => 'privacy:metadata:local_embedpoll_vote:pollid',
            'userid' => 'privacy:metadata:local_embedpoll_vote:userid',
            'optionid' => 'privacy:metadata:local_embedpoll_vote:optionid',
            'timecreated' => 'privacy:metadata:local_embedpoll_vote:timecreated',
            'timemodified' => 'privacy:metadata:local_embedpoll_vote:timemodified',
        ], 'privacy:metadata:local_embedpoll_vote');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Activities' polls.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_embedpoll_vote} v
               JOIN {local_embedpoll} p ON p.id = v.pollid AND p.cmid > 0
               JOIN {context} ctx ON ctx.instanceid = p.cmid AND ctx.contextlevel = :contextlevel
              WHERE v.userid = :userid",
            ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid]
        );

        // Section summaries' polls.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_embedpoll_vote} v
               JOIN {local_embedpoll} p ON p.id = v.pollid AND p.cmid = 0
               JOIN {context} ctx ON ctx.instanceid = p.courseid AND ctx.contextlevel = :contextlevel
              WHERE v.userid = :userid",
            ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $pollids = self::poll_ids_in($userlist->get_context());
        if (!$pollids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED);
        $userlist->add_from_sql('userid', "SELECT userid FROM {local_embedpoll_vote} WHERE pollid $insql", $params);
    }

    /**
     * Export all user data for the specified approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $pollids = self::poll_ids_in($context);
            if (!$pollids) {
                continue;
            }

            [$insql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $records = $DB->get_records_sql(
                "SELECT v.id, v.pollid, v.optionid, v.timecreated, v.timemodified, p.options
                   FROM {local_embedpoll_vote} v
                   JOIN {local_embedpoll} p ON p.id = v.pollid
                  WHERE v.userid = :userid AND v.pollid $insql",
                $params
            );

            foreach ($records as $record) {
                $labels = array_column(poll::options($record), 'label', 'id');
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_embedpoll'), $record->pollid],
                    (object)[
                        'choice' => $labels[(int)$record->optionid] ?? '',
                        'timecreated' => transform::datetime($record->timecreated),
                        'timemodified' => transform::datetime($record->timemodified),
                    ]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $pollids = self::poll_ids_in($context);
        if ($pollids) {
            [$insql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_embedpoll_vote', "pollid $insql", $params);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $pollids = self::poll_ids_in($context);
            if (!$pollids) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $DB->delete_records_select('local_embedpoll_vote', "userid = :userid AND pollid $insql", $params);
        }
    }

    /**
     * Delete multiple users' data for a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $userids = $userlist->get_userids();
        $pollids = self::poll_ids_in($userlist->get_context());
        if (!$userids || !$pollids) {
            return;
        }

        [$pollsql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED, 'p');
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $DB->delete_records_select('local_embedpoll_vote', "pollid $pollsql AND userid $usersql", $params + $userparams);
    }

    /**
     * The polls whose votes belong to a context: an activity's, or a course's section summaries'.
     *
     * @param \context $context
     * @return int[]
     */
    protected static function poll_ids_in(\context $context): array {
        global $DB;

        if ($context->contextlevel == CONTEXT_MODULE) {
            return $DB->get_fieldset_select('local_embedpoll', 'id', 'cmid = :cmid', ['cmid' => $context->instanceid]);
        }

        if ($context->contextlevel == CONTEXT_COURSE) {
            return $DB->get_fieldset_select(
                'local_embedpoll',
                'id',
                'courseid = :courseid AND cmid = 0',
                ['courseid' => $context->instanceid]
            );
        }

        return [];
    }
}
