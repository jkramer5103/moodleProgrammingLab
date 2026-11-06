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


/**
 * Upgrade steps for mod_mpl.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_mpl_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102100) {
        // AI hints: activity settings.
        $table = new xmldb_table('mpl');
        $fields = [
            new xmldb_field('aihints', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'grade'),
            new xmldb_field('aifree', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'aihints'),
            new xmldb_field('aicost', XMLDB_TYPE_NUMBER, '6, 2', null, XMLDB_NOTNULL, null, '5', 'aifree'),
            new xmldb_field('aimax', XMLDB_TYPE_NUMBER, '6, 2', null, XMLDB_NOTNULL, null, '35', 'aicost'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // AI hints given to students.
        $table = new xmldb_table('mpl_hints');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mplid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'test');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('code', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('context', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('hint', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('followups', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('cost', XMLDB_TYPE_NUMBER, '6, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('mplid', XMLDB_KEY_FOREIGN, ['mplid'], 'mpl', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('mpluser', XMLDB_INDEX_NOTUNIQUE, ['mplid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026102100, 'mpl');
    }

    if ($oldversion < 2026102200) {
        // Conversation with the task authoring AI.
        $table = new xmldb_table('mpl');
        $field = new xmldb_field('authorchat', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aimax');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026102200, 'mpl');
    }

    if ($oldversion < 2026102300) {
        // Exam mode and Safe Exam Browser.
        $table = new xmldb_table('mpl');
        $fields = [
            new xmldb_field('exammode', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'authorchat'),
            new xmldb_field('sebmode', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'exammode'),
            new xmldb_field('sebkeys', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sebmode'),
            new xmldb_field('sebquitpassword', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sebkeys'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Regrading.
        $table = new xmldb_table('mpl_submissions');
        $fields = [
            new xmldb_field('testshash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'result'),
            new xmldb_field('timeregraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'testshash'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Individual deadlines and attempts.
        $table = new xmldb_table('mpl_overrides');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mplid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cutoffdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('maxattempts', XMLDB_TYPE_INTEGER, '6', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('mplid', XMLDB_KEY_FOREIGN, ['mplid'], 'mpl', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'groups', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Teacher feedback.
        $table = new xmldb_table('mpl_feedback');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mplid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('line', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('mplid', XMLDB_KEY_FOREIGN, ['mplid'], 'mpl', ['id']);
        $table->add_key('submissionid', XMLDB_KEY_FOREIGN, ['submissionid'], 'mpl_submissions', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('authorid', XMLDB_KEY_FOREIGN, ['authorid'], 'user', ['id']);
        $table->add_index('mpluser', XMLDB_INDEX_NOTUNIQUE, ['mplid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Exam log.
        $table = new xmldb_table('mpl_examlog');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mplid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('event', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('detail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('duration', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('mplid', XMLDB_KEY_FOREIGN, ['mplid'], 'mpl', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('mpluser', XMLDB_INDEX_NOTUNIQUE, ['mplid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026102300, 'mpl');
    }

    return true;
}
