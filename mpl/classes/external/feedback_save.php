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
use mod_mpl\manager;

/**
 * Teacher: write, change or delete a comment on a submission.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_save extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'submissionid' => new external_value(PARAM_INT, 'Submission id'),
            'feedbackid' => new external_value(PARAM_INT, 'Comment id, 0 = new'),
            'line' => new external_value(PARAM_INT, 'Line (1-based), 0 = general comment'),
            'comment' => new external_value(PARAM_RAW, 'Text (empty = delete)'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param int $submissionid
     * @param int $feedbackid
     * @param int $line
     * @param string $comment
     * @return array
     */
    public static function execute(int $cmid, int $submissionid, int $feedbackid, int $line, string $comment): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'submissionid' => $submissionid,
            'feedbackid' => $feedbackid, 'line' => $line, 'comment' => $comment]);
        $manager = manager::from_cmid($params['cmid']);
        self::validate_context($manager->context);
        require_capability('mod/mpl:grade', $manager->context);
        $comment = clean_param($params['comment'], PARAM_TEXT);
        $feedback = $manager->save_feedback($params['submissionid'], $params['feedbackid'], $params['line'], $comment);
        return ['json' => json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'json' => new external_value(PARAM_RAW, 'All comments of the submission as JSON'),
        ]);
    }
}
