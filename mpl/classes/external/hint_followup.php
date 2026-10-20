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
 * Follow-up question about a hint (free, limited).
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hint_followup extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'hintid' => new external_value(PARAM_INT, 'Hint id'),
            'question' => new external_value(PARAM_TEXT, 'Question'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param int $hintid
     * @param string $question
     * @return array
     */
    public static function execute(int $cmid, int $hintid, string $question): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'hintid' => $hintid, 'question' => $question]);
        $manager = manager::from_cmid($params['cmid']);
        self::validate_context($manager->context);
        require_capability('mod/mpl:run', $manager->context);
        \core_php_time_limit::raise(180);
        $result = $manager->hint_followup((int)$USER->id, $params['hintid'], $params['question']);
        return ['json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Hint and hint status as JSON'),
        ]);
    }
}
