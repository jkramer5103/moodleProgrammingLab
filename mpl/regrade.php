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
 * Grade all submissions again after the tests (or the reference solution, the data files) changed.
 *
 * First a dry run shows what would change; only "apply" writes new grades.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$manager = \mod_mpl\manager::from_cmid($id);
$cm = $manager->cm;
$mpl = $manager->mpl;
$context = $manager->context;

require_login($manager->course, false, $cm);
require_capability('mod/mpl:regrade', $context);

$baseurl = new moodle_url('/mod/mpl/regrade.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($mpl->name) . ': ' . get_string('regrade', 'mpl'));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('submissions', 'mpl'), new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]));
$PAGE->navbar->add(get_string('regrade', 'mpl'), $baseurl);

$total = $DB->count_records('mpl_submissions', ['mplid' => $mpl->id]);
$button = function(string $action, string $label, string $class) use ($baseurl) {
    return html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
        'value' => sesskey()]) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]) .
        html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn ' . $class]),
        ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-inline-block me-2 mr-2']);
};

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($mpl->name) . ': ' . get_string('regrade', 'mpl'));

if ($action !== 'preview' && $action !== 'apply') {
    echo html_writer::div(get_string('regradeintro', 'mpl'), 'mb-3');
    $outdated = $manager->outdated_count();
    echo html_writer::div(get_string('regradecounts', 'mpl', (object)['total' => $total, 'outdated' => $outdated]), 'mb-3');
    if ($total) {
        echo html_writer::div($button('preview', get_string('regradepreview', 'mpl'), 'btn-primary') .
            $button('apply', get_string('regradeapply', 'mpl'), 'btn-outline-warning'), 'mb-3');
    } else {
        echo $OUTPUT->notification(get_string('regradenone', 'mpl'), 'info');
    }
    echo mpl_support_notice();
    echo $OUTPUT->footer();
    exit;
}

require_sesskey();
\core_php_time_limit::raise(3600);
\core\session\manager::write_close();
$apply = $action === 'apply';
$bar = new progress_bar('mpl_regrade', 500, true);
$summary = $manager->regrade($apply, function(int $done, int $all) use ($bar) {
    $bar->update($done, max(1, $all), get_string('regradeprogress', 'mpl', (object)['done' => $done, 'total' => $all]));
});
$bar->update_full(100, get_string('regradedone', 'mpl'));

$a = (object)['total' => $summary['total'], 'changed' => $summary['changed'], 'errors' => $summary['errors']];
echo $OUTPUT->notification(get_string($apply ? 'regradeappliedsummary' : 'regradepreviewsummary', 'mpl', $a),
    $summary['errors'] ? 'warning' : 'success');

if ($summary['rows']) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('fullname'), get_string('attempt', 'mpl'), get_string('regradeold', 'mpl'),
        get_string('regradenew', 'mpl'), ''];
    foreach ($summary['rows'] as $row) {
        $user = core_user::get_user($row['userid']);
        $table->data[] = [
            $user ? fullname($user) : '?',
            $row['attempt'],
            $manager->format_grade($row['old']),
            html_writer::tag('strong', $manager->format_grade($row['new'])) . ' ' .
                ($row['new'] > $row['old'] ? html_writer::span('▲', 'text-success') : html_writer::span('▼', 'text-danger')),
            html_writer::link(new moodle_url('/mod/mpl/submission.php', ['id' => $row['submissionid']]),
                get_string('viewattempts', 'mpl'), ['class' => 'btn btn-sm btn-secondary']),
        ];
    }
    echo html_writer::table($table);
}
if (!$apply && $summary['total']) {
    echo html_writer::div(get_string('regradepreviewnote', 'mpl'), 'mb-2 small text-muted');
    echo html_writer::div($button('apply', get_string('regradeapply', 'mpl'), 'btn-primary'), 'mb-3');
}
echo html_writer::link(new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]), get_string('backtosubmissions', 'mpl'),
    ['class' => 'btn btn-secondary']);
echo $OUTPUT->footer();
