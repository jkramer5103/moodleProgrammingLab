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
 * List of all Programming Labs in a course.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$coursecontext = context_course::instance($course->id);
\mod_mpl\event\course_module_instance_list_viewed::create(['context' => $coursecontext])->trigger();

$PAGE->set_url(new moodle_url('/mod/mpl/index.php', ['id' => $id]));
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);
$PAGE->navbar->add(get_string('modulenameplural', 'mpl'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mpl'));

$mpls = get_all_instances_in_course('mpl', $course);
if (!$mpls) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'mpl')),
        new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [get_string('name'), get_string('duedate', 'mpl')];
foreach ($mpls as $mpl) {
    $link = html_writer::link(new moodle_url('/mod/mpl/view.php', ['id' => $mpl->coursemodule]),
        format_string($mpl->name), $mpl->visible ? [] : ['class' => 'dimmed']);
    $table->data[] = [$link, $mpl->duedate ? userdate($mpl->duedate) : '-'];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
