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

namespace mod_mpl\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for an individual deadline / number of attempts (a user or a group).
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_form extends \moodleform {

    /** Value of the attempts select for "as in the activity". */
    const INHERIT = -1;

    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $users = $this->_customdata['users'];
        $groups = $this->_customdata['groups'];
        $mpl = $this->_customdata['mpl'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'overrideid');
        $mform->setType('overrideid', PARAM_INT);

        $targets = ['user' => get_string('overridetarget_user', 'mpl')];
        if ($groups) {
            $targets['group'] = get_string('overridetarget_group', 'mpl');
        }
        $mform->addElement('select', 'target', get_string('overridetarget', 'mpl'), $targets);
        $mform->addElement('autocomplete', 'userid', get_string('overrideuser', 'mpl'), [0 => ''] + $users);
        $mform->hideIf('userid', 'target', 'neq', 'user');
        if ($groups) {
            $mform->addElement('select', 'groupid', get_string('overridegroup', 'mpl'), [0 => ''] + $groups);
            $mform->hideIf('groupid', 'target', 'neq', 'group');
        }

        $mform->addElement('static', 'overrideinfo', '', get_string('overrideinfo', 'mpl'));
        $mform->addElement('date_time_selector', 'duedate', get_string('duedate', 'mpl'), ['optional' => true]);
        $mform->addElement('date_time_selector', 'cutoffdate', get_string('cutoffdate', 'mpl'), ['optional' => true]);
        $attempts = [self::INHERIT => get_string('overrideinherit', 'mpl', $mpl->maxattempts ?: get_string('unlimited')),
            0 => get_string('unlimited')];
        for ($i = 1; $i <= 50; $i++) {
            $attempts[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mpl'), $attempts);
        $mform->setDefault('maxattempts', self::INHERIT);
        $this->add_action_buttons(true, get_string('save'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $field = $data['target'] === 'group' ? 'groupid' : 'userid';
        if (empty($data[$field])) {
            $errors[$field] = get_string('required');
        } else {
            $existing = $DB->get_record('mpl_overrides', ['mplid' => $this->_customdata['mpl']->id,
                $field => $data[$field]]);
            if ($existing && $existing->id != $data['overrideid']) {
                $errors[$field] = get_string('overrideexists', 'mpl');
            }
        }
        if (empty($data['duedate']) && empty($data['cutoffdate']) && (int)$data['maxattempts'] === self::INHERIT) {
            $errors['maxattempts'] = get_string('overridenothing', 'mpl');
        }
        if (!empty($data['duedate']) && !empty($data['cutoffdate']) && $data['cutoffdate'] < $data['duedate']) {
            $errors['cutoffdate'] = get_string('cutoffbeforedue', 'mpl');
        }
        return $errors;
    }
}
