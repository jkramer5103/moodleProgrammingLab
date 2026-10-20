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
 * Admin: test the connection to the runner.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/mpl/runnertest.php'));
$PAGE->set_title(get_string('runnertest', 'mpl'));
$PAGE->set_heading(get_string('runnertest', 'mpl'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
try {
    $runner = new \mod_mpl\runner();
    $info = $runner->info();
    $sample = $runner->evaluate("print(sum(range(int(input()) + 1)))", "n = int(input())\nprint(n * (n + 1) // 2)",
        [['id' => 'demo', 'name' => 'Demo', 'type' => 'io', 'points' => 1, 'repeat' => 3,
          'inputs' => [['kind' => 'int', 'min' => 1, 'max' => 1000]], 'expected' => ['source' => 'reference']]],
        ['timeout' => 10, 'memory' => 256]);
    echo $OUTPUT->notification(get_string('runnertestok', 'mpl', (object)[
        'version' => s($info['version'] ?? '?'),
        'score' => format_float($sample['score'] ?? 0, 0) . '/' . format_float($sample['max_score'] ?? 0, 0),
        'duration' => format_float($sample['duration'] ?? 0, 2),
    ]), 'success');
} catch (moodle_exception $e) {
    echo $OUTPUT->notification(get_string('runnertestfailed', 'mpl', s($e->getMessage())), 'error');
}
echo $OUTPUT->continue_button(new moodle_url('/admin/settings.php', ['section' => 'modsettingmpl']));
echo $OUTPUT->footer();
