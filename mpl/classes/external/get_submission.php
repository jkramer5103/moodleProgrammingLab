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
 * A graded attempt (own attempts, or any for teachers).
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submission extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'submissionid' => new external_value(PARAM_INT, 'Submission id'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param int $submissionid
     * @return array
     */
    public static function execute(int $cmid, int $submissionid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'submissionid' => $submissionid]);
        $manager = manager::from_cmid($params['cmid']);
        self::validate_context($manager->context);
        require_capability('mod/mpl:view', $manager->context);
        global $DB;
        $sub = $DB->get_record('mpl_submissions', ['id' => $params['submissionid'], 'mplid' => $manager->mpl->id],
            '*', MUST_EXIST);
        if ($sub->userid != $USER->id) {
            require_capability('mod/mpl:grade', $manager->context);
        }
        $result = json_decode((string)$sub->result, true) ?: [];
        $out = manager::filter_result($result, $manager->can_grade());
        $out['mode'] = 'submit';
        $out['attempt'] = (int)$sub->attempt;
        $out['submissionid'] = (int)$sub->id;
        $out['timecreated'] = (int)$sub->timecreated;
        $out['grade'] = $manager->format_grade((float)$sub->fraction);
        $out['code'] = (string)$sub->code;
        $out['feedback'] = $manager->get_feedback((int)$sub->id);
        return ['json' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Result as JSON'),
        ]);
    }
}
