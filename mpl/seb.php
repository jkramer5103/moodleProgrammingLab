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
 * Safe Exam Browser configuration of an activity (opened by SEB via seb:// or sebs://).
 *
 * SEB downloads this file itself, in its own browser without the Moodle session of the
 * student, so no login is required. The file only contains the start page of the activity
 * and the SEB settings (quit password only as hash); access to the activity is checked as
 * usual once SEB opens it.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- SEB fetches the file without a Moodle session.
require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$manager = \mod_mpl\manager::from_cmid($id);
$mpl = $manager->mpl;
if (empty($mpl->exammode) || (int)$mpl->sebmode !== \mod_mpl\manager::SEB_CONFIG || !$manager->cm->visible) {
    throw new moodle_exception('sebnoconfig', 'mpl');
}
$xml = \mod_mpl\seb::plist($manager->seb_settings());
$filename = clean_filename(format_string($mpl->name) ?: 'pruefung') . '.seb';
header('Content-Type: application/seb');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=1, no-transform');
header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
header('Pragma: no-cache');
header('Content-Length: ' . strlen($xml));
echo $xml;
