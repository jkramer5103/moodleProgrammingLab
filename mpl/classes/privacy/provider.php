<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.


namespace mod_mpl\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /** Tables with data of students (each has mplid and userid). */
    const TABLES = ['mpl_feedback', 'mpl_submissions', 'mpl_drafts', 'mpl_hints', 'mpl_overrides', 'mpl_examlog'];

    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('mpl_submissions', [
            'userid' => 'privacy:metadata:submissions:userid',
            'code' => 'privacy:metadata:submissions:code',
            'score' => 'privacy:metadata:submissions:score',
            'result' => 'privacy:metadata:submissions:result',
            'timecreated' => 'privacy:metadata:submissions:timecreated',
        ], 'privacy:metadata:submissions');
        $collection->add_database_table('mpl_drafts', [
            'userid' => 'privacy:metadata:drafts:userid',
            'code' => 'privacy:metadata:drafts:code',
            'timemodified' => 'privacy:metadata:drafts:timemodified',
        ], 'privacy:metadata:drafts');
        $collection->add_database_table('mpl_hints', [
            'userid' => 'privacy:metadata:hints:userid',
            'code' => 'privacy:metadata:hints:code',
            'hint' => 'privacy:metadata:hints:hint',
            'followups' => 'privacy:metadata:hints:followups',
            'cost' => 'privacy:metadata:hints:cost',
            'timecreated' => 'privacy:metadata:hints:timecreated',
        ], 'privacy:metadata:hints');
        $collection->add_database_table('mpl_feedback', [
            'userid' => 'privacy:metadata:feedback:userid',
            'comment' => 'privacy:metadata:feedback:comment',
            'authorid' => 'privacy:metadata:feedback:authorid',
            'timemodified' => 'privacy:metadata:feedback:timemodified',
        ], 'privacy:metadata:feedback');
        $collection->add_database_table('mpl_overrides', [
            'userid' => 'privacy:metadata:overrides:userid',
            'duedate' => 'privacy:metadata:overrides:duedate',
            'cutoffdate' => 'privacy:metadata:overrides:cutoffdate',
            'maxattempts' => 'privacy:metadata:overrides:maxattempts',
        ], 'privacy:metadata:overrides');
        $collection->add_database_table('mpl_examlog', [
            'userid' => 'privacy:metadata:examlog:userid',
            'event' => 'privacy:metadata:examlog:event',
            'duration' => 'privacy:metadata:examlog:duration',
            'timecreated' => 'privacy:metadata:examlog:timecreated',
        ], 'privacy:metadata:examlog');
        $collection->add_external_location_link('ai', [
            'code' => 'privacy:metadata:ai:code',
            'context' => 'privacy:metadata:ai:context',
        ], 'privacy:metadata:ai');
        $collection->add_external_location_link('runner', [
            'code' => 'privacy:metadata:runner:code',
            'owner' => 'privacy:metadata:runner:owner',
        ], 'privacy:metadata:runner');
        return $collection;
    }

    /**
     * Contexts with data of a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'mpl'
                 WHERE EXISTS (SELECT 1 FROM {mpl_submissions} s WHERE s.mplid = cm.instance AND s.userid = :u1)
                    OR EXISTS (SELECT 1 FROM {mpl_drafts} d WHERE d.mplid = cm.instance AND d.userid = :u2)
                    OR EXISTS (SELECT 1 FROM {mpl_hints} h WHERE h.mplid = cm.instance AND h.userid = :u3)
                    OR EXISTS (SELECT 1 FROM {mpl_feedback} f WHERE f.mplid = cm.instance AND (f.userid = :u4 OR f.authorid = :u5))
                    OR EXISTS (SELECT 1 FROM {mpl_overrides} o WHERE o.mplid = cm.instance AND o.userid = :u6)
                    OR EXISTS (SELECT 1 FROM {mpl_examlog} e WHERE e.mplid = cm.instance AND e.userid = :u7)";
        $list = new contextlist();
        $list->add_from_sql($sql, ['ctxlevel' => CONTEXT_MODULE, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid,
            'u4' => $userid, 'u5' => $userid, 'u6' => $userid, 'u7' => $userid]);
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid];
        foreach (['mpl_submissions', 'mpl_drafts', 'mpl_hints', 'mpl_feedback', 'mpl_overrides', 'mpl_examlog'] as $table) {
            $sql = "SELECT t.userid FROM {{$table}} t
                      JOIN {course_modules} cm ON cm.instance = t.mplid
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'mpl'
                     WHERE cm.id = :cmid";
            $userlist->add_from_sql('userid', $sql, $params);
        }
    }

    /**
     * Export.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('mpl', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $data = helper::get_context_data($context, $contextlist->get_user());
            helper::export_context_files($context, $contextlist->get_user());
            $subs = $DB->get_records('mpl_submissions', ['mplid' => $cm->instance, 'userid' => $userid], 'attempt');
            $data->submissions = array_values(array_map(function($s) {
                return (object)[
                    'attempt' => $s->attempt,
                    'code' => $s->code,
                    'score' => $s->score,
                    'maxscore' => $s->maxscore,
                    'timecreated' => transform::datetime($s->timecreated),
                ];
            }, $subs));
            $draft = $DB->get_record('mpl_drafts', ['mplid' => $cm->instance, 'userid' => $userid]);
            if ($draft) {
                $data->draft = (object)['code' => $draft->code, 'timemodified' => transform::datetime($draft->timemodified)];
            }
            $hints = $DB->get_records('mpl_hints', ['mplid' => $cm->instance, 'userid' => $userid], 'timecreated');
            $data->hints = array_values(array_map(function($h) {
                return (object)[
                    'hint' => $h->hint,
                    'followups' => json_decode((string)$h->followups, true) ?: [],
                    'cost' => $h->cost,
                    'timecreated' => transform::datetime($h->timecreated),
                ];
            }, $hints));
            $data->feedback = array_values(array_map(function($f) {
                return (object)['line' => $f->line, 'comment' => $f->comment,
                    'timemodified' => transform::datetime($f->timemodified)];
            }, $DB->get_records('mpl_feedback', ['mplid' => $cm->instance, 'userid' => $userid], 'id')));
            $override = $DB->get_record('mpl_overrides', ['mplid' => $cm->instance, 'userid' => $userid]);
            if ($override) {
                $data->override = (object)[
                    'duedate' => $override->duedate ? transform::datetime($override->duedate) : null,
                    'cutoffdate' => $override->cutoffdate ? transform::datetime($override->cutoffdate) : null,
                    'maxattempts' => $override->maxattempts,
                ];
            }
            $data->examlog = array_values(array_map(function($e) {
                return (object)['event' => $e->event, 'detail' => $e->detail, 'duration' => $e->duration,
                    'timecreated' => transform::datetime($e->timecreated)];
            }, $DB->get_records('mpl_examlog', ['mplid' => $cm->instance, 'userid' => $userid], 'id')));
            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        if ($cm = get_coursemodule_from_id('mpl', $context->instanceid)) {
            foreach (self::TABLES as $table) {
                $DB->delete_records($table, ['mplid' => $cm->instance]);
            }
        }
    }

    /**
     * Delete data of one user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module && ($cm = get_coursemodule_from_id('mpl', $context->instanceid))) {
                foreach (self::TABLES as $table) {
                    $DB->delete_records($table, ['mplid' => $cm->instance, 'userid' => $userid]);
                }
            }
        }
    }

    /**
     * Delete data of several users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module || !($cm = get_coursemodule_from_id('mpl', $context->instanceid))) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['mplid'] = $cm->instance;
        foreach (self::TABLES as $table) {
            $DB->delete_records_select($table, "mplid = :mplid AND userid $insql", $params);
        }
    }
}
