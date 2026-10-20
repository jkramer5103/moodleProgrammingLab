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
 * Restore structure.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step of the restore.
 */
class restore_mpl_activity_structure_step extends restore_activity_structure_step {

    /**
     * Paths.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [new restore_path_element('mpl', '/activity/mpl')];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('mpl_submission', '/activity/mpl/submissions/submission');
            $paths[] = new restore_path_element('mpl_draft', '/activity/mpl/drafts/draft');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Activity record.
     *
     * @param array $data
     */
    protected function process_mpl($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->duedate = $this->apply_date_offset($data->duedate);
        $data->cutoffdate = $this->apply_date_offset($data->cutoffdate);
        if ($data->grade < 0) {
            $data->grade = -($this->get_mappingid('scale', abs($data->grade)));
        }
        $newid = $DB->insert_record('mpl', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Submission.
     *
     * @param array $data
     */
    protected function process_mpl_submission($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->mplid = $this->get_new_parentid('mpl');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $newid = $DB->insert_record('mpl_submissions', $data);
        $this->set_mapping('mpl_submission', $oldid, $newid);
    }

    /**
     * Draft.
     *
     * @param array $data
     */
    protected function process_mpl_draft($data) {
        global $DB;
        $data = (object)$data;
        $data->mplid = $this->get_new_parentid('mpl');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $DB->insert_record('mpl_drafts', $data);
    }

    /**
     * Files.
     */
    protected function after_execute() {
        $this->add_related_files('mod_mpl', 'intro', null);
    }
}
