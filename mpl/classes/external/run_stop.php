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
use mod_mpl\runner;

/**
 * Stop an interactive run.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_stop extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'sessionid' => new external_value(PARAM_ALPHANUMEXT, 'Session id'),
        ]);
    }

    /**
     * Stop.
     *
     * @param int $cmid
     * @param string $sessionid
     * @return array
     */
    public static function execute(int $cmid, string $sessionid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'sessionid' => $sessionid]);
        run_start::check_session($params['cmid'], $params['sessionid']);
        try {
            (new runner())->session_stop($params['sessionid']);
        } catch (\moodle_exception $e) {
            // Already gone.
            return ['ok' => false];
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
            'ok' => new external_value(PARAM_BOOL, 'Stopped'),
        ]);
    }
}
