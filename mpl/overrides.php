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
 * Individual deadlines and attempts (per student or group), e.g. for a "Nachteilsausgleich".
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$overrideid = optional_param('overrideid', 0, PARAM_INT);
$manager = \mod_mpl\manager::from_cmid($id);
$cm = $manager->cm;
$mpl = $manager->mpl;
$context = $manager->context;

require_login($manager->course, false, $cm);
require_capability('mod/mpl:manageoverrides', $context);

$baseurl = new moodle_url('/mod/mpl/overrides.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($mpl->name) . ': ' . get_string('overrides', 'mpl'));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('overrides', 'mpl'), $baseurl);

if ($action === 'delete' && $overrideid) {
    require_sesskey();
    $DB->delete_records('mpl_overrides', ['id' => $overrideid, 'mplid' => $mpl->id]);
    redirect($baseurl, get_string('overridedeleted', 'mpl'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$users = [];
foreach (get_enrolled_users($context, 'mod/mpl:submit', 0, 'u.*', 'u.lastname, u.firstname') as $u) {
    $users[$u->id] = fullname($u);
}
$groups = [];
foreach (groups_get_all_groups($manager->course->id) as $g) {
    $groups[$g->id] = format_string($g->name);
}

if ($action === 'edit') {
    $form = new \mod_mpl\form\override_form(new moodle_url($baseurl, ['action' => 'edit']),
        ['users' => $users, 'groups' => $groups, 'mpl' => $mpl]);
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $record = (object)[
            'mplid' => $mpl->id,
            'userid' => $data->target === 'user' ? (int)$data->userid : null,
            'groupid' => $data->target === 'group' ? (int)$data->groupid : null,
            'duedate' => !empty($data->duedate) ? (int)$data->duedate : null,
            'cutoffdate' => !empty($data->cutoffdate) ? (int)$data->cutoffdate : null,
            'maxattempts' => (int)$data->maxattempts === \mod_mpl\form\override_form::INHERIT ? null : (int)$data->maxattempts,
            'timemodified' => time(),
        ];
        if (!empty($data->overrideid) && $DB->record_exists('mpl_overrides', ['id' => $data->overrideid, 'mplid' => $mpl->id])) {
            $record->id = $data->overrideid;
            $DB->update_record('mpl_overrides', $record);
        } else {
            $DB->insert_record('mpl_overrides', $record);
        }
        redirect($baseurl, get_string('overridesaved', 'mpl'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $defaults = ['id' => $cm->id, 'overrideid' => 0, 'target' => optional_param('target', 'user', PARAM_ALPHA),
        'userid' => optional_param('userid', 0, PARAM_INT), 'maxattempts' => \mod_mpl\form\override_form::INHERIT,
        'duedate' => $mpl->duedate, 'cutoffdate' => $mpl->cutoffdate];
    if ($overrideid && ($o = $DB->get_record('mpl_overrides', ['id' => $overrideid, 'mplid' => $mpl->id]))) {
        $defaults = array_merge($defaults, ['overrideid' => $o->id, 'target' => $o->groupid ? 'group' : 'user',
            'userid' => (int)$o->userid, 'groupid' => (int)$o->groupid, 'duedate' => (int)$o->duedate,
            'cutoffdate' => (int)$o->cutoffdate,
            'maxattempts' => $o->maxattempts === null ? \mod_mpl\form\override_form::INHERIT : (int)$o->maxattempts]);
    }
    $form->set_data($defaults);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($mpl->name) . ': ' . get_string('overrideedit', 'mpl'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($mpl->name) . ': ' . get_string('overrides', 'mpl'));
echo html_writer::div(get_string('overridesintro', 'mpl'), 'mb-3');

$activity = [];
$activity[] = get_string('duedate', 'mpl') . ': ' . ($mpl->duedate ? userdate($mpl->duedate) : '–');
$activity[] = get_string('cutoffdate', 'mpl') . ': ' . ($mpl->cutoffdate ? userdate($mpl->cutoffdate) : '–');
$activity[] = get_string('maxattempts', 'mpl') . ': ' . ($mpl->maxattempts ?: get_string('unlimited'));
echo html_writer::div(get_string('overrideactivity', 'mpl') . ' ' . implode(' · ', $activity), 'small text-muted mb-3');

echo html_writer::div(
    html_writer::link(new moodle_url($baseurl, ['action' => 'edit', 'target' => 'user']),
        get_string('overrideadduser', 'mpl'), ['class' => 'btn btn-primary me-2 mr-2']) .
    ($groups ? html_writer::link(new moodle_url($baseurl, ['action' => 'edit', 'target' => 'group']),
        get_string('overrideaddgroup', 'mpl'), ['class' => 'btn btn-secondary']) : ''), 'mb-3');

$overrides = $DB->get_records('mpl_overrides', ['mplid' => $mpl->id], 'groupid, userid');
if (!$overrides) {
    echo $OUTPUT->notification(get_string('overridesnone', 'mpl'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('overridetarget', 'mpl'), get_string('duedate', 'mpl'), get_string('cutoffdate', 'mpl'),
        get_string('maxattempts', 'mpl'), ''];
    $inherit = html_writer::span(get_string('overrideasactivity', 'mpl'), 'text-muted');
    foreach ($overrides as $o) {
        if ($o->groupid) {
            $who = get_string('overridegroupname', 'mpl', $groups[$o->groupid] ?? ('#' . $o->groupid));
        } else {
            $who = $users[$o->userid] ?? fullname(core_user::get_user($o->userid) ?: (object)['firstname' => '?', 'lastname' => '']);
        }
        $table->data[] = [
            $who,
            $o->duedate === null ? $inherit : userdate($o->duedate),
            $o->cutoffdate === null ? $inherit : userdate($o->cutoffdate),
            $o->maxattempts === null ? $inherit : ((int)$o->maxattempts ?: get_string('unlimited')),
            html_writer::link(new moodle_url($baseurl, ['action' => 'edit', 'overrideid' => $o->id]), get_string('edit'),
                ['class' => 'btn btn-sm btn-secondary me-1 mr-1']) .
            $OUTPUT->action_link(new moodle_url($baseurl, ['action' => 'delete', 'overrideid' => $o->id,
                'sesskey' => sesskey()]), get_string('delete'),
                new confirm_action(get_string('overridedeleteconfirm', 'mpl')), ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
}
echo mpl_support_notice();
echo $OUTPUT->footer();
