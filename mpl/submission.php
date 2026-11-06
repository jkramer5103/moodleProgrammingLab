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
 * Teacher view of a single attempt (code + full test results).
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$submission = $DB->get_record('mpl_submissions', ['id' => $id], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('mpl', $submission->mplid, 0, false, MUST_EXIST);
$manager = \mod_mpl\manager::from_cmid($cm->id);
$context = $manager->context;

require_login($manager->course, false, $manager->cm);
if ($submission->userid != $USER->id) {
    require_capability('mod/mpl:grade', $context);
}

$user = core_user::get_user($submission->userid, '*', MUST_EXIST);
$PAGE->set_url(new moodle_url('/mod/mpl/submission.php', ['id' => $id]));
$PAGE->set_title(format_string($manager->mpl->name) . ': ' . fullname($user));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->activityheader->disable();
if ($manager->can_grade()) {
    $PAGE->navbar->add(get_string('submissions', 'mpl'), new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]));
}
$PAGE->navbar->add(fullname($user));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($manager->mpl->name) . ': ' . fullname($user));

// Attempt navigation.
$all = $DB->get_records('mpl_submissions', ['mplid' => $submission->mplid, 'userid' => $submission->userid],
    'attempt ASC', 'id, attempt, fraction, timecreated');
$links = [];
foreach ($all as $a) {
    $label = get_string('attemptn', 'mpl', $a->attempt) . ' (' . $manager->format_grade((float)$a->fraction) . ')';
    $links[] = $a->id == $submission->id ? html_writer::tag('strong', $label)
        : html_writer::link(new moodle_url('/mod/mpl/submission.php', ['id' => $a->id]), $label);
}
echo html_writer::div(implode(' · ', $links), 'mb-3');
echo html_writer::div(get_string('submittedon', 'mpl', userdate($submission->timecreated)) . ' · ' .
    get_string('score', 'mpl') . ': ' . format_float($submission->score, 2) . ' / ' . format_float($submission->maxscore, 2) .
    ' · ' . get_string('gradenoun') . ': ' . $manager->format_grade((float)$submission->fraction), 'mb-3');

$result = json_decode((string)$submission->result, true) ?: [];
$filtered = \mod_mpl\manager::filter_result($result, $manager->can_grade());
$canfeedback = has_capability('mod/mpl:grade', $context);
$config = ['code' => (string)$submission->code, 'result' => $filtered, 'cmid' => (int)$cm->id,
    'submissionid' => (int)$submission->id, 'feedback' => $manager->get_feedback((int)$submission->id),
    'canfeedback' => $canfeedback];
if ($submission->timeregraded) {
    echo html_writer::div(get_string('regradedon', 'mpl', userdate($submission->timeregraded)), 'small text-muted mb-2');
}
if ($canfeedback) {
    echo html_writer::div(get_string('feedbackteacherhint', 'mpl'), 'small text-muted mb-2');
}

$domid = 'mpl-submission-' . $submission->id;
echo html_writer::div('', 'mpl-submission-view', ['id' => $domid]);
echo html_writer::tag('script', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP |
    JSON_INVALID_UTF8_SUBSTITUTE), ['type' => 'application/json', 'id' => $domid . '-config']);
$PAGE->requires->js_call_amd('mod_mpl/workspace', 'initSubmission', ['#' . $domid]);
echo $OUTPUT->footer();
