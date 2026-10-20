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
 * Backup structure.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step of the backup.
 */
class backup_mpl_activity_structure_step extends backup_activity_structure_step {

    /**
     * Structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $mpl = new backup_nested_element('mpl', ['id'], ['name', 'intro', 'introformat', 'starter', 'reference', 'tests', 'allowrun', 'allowcheck', 'checkgraded', 'maxattempts', 'grademethod', 'duedate', 'cutoffdate', 'timeout', 'memory', 'grade', 'timecreated', 'timemodified', 'aihints', 'aifree', 'aicost', 'aimax']);
        $submissions = new backup_nested_element('submissions');
        $submission = new backup_nested_element('submission', ['id'], ['userid', 'attempt', 'code', 'score', 'maxscore',
            'fraction', 'status', 'result', 'timecreated']);
        $drafts = new backup_nested_element('drafts');
        $draft = new backup_nested_element('draft', ['id'], ['userid', 'code', 'timemodified']);

        $mpl->add_child($submissions);
        $submissions->add_child($submission);
        $mpl->add_child($drafts);
        $drafts->add_child($draft);
        $hints = new backup_nested_element('hints');
        $hint = new backup_nested_element('hint', ['id'], ['userid', 'kind', 'title', 'code', 'context', 'hint',
            'followups', 'cost', 'timecreated']);
        $mpl->add_child($hints);
        $hints->add_child($hint);

        $mpl->set_source_table('mpl', ['id' => backup::VAR_ACTIVITYID]);
        if ($userinfo) {
            $submission->set_source_table('mpl_submissions', ['mplid' => backup::VAR_PARENTID], 'id ASC');
            $draft->set_source_table('mpl_drafts', ['mplid' => backup::VAR_PARENTID], 'id ASC');
            $hint->set_source_table('mpl_hints', ['mplid' => backup::VAR_PARENTID], 'id ASC');
        }
        $submission->annotate_ids('user', 'userid');
        $draft->annotate_ids('user', 'userid');
        $hint->annotate_ids('user', 'userid');
        $mpl->annotate_files('mod_mpl', 'intro', null);

        return $this->prepare_activity_structure($mpl);
    }
}
