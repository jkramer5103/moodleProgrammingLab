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
    foreach (['allowrun', 'allowcheck', 'checkgraded', 'aihints', 'exammode'] as $flag) {
        $data->$flag = empty($data->$flag) ? 0 : 1;
    }
    $data->sebmode = isset($data->sebmode) ? max(0, min(3, (int)$data->sebmode)) : 0;
    if (isset($data->sebkeys)) {
        $data->sebkeys = trim(str_replace("\r\n", "\n", (string)$data->sebkeys));
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
    mpl_save_data_files($data);
    mpl_grade_item_update($data);
    return $data->id;
}

/**
 * Save the data files of the settings form (draft area) into the activity.
 *
 * @param stdClass $data form data incl. coursemodule and datafiles (draft item id)
 */
function mpl_save_data_files(stdClass $data): void {
    if (empty($data->datafiles) || empty($data->coursemodule)) {
        return;
    }
    $context = context_module::instance($data->coursemodule);
    file_save_draft_area_files((int)$data->datafiles, $context->id, 'mod_mpl', \mod_mpl\manager::FILEAREA, 0,
        mpl_data_file_options());
}

/**
 * Options of the data file area.
 *
 * @return array
 */
function mpl_data_file_options(): array {
    return ['subdirs' => 0, 'maxbytes' => \mod_mpl\manager::MAX_FILE, 'areamaxbytes' => \mod_mpl\manager::MAX_FILES_TOTAL,
        'maxfiles' => \mod_mpl\manager::MAX_FILE_COUNT];
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
    mpl_save_data_files($data);
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
    foreach (['mpl_feedback', 'mpl_submissions', 'mpl_drafts', 'mpl_hints', 'mpl_overrides', 'mpl_examlog'] as $table) {
        $DB->delete_records($table, ['mplid' => $id]);
    }
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
    // Deduction for AI hints (percent, capped per activity).
    $penalties = [];
    if (!empty($mpl->aihints) || $DB->record_exists('mpl_hints', ['mplid' => $mpl->id])) {
        $hparams = ['mplid' => $mpl->id];
        $hwhere = 'mplid = :mplid';
        if ($userid) {
            $hwhere .= ' AND userid = :userid';
            $hparams['userid'] = $userid;
        }
        $penalties = $DB->get_records_sql_menu("SELECT userid, SUM(cost) FROM {mpl_hints} WHERE $hwhere GROUP BY userid",
            $hparams);
    }
    foreach ($grades as $g) {
        if (!empty($penalties[$g->userid])) {
            $g->fraction *= 1 - min((float)$mpl->aimax, (float)$penalties[$g->userid]) / 100;
        }
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
    $mform->addElement('advcheckbox', 'reset_mpl_overrides', get_string('resetoverrides', 'mpl'));
}

/**
 * Reset course: defaults.
 *
 * @param stdClass $course
 * @return array
 */
function mpl_reset_course_form_defaults($course) {
    return ['reset_mpl_submissions' => 1, 'reset_mpl_overrides' => 1];
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
            foreach (['mpl_feedback', 'mpl_submissions', 'mpl_drafts', 'mpl_hints', 'mpl_examlog'] as $table) {
                $DB->delete_records($table, ['mplid' => $mpl->id]);
            }
            mpl_grade_item_update($mpl, 'reset');
        }
        $status[] = ['component' => get_string('modulenameplural', 'mpl'),
            'item' => get_string('resetsubmissions', 'mpl'), 'error' => false];
    }
    if (!empty($data->reset_mpl_overrides)) {
        $mplids = $DB->get_fieldset_select('mpl', 'id', 'course = ?', [$data->courseid]);
        if ($mplids) {
            $DB->delete_records_list('mpl_overrides', 'mplid', $mplids);
        }
        $status[] = ['component' => get_string('modulenameplural', 'mpl'),
            'item' => get_string('resetoverrides', 'mpl'), 'error' => false];
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
    if ($cm && has_capability('mod/mpl:manageoverrides', $cm->context)) {
        $node->add(get_string('overrides', 'mpl'),
            new moodle_url('/mod/mpl/overrides.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING, null, 'mpl_overrides');
    }
    if ($cm && has_capability('mod/mpl:regrade', $cm->context)) {
        $node->add(get_string('regrade', 'mpl'),
            new moodle_url('/mod/mpl/regrade.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING, null, 'mpl_regrade');
    }
}


/**
 * Support notice for teachers ("need something else? write to …").
 *
 * @return string HTML
 */
function mpl_support_notice(): string {
    $email = trim((string)get_config('mod_mpl', 'supportemail'));
    if ($email === '') {
        return '';
    }
    $link = html_writer::link('mailto:' . $email, s($email));
    // Mail icon (Lucide, ISC license).
    $icon = '<svg class="mpl-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
        'stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/>' .
        '<path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
    return html_writer::div($icon . get_string('supportnotice', 'mpl', $link), 'mpl-support small text-muted');
}

/**
 * Serve the data files of a task (students download them to work locally).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file was not found
 */
function mpl_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== \mod_mpl\manager::FILEAREA) {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/mpl:view', $context);
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'mod_mpl', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Page for students who have to use Safe Exam Browser but are not (yet) in the right SEB.
 *
 * @param \mod_mpl\manager $manager
 * @return string HTML
 */
function mpl_seb_gate(\mod_mpl\manager $manager): string {
    $mode = (int)$manager->mpl->sebmode;
    $insb = \mod_mpl\seb::is_seb_useragent();
    $out = html_writer::tag('h3', get_string('sebgatetitle', 'mpl'));
    if ($insb) {
        $out .= html_writer::div(get_string('sebgatewrongconfig', 'mpl'), 'alert alert-warning');
    } else {
        $out .= html_writer::tag('p', get_string('sebgateintro', 'mpl'));
    }
    if ($mode === \mod_mpl\manager::SEB_CONFIG) {
        $out .= html_writer::div(
            html_writer::link(\mod_mpl\seb::start_link($manager->cm->id), get_string('sebstart', 'mpl'),
                ['class' => 'btn btn-primary btn-lg me-2 mr-2']) .
            html_writer::link(new moodle_url('/mod/mpl/seb.php', ['id' => $manager->cm->id]),
                get_string('sebdownload', 'mpl'), ['class' => 'btn btn-link']), 'mb-3');
        $out .= html_writer::tag('p', get_string('sebgatehelp', 'mpl'), ['class' => 'small text-muted']);
    } else {
        $out .= html_writer::tag('p', get_string('sebgateschool', 'mpl'), ['class' => 'small text-muted']);
    }
    $out .= html_writer::div('', 'mpl-seb-jscheck small text-muted', ['data-region' => 'mpl-seb-jscheck']);
    return $out;
}

/**
 * Exam mode box for teachers on the activity page: how students get into the exam.
 *
 * @param \mod_mpl\manager $manager
 * @return string HTML
 */
function mpl_exam_teacher_panel(\mod_mpl\manager $manager): string {
    global $PAGE;
    $mode = (int)$manager->mpl->sebmode;
    $copy = function(string $value, string $label) {
        return html_writer::div(
            html_writer::tag('code', s($value), ['class' => 'mpl-copy-value']) .
            html_writer::tag('button', $label, ['type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary ms-2 ml-2',
                'data-mpl-copy' => $value]), 'mpl-copy-row');
    };
    $body = html_writer::tag('p', get_string('examteacherstatus', 'mpl'));
    if ($mode === \mod_mpl\manager::SEB_CONFIG) {
        $link = \mod_mpl\seb::start_link($manager->cm->id);
        $body .= html_writer::tag('p', get_string('examteacherseblink', 'mpl'));
        $body .= $copy($link, get_string('copy', 'mpl'));
        $body .= html_writer::div(
            html_writer::link(new moodle_url('/mod/mpl/seb.php', ['id' => $manager->cm->id]), get_string('sebdownload', 'mpl')) .
            ' · ' . get_string('sebconfigkey', 'mpl') . ': ' .
            html_writer::tag('code', \mod_mpl\seb::config_key($manager->seb_settings()), ['class' => 'small']),
            'small mt-1 mb-2');
        $veyon = '"C:\\Program Files\\SafeExamBrowser\\Application\\SafeExamBrowser.exe" ' . $link;
        $steps = html_writer::tag('ol', html_writer::tag('li', get_string('examveyon1', 'mpl')) .
            html_writer::tag('li', get_string('examveyon2', 'mpl') . $copy($link, get_string('copy', 'mpl'))) .
            html_writer::tag('li', get_string('examveyon3', 'mpl') . $copy($veyon, get_string('copy', 'mpl'))) .
            html_writer::tag('li', get_string('examveyon4', 'mpl')));
        $body .= html_writer::tag('details', html_writer::tag('summary', get_string('examveyontitle', 'mpl')) . $steps .
            html_writer::div(get_string('examiservnote', 'mpl'), 'small text-muted'), ['class' => 'mpl-exam-howto']);
    } else if ($mode === \mod_mpl\manager::SEB_KEYS) {
        $body .= html_writer::tag('p', get_string('examteacherkeys', 'mpl', count($manager->seb_allowed_keys())));
    } else if ($mode === \mod_mpl\manager::SEB_ANY) {
        $body .= html_writer::tag('p', get_string('examteacherany', 'mpl'));
    } else {
        $body .= html_writer::tag('p', get_string('examteachernoseb', 'mpl'), ['class' => 'small text-muted']);
    }
    $PAGE->requires->js_call_amd('mod_mpl/exam', 'initCopy', []);
    return html_writer::div(html_writer::tag('strong', get_string('examheader', 'mpl')) . $body,
        'alert alert-info mpl-exam-teacher');
}
