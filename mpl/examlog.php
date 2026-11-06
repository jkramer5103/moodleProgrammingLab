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
 * Exam mode: log of window changes, blocked pastes and Safe Exam Browser checks.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$manager = \mod_mpl\manager::from_cmid($id);
$cm = $manager->cm;
$mpl = $manager->mpl;

require_login($manager->course, false, $cm);
require_capability('mod/mpl:grade', $manager->context);

$url = new moodle_url('/mod/mpl/examlog.php', ['id' => $cm->id, 'userid' => $userid]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($mpl->name) . ': ' . get_string('examlog', 'mpl'));
$PAGE->set_heading(format_string($manager->course->fullname));
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('submissions', 'mpl'), new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]));
$PAGE->navbar->add(get_string('examlog', 'mpl'));

echo $OUTPUT->header();
$params = ['mplid' => $mpl->id];
$title = get_string('examlog', 'mpl');
if ($userid) {
    $params['userid'] = $userid;
    $title .= ': ' . fullname(core_user::get_user($userid, '*', MUST_EXIST));
}
echo $OUTPUT->heading($title);
echo html_writer::div(get_string('examloginfo', 'mpl'), 'small text-muted mb-3');

$rows = $DB->get_records('mpl_examlog', $params, 'timecreated DESC, id DESC', '*', 0, 2000);
if (!$rows) {
    echo $OUTPUT->notification(get_string('examlognone', 'mpl'), 'info');
} else {
    $names = [];
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('time'), get_string('fullname'), get_string('examlogevent', 'mpl'), get_string('examlogdetail', 'mpl')];
    foreach ($rows as $r) {
        if (!isset($names[$r->userid])) {
            $u = core_user::get_user($r->userid);
            $names[$r->userid] = $u ? fullname($u) : '?';
        }
        $detail = s($r->detail);
        if ($r->event === 'left') {
            $detail = get_string('examlogleftfor', 'mpl', format_time((int)$r->duration)) . ($detail ? ' · ' . $detail : '');
        }
        $table->data[] = [
            userdate($r->timecreated, get_string('strftimedatetimeaccurate', 'core_langconfig')),
            html_writer::link(new moodle_url('/mod/mpl/examlog.php', ['id' => $cm->id, 'userid' => $r->userid]),
                $names[$r->userid]),
            get_string('examevent_' . $r->event, 'mpl'),
            $detail,
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
