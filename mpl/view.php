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
 * Workspace of a Programming Lab: editor, console, tests, submissions.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$manager = \mod_mpl\manager::from_cmid($id);
$cm = $manager->cm;
$mpl = $manager->mpl;
$context = $manager->context;

require_login($manager->course, true, $cm);
require_capability('mod/mpl:view', $context);

$event = \mod_mpl\event\course_module_viewed::create(['objectid' => $mpl->id, 'context' => $context]);
$event->add_record_snapshot('course', $manager->course);
$event->add_record_snapshot('mpl', $mpl);
$event->trigger();
$completion = new completion_info($manager->course);
$completion->set_module_viewed($cm);

$PAGE->set_url(new moodle_url('/mod/mpl/view.php', ['id' => $cm->id]));
$PAGE->set_title(format_string($mpl->name));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->add_body_class('mod-mpl-workspace-page');
$PAGE->activityheader->set_attrs(['description' => format_module_intro('mpl', $mpl, $cm->id)]);

$isgrader = $manager->can_grade();
$cansubmit = $manager->can_submit();
$canrun = has_capability('mod/mpl:run', $context);
$runner = new \mod_mpl\runner();

$attempts = [];
foreach ($DB->get_records('mpl_submissions', ['mplid' => $mpl->id, 'userid' => $USER->id], 'attempt DESC',
        'id, attempt, fraction, score, maxscore, timecreated') as $s) {
    $attempts[] = [
        'id' => (int)$s->id,
        'attempt' => (int)$s->attempt,
        'grade' => $manager->format_grade((float)$s->fraction),
        'time' => userdate($s->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
    ];
}

$config = [
    'cmid' => (int)$cm->id,
    'code' => $manager->initial_code($USER->id),
    'starter' => (string)$mpl->starter,
    'allowrun' => ($mpl->allowrun || $isgrader) && $canrun,
    'allowcheck' => ($mpl->allowcheck || $isgrader) && $canrun,
    'checkgraded' => (bool)$mpl->checkgraded && $cansubmit,
    'cansubmit' => $cansubmit,
    'submitblocker' => $cansubmit ? $manager->submit_blocker($USER->id) : null,
    'attemptsleft' => $manager->attempts_left($USER->id),
    'maxattempts' => (int)$mpl->maxattempts,
    'currentgrade' => $manager->current_grade_text($USER->id),
    'attempts' => $attempts,
    'isgrader' => $isgrader,
    'testcount' => count($manager->get_tests()),
    'ai' => $canrun ? $manager->hint_state($USER->id) : ['enabled' => false],
    'hints' => $canrun && !empty($mpl->aihints) ? $manager->get_hints($USER->id) : [],
];

echo $OUTPUT->header();

if (!$runner->is_configured()) {
    echo $OUTPUT->notification(get_string('runnernotconfigured', 'mpl'), 'warning');
}

if ($isgrader) {
    $count = $DB->count_records_select('mpl_submissions', 'mplid = ?', [$mpl->id], 'COUNT(DISTINCT userid)');
    echo html_writer::div(
        html_writer::link(new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]),
            get_string('viewsubmissions', 'mpl', $count), ['class' => 'btn btn-secondary']) .
        html_writer::span(get_string('teacherhint', 'mpl'), 'text-muted ms-3 ml-3 small'),
        'mb-3 d-flex align-items-center flex-wrap');
}

// Status line for students.
$info = [];
if ($cansubmit) {
    if ($mpl->maxattempts) {
        $info[] = get_string('attemptsinfo', 'mpl', (object)['used' => count($attempts), 'max' => $mpl->maxattempts]);
    } else {
        $info[] = get_string('attemptsunlimited', 'mpl', count($attempts));
    }
    $info[] = get_string('grademethodinfo', 'mpl', $mpl->grademethod == MPL_GRADE_LAST
        ? get_string('grademethodlast', 'mpl') : get_string('grademethodbest', 'mpl'));
    if ($mpl->cutoffdate) {
        $info[] = get_string('cutoffinfo', 'mpl', userdate($mpl->cutoffdate));
    }
}
if (!empty($mpl->aihints) && $canrun && !$isgrader) {
    $info[] = get_string('aistatusline', 'mpl', (object)['free' => (int)$mpl->aifree,
        'cost' => format_float($mpl->aicost, 0), 'max' => format_float($mpl->aimax, 0)]);
}
if ($info) {
    echo html_writer::div(implode(' · ', $info), 'mpl-statusline text-muted small mb-2');
}

$domid = 'mpl-workspace-' . $cm->id;
echo html_writer::div('', 'mpl-workspace', ['id' => $domid]);
echo html_writer::tag('script', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
    ['type' => 'application/json', 'id' => $domid . '-config']);
$PAGE->requires->js_call_amd('mod_mpl/workspace', 'init', ['#' . $domid]);

echo $OUTPUT->footer();
