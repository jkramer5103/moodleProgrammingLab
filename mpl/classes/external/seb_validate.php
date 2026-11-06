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
 * Safe Exam Browser: check the keys of the SEB JavaScript API (if no HTTP headers were sent).
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seb_validate extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'url' => new external_value(PARAM_URL, 'Page URL the keys were computed with'),
            'configkey' => new external_value(PARAM_ALPHANUMEXT, 'SafeExamBrowser.security.configKey', VALUE_DEFAULT, ''),
            'browserexamkey' => new external_value(PARAM_ALPHANUMEXT, 'SafeExamBrowser.security.browserExamKey',
                VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param string $url
     * @param string $configkey
     * @param string $browserexamkey
     * @return array
     */
    public static function execute(int $cmid, string $url, string $configkey = '', string $browserexamkey = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'url' => $url,
            'configkey' => $configkey, 'browserexamkey' => $browserexamkey]);
        $manager = manager::from_cmid($params['cmid']);
        self::validate_context($manager->context);
        require_capability('mod/mpl:view', $manager->context);
        // The keys must have been computed for this activity's page.
        $page = new \moodle_url('/mod/mpl/view.php');
        $ok = strpos($params['url'], $page->out(false)) === 0 && $manager->seb_keys_valid($params['url'],
            $params['configkey'] ?: null, $params['browserexamkey'] ?: null, \mod_mpl\seb::is_seb_useragent());
        $manager->set_seb_session($ok);
        if (!$ok && $manager->seb_required()) {
            $manager->log_exam_event((int)$USER->id, 'sebfail', 'js');
        }
        return ['ok' => $ok];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Keys valid'),
        ]);
    }
}
