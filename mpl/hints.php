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
 * Teacher view: AI hints a student received.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$manager = \mod_mpl\manager::from_cmid($id);
require_login($manager->course, false, $manager->cm);
if ($userid != $USER->id) {
    require_capability('mod/mpl:grade', $manager->context);
}
$user = core_user::get_user($userid, '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/mod/mpl/hints.php', ['id' => $id, 'userid' => $userid]));
$PAGE->set_title(format_string($manager->mpl->name) . ': ' . get_string('aihintsof', 'mpl', fullname($user)));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->activityheader->disable();
if ($manager->can_grade()) {
    $PAGE->navbar->add(get_string('submissions', 'mpl'), new moodle_url('/mod/mpl/submissions.php', ['id' => $id]));
}
$PAGE->navbar->add(get_string('aihintsof', 'mpl', fullname($user)));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aihintsof', 'mpl', fullname($user)));
$penalty = $manager->hint_penalty($userid);
$records = $DB->get_records('mpl_hints', ['mplid' => $manager->mpl->id, 'userid' => $userid], 'timecreated ASC');
echo html_writer::div(get_string('aihintssummary', 'mpl', (object)['count' => count($records),
    'penalty' => format_float($penalty, 0)]), 'mb-3');
if (!$records) {
    echo $OUTPUT->notification(get_string('aihintsnone', 'mpl'), 'info');
}
$n = 0;
foreach ($records as $r) {
    $n++;
    $context = json_decode((string)$r->context, true) ?: [];
    $head = get_string('aihintn', 'mpl', $n) . ' · ' . userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')) .
        ' · ' . ($r->kind === 'test' ? get_string('aikindtest', 'mpl', s($r->title)) : get_string('aikindconsole', 'mpl')) .
        ' · ' . ($r->cost > 0 ? '−' . format_float($r->cost, 0) . ' %' : get_string('aifreehint', 'mpl'));
    $body = html_writer::tag('div', format_text($r->hint, FORMAT_MARKDOWN, ['context' => $manager->context]),
        ['class' => 'mpl-hint-text']);
    foreach ((json_decode((string)$r->followups, true) ?: []) as $f) {
        $body .= html_writer::div(html_writer::tag('strong', get_string('aifollowupq', 'mpl')) . ' ' . s($f['q']),
            'mpl-hint-q');
        $body .= html_writer::div(format_text($f['a'], FORMAT_MARKDOWN, ['context' => $manager->context]), 'mpl-hint-a');
    }
    $details = html_writer::tag('pre', s((string)$r->code), ['class' => 'mpl-pre']);
    if (($context['kind'] ?? '') === 'test') {
        $details .= html_writer::tag('pre', s(json_encode($context['test'] ?? [], JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE)), ['class' => 'mpl-pre']);
    } else if (!empty($context['output'])) {
        $details .= html_writer::tag('pre', s($context['output']), ['class' => 'mpl-pre']);
    }
    $body .= print_collapsible_region($details, 'mpl-hint-details', 'mpl-hint-details-' . $r->id,
        get_string('aihintcontext', 'mpl'), '', true, true);
    echo html_writer::div(html_writer::div($head, 'mpl-hint-head') . $body, 'mpl-hint-card');
}
echo $OUTPUT->footer();
