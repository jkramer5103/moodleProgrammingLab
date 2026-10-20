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
 * Teacher: evaluate the tests against the reference solution (or any code) from the settings form.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Course or module context id'),
            'code' => new external_value(PARAM_RAW, 'Code to test (normally the reference solution)'),
            'reference' => new external_value(PARAM_RAW, 'Reference solution'),
            'tests' => new external_value(PARAM_RAW, 'Tests JSON'),
            'timeout' => new external_value(PARAM_INT, 'Time limit'),
            'memory' => new external_value(PARAM_INT, 'Memory limit'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $contextid
     * @param string $code
     * @param string $reference
     * @param string $tests
     * @param int $timeout
     * @param int $memory
     * @return array
     */
    public static function execute(int $contextid, string $code, string $reference, string $tests, int $timeout, int $memory): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid, 'code' => $code, 'reference' => $reference, 'tests' => $tests, 'timeout' => $timeout, 'memory' => $memory]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        $coursecontext = $context->get_course_context();
        require_capability('mod/mpl:addinstance', $coursecontext);
        \core_php_time_limit::raise(360);
        $tests = manager::decode_tests(manager::normalise_tests_json($params['tests']));
        $limits = ['timeout' => max(1, $params['timeout']), 'memory' => max(64, $params['memory'])];
        $result = (new \mod_mpl\runner())->evaluate(manager::clean_code($params['code']),
            manager::clean_code($params['reference']), $tests, $limits);
        $out = manager::filter_result($result, true);
        $out['mode'] = 'validate';
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
