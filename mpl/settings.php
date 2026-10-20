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


/**
 * Admin settings for mod_mpl.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading('mod_mpl/runnerheading', get_string('runnerheading', 'mpl'),
        get_string('runnerheading_desc', 'mpl') . ' ' .
        html_writer::link(new moodle_url('/mod/mpl/runnertest.php'), get_string('runnertest', 'mpl'))));
    $settings->add(new admin_setting_configtext('mod_mpl/runnerurl', get_string('runnerurl', 'mpl'),
        get_string('runnerurl_desc', 'mpl'), 'http://runner:8000', PARAM_URL));
    $settings->add(new admin_setting_configpasswordunmask('mod_mpl/runnersecret', get_string('runnersecret', 'mpl'),
        get_string('runnersecret_desc', 'mpl'), ''));
    $settings->add(new admin_setting_configcheckbox('mod_mpl/runnerverifytls', get_string('runnerverifytls', 'mpl'),
        get_string('runnerverifytls_desc', 'mpl'), 1));
    $settings->add(new admin_setting_configtext('mod_mpl/supportemail', get_string('supportemail', 'mpl'),
        get_string('supportemail_desc', 'mpl'), 'info@jkramertech.com', PARAM_EMAIL));
    $settings->add(new admin_setting_heading('mod_mpl/defaultsheading', get_string('defaultsheading', 'mpl'), ''));
    $settings->add(new admin_setting_configtext('mod_mpl/defaulttimeout', get_string('timeout', 'mpl'),
        get_string('timeout_help', 'mpl'), 30, PARAM_INT));
    $settings->add(new admin_setting_configtext('mod_mpl/defaultmemory', get_string('memory', 'mpl'),
        get_string('memory_help', 'mpl'), 512, PARAM_INT));
}
