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
 * Teacher overview of all students and their attempts.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/gradelib.php');

$id = required_param('id', PARAM_INT);
$manager = \mod_mpl\manager::from_cmid($id);
$cm = $manager->cm;
$mpl = $manager->mpl;
$context = $manager->context;

require_login($manager->course, false, $cm);
require_capability('mod/mpl:grade', $context);

$PAGE->set_url(new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]));
$PAGE->set_title(format_string($mpl->name) . ': ' . get_string('submissions', 'mpl'));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->activityheader->disable();

$exam = !empty($mpl->exammode);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($mpl->name) . ': ' . get_string($exam ? 'exammonitortitle' : 'submissions', 'mpl'));

$actions = [];
if (has_capability('mod/mpl:regrade', $context)) {
    $outdated = $manager->outdated_count();
    if ($outdated) {
        echo $OUTPUT->notification(get_string('regradeneeded', 'mpl', $outdated), 'warning');
    }
    $actions[] = html_writer::link(new moodle_url('/mod/mpl/regrade.php', ['id' => $cm->id]), get_string('regrade', 'mpl'),
        ['class' => 'btn ' . ($outdated ? 'btn-warning' : 'btn-outline-secondary') . ' me-2 mr-2']);
}
if (has_capability('mod/mpl:manageoverrides', $context)) {
    $actions[] = html_writer::link(new moodle_url('/mod/mpl/overrides.php', ['id' => $cm->id]),
        get_string('overridesbutton', 'mpl', $DB->count_records('mpl_overrides', ['mplid' => $mpl->id])),
        ['class' => 'btn btn-outline-secondary me-2 mr-2']);
}
if ($exam) {
    $actions[] = html_writer::link(new moodle_url('/mod/mpl/examlog.php', ['id' => $cm->id]), get_string('examlog', 'mpl'),
        ['class' => 'btn btn-outline-secondary me-2 mr-2']);
    $actions[] = html_writer::tag('label', html_writer::checkbox('mpl-autorefresh', 1, true, '', ['id' => 'mpl-autorefresh']) .
        ' ' . get_string('examautorefresh', 'mpl'), ['class' => 'ms-2 ml-2 small']);
    $PAGE->requires->js_call_amd('mod_mpl/exam', 'autoRefresh', [30]);
}
if ($actions) {
    echo html_writer::div(implode('', $actions), 'mb-3 d-flex align-items-center flex-wrap');
}

$users = get_enrolled_users($context, 'mod/mpl:submit', 0, 'u.*', 'u.lastname, u.firstname');
$stats = $DB->get_records_sql("SELECT userid, COUNT(1) AS attempts, MAX(fraction) AS best, MAX(timecreated) AS lastsubmit
                                 FROM {mpl_submissions}
                                WHERE mplid = ?
                             GROUP BY userid", [$mpl->id]);
$last = [];
foreach ($DB->get_records_sql("SELECT s.id, s.userid, s.fraction, s.attempt
                                 FROM {mpl_submissions} s
                                 JOIN (SELECT userid, MAX(attempt) AS maxattempt FROM {mpl_submissions}
                                        WHERE mplid = ? GROUP BY userid) m
                                   ON m.userid = s.userid AND m.maxattempt = s.attempt
                                WHERE s.mplid = ?", [$mpl->id, $mpl->id]) as $r) {
    $last[$r->userid] = $r;
}

$hintstats = $DB->get_records_sql("SELECT userid, COUNT(1) AS n, SUM(cost) AS cost
                                      FROM {mpl_hints} WHERE mplid = ? GROUP BY userid", [$mpl->id]);
$feedbackstats = $DB->get_records_sql_menu("SELECT userid, COUNT(1) FROM {mpl_feedback} WHERE mplid = ? GROUP BY userid",
    [$mpl->id]);
$drafts = $DB->get_records_menu('mpl_drafts', ['mplid' => $mpl->id], '', 'userid, timemodified');
$examstats = $exam ? $manager->exam_summary() : [];
$hasoverrides = $DB->record_exists('mpl_overrides', ['mplid' => $mpl->id]);

$gradeitem = grade_get_grades($manager->course->id, 'mod', 'mpl', $mpl->id, array_keys($users));
$gradebook = [];
if (!empty($gradeitem->items[0]->grades)) {
    foreach ($gradeitem->items[0]->grades as $uid => $g) {
        $gradebook[$uid] = $g;
    }
}

$table = new html_table();
$table->attributes['class'] = 'generaltable mpl-submissions-table';
$table->head = [get_string('fullname'), get_string('attempts', 'mpl'), get_string('bestgrade', 'mpl'),
    get_string('lastgrade', 'mpl')];
if ($exam) {
    array_push($table->head, get_string('examlastactive', 'mpl'), get_string('examleft', 'mpl'),
        get_string('exampastes', 'mpl'));
} else {
    $table->head[] = get_string('aihintscolumn', 'mpl');
}
array_push($table->head, get_string('feedbackcolumn', 'mpl'), get_string('gradebookgrade', 'mpl'),
    get_string('lastsubmission', 'mpl'), '');
$short = get_string('strftimedatetimeshort', 'langconfig');
$submitted = 0;
foreach ($users as $user) {
    $s = $stats[$user->id] ?? null;
    $l = $last[$user->id] ?? null;
    if ($s) {
        $submitted++;
    }
    $g = $gradebook[$user->id] ?? null;
    $gradetext = $g && $g->grade !== null ? $g->str_grade : '-';
    if ($g && !empty($g->overridden)) {
        $gradetext .= ' ' . html_writer::span(get_string('overridden', 'grades'), 'badge bg-warning text-dark badge-warning');
    }
    $name = fullname($user);
    if ($hasoverrides && $manager->effective((int)$user->id)->override) {
        $name .= ' ' . html_writer::span(get_string('individualbadge', 'mpl'), 'badge bg-info text-dark badge-info',
            ['title' => get_string('individualtitle', 'mpl')]);
    }
    $row = [
        $name,
        $s ? (int)$s->attempts : 0,
        $s ? $manager->format_grade((float)$s->best) : '-',
        $l ? $manager->format_grade((float)$l->fraction) : '-',
    ];
    if ($exam) {
        $e = $examstats[$user->id] ?? null;
        $active = max((int)($drafts[$user->id] ?? 0), (int)($e->last ?? 0), $s ? (int)$s->lastsubmit : 0);
        $row[] = $active ? userdate($active, $short) : html_writer::span(get_string('examnotstarted', 'mpl'), 'text-muted');
        $lefts = $e ? (int)$e->lefts : 0;
        $row[] = $lefts ? html_writer::link(new moodle_url('/mod/mpl/examlog.php', ['id' => $cm->id, 'userid' => $user->id]),
            html_writer::span(get_string('examleftcount', 'mpl', (object)['n' => $lefts,
                'time' => format_time((int)$e->seconds)]), 'text-danger')) : '0';
        $row[] = ($e && $e->pastes) ? html_writer::span((int)$e->pastes, 'text-danger') .
            (($e->sebfails ?? 0) ? ' · ' . get_string('examsebfails', 'mpl', (int)$e->sebfails) : '') : '0';
    } else {
        $row[] = !empty($hintstats[$user->id]) ? html_writer::link(new moodle_url('/mod/mpl/hints.php',
            ['id' => $cm->id, 'userid' => $user->id]), $hintstats[$user->id]->n . ' (−' .
            format_float(min((float)$mpl->aimax, (float)$hintstats[$user->id]->cost), 0) . ' %)') : '-';
    }
    array_push($row,
        !empty($feedbackstats[$user->id]) ? (int)$feedbackstats[$user->id] : '-',
        $gradetext,
        $s ? userdate($s->lastsubmit, $short) : '-',
        $l ? html_writer::link(new moodle_url('/mod/mpl/submission.php', ['id' => $l->id]),
            get_string('viewattempts', 'mpl'), ['class' => 'btn btn-sm btn-secondary']) : '');
    $table->data[] = $row;
}

echo html_writer::div(get_string('submissionsummary', 'mpl', (object)['submitted' => $submitted,
    'total' => count($users)]), 'mb-2');
echo html_writer::div(html_writer::link(new moodle_url('/grade/report/grader/index.php', ['id' => $manager->course->id]),
    get_string('overridehint', 'mpl')), 'small text-muted mb-3');
echo html_writer::table($table);
echo mpl_support_notice();
echo $OUTPUT->footer();
