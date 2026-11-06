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
 * Exam mode: log a window change or a blocked paste.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_log extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'event' => new external_value(PARAM_ALPHA, 'start, left, paste'),
            'detail' => new external_value(PARAM_TEXT, 'Detail', VALUE_DEFAULT, ''),
            'duration' => new external_value(PARAM_INT, 'Seconds outside the window', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param string $event
     * @param string $detail
     * @param int $duration
     * @return array
     */
    public static function execute(int $cmid, string $event, string $detail = '', int $duration = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'event' => $event,
            'detail' => $detail, 'duration' => $duration]);
        $manager = manager::from_cmid($params['cmid']);
        self::validate_context($manager->context);
        require_capability('mod/mpl:view', $manager->context);
        if (!in_array($params['event'], ['start', 'left', 'paste'])) {
            throw new \invalid_parameter_exception('event');
        }
        if (!$manager->can_grade()) {
            $manager->log_exam_event((int)$USER->id, $params['event'], $params['detail'], $params['duration']);
        }
        return ['ok' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Logged'),
        ]);
    }
}
