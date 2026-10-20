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
 * Teacher: status/result of a task authoring job.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class author_poll extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Course or module context'),
            'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Job id'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $contextid
     * @param string $jobid
     * @return array
     */
    public static function execute(int $contextid, string $jobid): array {
        global $SESSION;
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid, 'jobid' => $jobid]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('mod/mpl:addinstance', $context->get_course_context());
        if (empty($SESSION->mod_mpl_authorjobs[$params['jobid']])) {
            throw new \moodle_exception('sessionnotfound', 'mpl');
        }
        \core\session\manager::write_close();
        $job = (new \mod_mpl\runner())->author_get($params['jobid']);
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
