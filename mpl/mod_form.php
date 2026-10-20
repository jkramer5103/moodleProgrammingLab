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
 * Activity settings form.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/mpl/lib.php');

/**
 * Settings form of a Programming Lab.
 */
class mod_mpl_mod_form extends moodleform_mod {

    /**
     * Form definition.
     */
    public function definition() {
        global $PAGE;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements(get_string('taskdescription', 'mpl'));

        // Code.
        $mform->addElement('header', 'codeheader', get_string('codeheader', 'mpl'));
        $mform->setExpanded('codeheader');
        $mform->addElement('textarea', 'starter', get_string('starter', 'mpl'),
            ['rows' => 8, 'class' => 'mpl-code-textarea', 'data-mpl-code' => 'starter', 'spellcheck' => 'false']);
        $mform->setType('starter', PARAM_RAW);
        $mform->addHelpButton('starter', 'starter', 'mpl');
        $mform->addElement('textarea', 'reference', get_string('reference', 'mpl'),
            ['rows' => 14, 'class' => 'mpl-code-textarea', 'data-mpl-code' => 'reference', 'spellcheck' => 'false']);
        $mform->setType('reference', PARAM_RAW);
        $mform->addHelpButton('reference', 'reference', 'mpl');

        // Tests (edited with the visual test builder, stored as JSON).
        $mform->addElement('header', 'testsheader', get_string('testsheader', 'mpl'));
        $mform->setExpanded('testsheader');
        $mform->addElement('static', 'testsintro', '', get_string('testsintro', 'mpl'));
        $mform->addElement('textarea', 'tests', get_string('testsjson', 'mpl'),
            ['rows' => 6, 'class' => 'mpl-tests-json', 'spellcheck' => 'false']);
        $mform->setType('tests', PARAM_RAW);
        $mform->addElement('html', '<div class="mpl-testbuilder" data-region="mpl-testbuilder"></div>');

        // Workflow.
        $mform->addElement('header', 'workflowheader', get_string('workflowheader', 'mpl'));
        $mform->addElement('advcheckbox', 'allowrun', get_string('allowrun', 'mpl'));
        $mform->addHelpButton('allowrun', 'allowrun', 'mpl');
        $mform->setDefault('allowrun', 1);
        $mform->addElement('advcheckbox', 'allowcheck', get_string('allowcheck', 'mpl'));
        $mform->addHelpButton('allowcheck', 'allowcheck', 'mpl');
        $mform->setDefault('allowcheck', 1);
        $mform->addElement('advcheckbox', 'checkgraded', get_string('checkgraded', 'mpl'));
        $mform->addHelpButton('checkgraded', 'checkgraded', 'mpl');
        $mform->disabledIf('checkgraded', 'allowcheck');

        $attempts = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 50; $i++) {
            $attempts[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mpl'), $attempts);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mpl');
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'mpl'), [
            MPL_GRADE_BEST => get_string('grademethodbest', 'mpl'),
            MPL_GRADE_LAST => get_string('grademethodlast', 'mpl'),
        ]);
        $mform->addElement('date_time_selector', 'duedate', get_string('duedate', 'mpl'), ['optional' => true]);
        $mform->addHelpButton('duedate', 'duedate', 'mpl');
        $mform->addElement('date_time_selector', 'cutoffdate', get_string('cutoffdate', 'mpl'), ['optional' => true]);
        $mform->addHelpButton('cutoffdate', 'cutoffdate', 'mpl');

        // Limits.
        $mform->addElement('header', 'limitsheader', get_string('limitsheader', 'mpl'));
        $mform->addElement('text', 'timeout', get_string('timeout', 'mpl'), ['size' => 5]);
        $mform->setType('timeout', PARAM_INT);
        $mform->setDefault('timeout', (int)(get_config('mod_mpl', 'defaulttimeout') ?: 30));
        $mform->addHelpButton('timeout', 'timeout', 'mpl');
        $mform->addElement('text', 'memory', get_string('memory', 'mpl'), ['size' => 5]);
        $mform->setType('memory', PARAM_INT);
        $mform->setDefault('memory', (int)(get_config('mod_mpl', 'defaultmemory') ?: 512));
        $mform->addHelpButton('memory', 'memory', 'mpl');

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();

        $PAGE->requires->js_call_amd('mod_mpl/testbuilder', 'init', [$this->context->id]);
    }

    /**
     * Defaults for a new activity.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        if (empty($defaultvalues['tests'])) {
            $defaultvalues['tests'] = json_encode(['version' => 1, 'tests' => []]);
        }
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $decoded = json_decode((string)($data['tests'] ?? ''), true);
        if (!is_array($decoded)) {
            $errors['tests'] = get_string('testsinvalid', 'mpl');
        } else {
            $tests = \mod_mpl\manager::decode_tests($data['tests']);
            $needsref = false;
            foreach ($tests as $t) {
                $type = $t['type'] ?? '';
                if ($type === 'plot' || ($type !== 'code' && ($t['expected']['source'] ?? 'reference') === 'reference')) {
                    $needsref = true;
                }
            }
            if ($needsref && trim((string)$data['reference']) === '') {
                $errors['reference'] = get_string('referencerequired', 'mpl');
            }
        }
        if (!empty($data['duedate']) && !empty($data['cutoffdate']) && $data['cutoffdate'] < $data['duedate']) {
            $errors['cutoffdate'] = get_string('cutoffbeforedue', 'mpl');
        }
        if ((int)$data['timeout'] < 1 || (int)$data['timeout'] > 120) {
            $errors['timeout'] = get_string('timeoutrange', 'mpl');
        }
        if ((int)$data['memory'] < 64 || (int)$data['memory'] > 4096) {
            $errors['memory'] = get_string('memoryrange', 'mpl');
        }
        return $errors;
    }
}
