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
 * Teacher: data files in the draft area of the settings form.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class datafile_list extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Course or module context'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id of the form'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $contextid
     * @param int $draftitemid
     * @return array
     */
    public static function execute(int $contextid, int $draftitemid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid,
            'draftitemid' => $draftitemid]);
        self::check_teacher($params['contextid']);
        $files = manager::files_info(manager::draft_files($params['draftitemid']), false);
        return ['json' => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /**
     * The user may edit activities in the course of the context.
     *
     * @param int $contextid
     */
    public static function check_teacher(int $contextid): void {
        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        require_capability('mod/mpl:addinstance', $context->get_course_context());
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Files as JSON'),
        ]);
    }
}
