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
 * Start an interactive run (console).
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_start extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'code' => new external_value(PARAM_RAW, 'Source code'),
        ]);
    }

    /**
     * Start the run.
     *
     * @param int $cmid
     * @param string $code
     * @return array
     */
    public static function execute(int $cmid, string $code): array {
        global $USER, $SESSION;
        ['cmid' => $cmid, 'code' => $code] = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'code' => $code]);
        $manager = manager::from_cmid($cmid);
        self::validate_context($manager->context);
        require_capability('mod/mpl:run', $manager->context);
        if (empty($manager->mpl->allowrun) && !$manager->can_grade()) {
            throw new \moodle_exception('runnotallowed', 'mpl');
        }
        $manager->require_exam_access();
        $code = manager::clean_code($code);
        $manager->save_draft($USER->id, $code);
        $sid = (new runner())->session_start($code, (int)$USER->id, $manager->limits(), $manager->data_files());
        if (empty($SESSION->mod_mpl_sessions) || !is_array($SESSION->mod_mpl_sessions)) {
            $SESSION->mod_mpl_sessions = [];
        }
        // Keep the list short: only the latest sessions of this browser session.
        $SESSION->mod_mpl_sessions = array_slice($SESSION->mod_mpl_sessions, -9, null, true);
        $SESSION->mod_mpl_sessions[$sid] = ['cmid' => $cmid, 'userid' => (int)$USER->id];
        return ['sessionid' => $sid];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sessionid' => new external_value(PARAM_RAW, 'Session id'),
        ]);
    }

    /**
     * Ensure the session belongs to the current user.
     *
     * @param int $cmid
     * @param string $sessionid
     * @return manager
     */
    public static function check_session(int $cmid, string $sessionid): manager {
        global $SESSION, $USER;
        $manager = manager::from_cmid($cmid);
        self::validate_context($manager->context);
        require_capability('mod/mpl:run', $manager->context);
        $sessions = $SESSION->mod_mpl_sessions ?? [];
        $entry = is_array($sessions) ? ($sessions[$sessionid] ?? null) : null;
        if (!is_array($entry) || $entry['cmid'] != $cmid || $entry['userid'] != $USER->id) {
            throw new \moodle_exception('sessionnotfound', 'mpl');
        }
        return $manager;
    }
}
