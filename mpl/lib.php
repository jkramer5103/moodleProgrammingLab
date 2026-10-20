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
 * Library of functions for mod_mpl.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Grade method: best attempt counts. */
define('MPL_GRADE_BEST', 0);
/** Grade method: last attempt counts. */
define('MPL_GRADE_LAST', 1);

/**
 * Supported features.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function mpl_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Normalise form data before saving.
 *
 * @param stdClass $data
 * @return stdClass
 */
function mpl_prepare_instance_data(stdClass $data): stdClass {
    $data->timemodified = time();
    foreach (['allowrun', 'allowcheck', 'checkgraded'] as $flag) {
        $data->$flag = empty($data->$flag) ? 0 : 1;
    }
    $data->tests = \mod_mpl\manager::normalise_tests_json($data->tests ?? '');
    foreach (['starter', 'reference'] as $field) {
        $data->$field = str_replace("\r\n", "\n", (string)($data->$field ?? ''));
    }
    return $data;
}

/**
 * Add a new instance.
 *
 * @param stdClass $data
 * @param mod_mpl_mod_form|null $mform
 * @return int new instance id
 */
function mpl_add_instance($data, $mform = null) {
    global $DB;
    $data = mpl_prepare_instance_data($data);
    $data->timecreated = $data->timemodified;
    $data->id = $DB->insert_record('mpl', $data);
    mpl_grade_item_update($data);
    return $data->id;
}

/**
 * Update an instance.
 *
 * @param stdClass $data
 * @param mod_mpl_mod_form|null $mform
 * @return bool
 */
function mpl_update_instance($data, $mform = null) {
    global $DB;
    $data = mpl_prepare_instance_data($data);
    $data->id = $data->instance;
    $DB->update_record('mpl', $data);
    $mpl = $DB->get_record('mpl', ['id' => $data->id], '*', MUST_EXIST);
    mpl_grade_item_update($mpl);
    mpl_update_grades($mpl);
    return true;
}

/**
 * Delete an instance and all its data.
 *
 * @param int $id
 * @return bool
 */
function mpl_delete_instance($id) {
    global $DB;
    if (!$mpl = $DB->get_record('mpl', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('mpl_submissions', ['mplid' => $id]);
    $DB->delete_records('mpl_drafts', ['mplid' => $id]);
    $DB->delete_records('mpl', ['id' => $id]);
    mpl_grade_item_delete($mpl);
    return true;
}

/**
 * Create/update the grade item.
 *
 * @param stdClass $mpl
 * @param array|string|null $grades
 * @return int
 */
function mpl_grade_item_update($mpl, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => $mpl->name, 'idnumber' => $mpl->cmidnumber ?? ''];
    if ($mpl->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $mpl->grade;
        $params['grademin'] = 0;
    } else if ($mpl->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$mpl->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/mpl', $mpl->course, 'mod', 'mpl', $mpl->id, 0, $grades, $params);
}

/**
 * Delete the grade item.
 *
 * @param stdClass $mpl
 * @return int
 */
function mpl_grade_item_delete($mpl) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/mpl', $mpl->course, 'mod', 'mpl', $mpl->id, 0, null, ['deleted' => 1]);
}

/**
 * Grades of users according to the grade method.
 *
 * @param stdClass $mpl
 * @param int $userid 0 = all users
 * @return array userid => grade object
 */
function mpl_get_user_grades($mpl, $userid = 0) {
    global $DB;
    $params = ['mplid' => $mpl->id];
    $where = 'mplid = :mplid';
    if ($userid) {
        $where .= ' AND userid = :userid';
        $params['userid'] = $userid;
    }
    $records = $DB->get_records_select('mpl_submissions', $where, $params, 'userid, attempt',
        'id, userid, attempt, fraction, timecreated');
    $grades = [];
    foreach ($records as $r) {
        $value = (float)$r->fraction;
        if (!isset($grades[$r->userid])) {
            $grades[$r->userid] = (object)['userid' => $r->userid, 'fraction' => $value,
                'dategraded' => $r->timecreated, 'datesubmitted' => $r->timecreated];
            continue;
        }
        $g = $grades[$r->userid];
        if ($mpl->grademethod == MPL_GRADE_LAST || $value > $g->fraction) {
            $g->fraction = $value;
        }
        $g->dategraded = max($g->dategraded, $r->timecreated);
        $g->datesubmitted = max($g->datesubmitted, $r->timecreated);
    }
    foreach ($grades as $g) {
        if ($mpl->grade > 0) {
            $g->rawgrade = round($g->fraction * $mpl->grade, 5);
        } else if ($mpl->grade < 0) {
            $scale = \grade_scale::fetch(['id' => -$mpl->grade]);
            $items = $scale ? count($scale->load_items()) : 1;
            $g->rawgrade = max(1, (int)ceil($g->fraction * $items));
        }
        unset($g->fraction);
    }
    return $grades;
}

/**
 * Push grades to the gradebook.
 *
 * @param stdClass $mpl
 * @param int $userid
 * @param bool $nullifnone
 */
function mpl_update_grades($mpl, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    if ($mpl->grade == 0) {
        mpl_grade_item_update($mpl);
        return;
    }
    if ($grades = mpl_get_user_grades($mpl, $userid)) {
        mpl_grade_item_update($mpl, $grades);
    } else if ($userid && $nullifnone) {
        mpl_grade_item_update($mpl, [$userid => (object)['userid' => $userid, 'rawgrade' => null]]);
    } else {
        mpl_grade_item_update($mpl);
    }
}

/**
 * Reset course: form elements.
 *
 * @param MoodleQuickForm $mform
 */
function mpl_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'mplheader', get_string('modulenameplural', 'mpl'));
    $mform->addElement('advcheckbox', 'reset_mpl_submissions', get_string('resetsubmissions', 'mpl'));
}

/**
 * Reset course: defaults.
 *
 * @param stdClass $course
 * @return array
 */
function mpl_reset_course_form_defaults($course) {
    return ['reset_mpl_submissions' => 1];
}

/**
 * Reset course user data.
 *
 * @param stdClass $data
 * @return array status
 */
function mpl_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_mpl_submissions)) {
        $mpls = $DB->get_records('mpl', ['course' => $data->courseid]);
        foreach ($mpls as $mpl) {
            $DB->delete_records('mpl_submissions', ['mplid' => $mpl->id]);
            $DB->delete_records('mpl_drafts', ['mplid' => $mpl->id]);
            mpl_grade_item_update($mpl, 'reset');
        }
        $status[] = ['component' => get_string('modulenameplural', 'mpl'),
            'item' => get_string('resetsubmissions', 'mpl'), 'error' => false];
    }
    return $status;
}

/**
 * Extend the settings navigation with links for teachers.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $node
 */
function mpl_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    $cm = $settingsnav->get_page()->cm;
    if ($cm && has_capability('mod/mpl:grade', $cm->context)) {
        $node->add(get_string('submissions', 'mpl'),
            new moodle_url('/mod/mpl/submissions.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING, null, 'mpl_submissions');
    }
}

