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


namespace mod_mpl\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Teacher: start the task authoring AI.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class author_start extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Course or module context'),
            'messages' => new external_value(PARAM_RAW, 'Conversation (JSON)'),
            'state' => new external_value(PARAM_RAW, 'Current form state (JSON)'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area with the data files of the form', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $contextid
     * @param string $messages
     * @param string $state
     * @param int $draftitemid
     * @return array
     */
    public static function execute(int $contextid, string $messages, string $state, int $draftitemid = 0): array {
        global $SESSION;
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid,
            'messages' => $messages, 'state' => $state, 'draftitemid' => $draftitemid]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('mod/mpl:addinstance', $context->get_course_context());
        $messages = json_decode($params['messages'], true);
        $state = json_decode($params['state'], true);
        if (!is_array($messages) || !$messages) {
            throw new \invalid_parameter_exception('messages');
        }
        $clean = [];
        foreach (array_slice($messages, -20) as $m) {
            $clean[] = [
                'role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'teacher',
                'content' => \core_text::substr((string)($m['content'] ?? ''), 0, 8000),
            ];
        }
        $state = is_array($state) ? $state : [];
        // Data files: a preview for the AI, the content for running the drafts.
        $stored = \mod_mpl\manager::draft_files($params['draftitemid']);
        $state['files'] = array_map(function($f) {
            return ['name' => $f['name'], 'size' => $f['size'], 'preview' => $f['preview'] ?? '(Binärdatei)'];
        }, \mod_mpl\manager::files_info($stored, false));
        $state['files_data'] = \mod_mpl\manager::files_payload($stored);
        $job = (new \mod_mpl\runner())->author_start($clean, $state,
            strpos(current_language(), 'de') === 0 ? 'de' : 'en');
        if (empty($SESSION->mod_mpl_authorjobs) || !is_array($SESSION->mod_mpl_authorjobs)) {
            $SESSION->mod_mpl_authorjobs = [];
        }
        $SESSION->mod_mpl_authorjobs = array_slice($SESSION->mod_mpl_authorjobs, -9, null, true);
        $SESSION->mod_mpl_authorjobs[$job['id']] = true;
        return ['json' => json_encode($job, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Job status as JSON'),
        ]);
    }
}
