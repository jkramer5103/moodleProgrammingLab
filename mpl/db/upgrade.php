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

    return true;
}
