<?php
// Dev test of data files, turtle tests, individual dates, regrading, feedback, exam mode and Safe Exam Browser:
//   docker compose exec -u www-data moodle php /mpl-dev/test_features.php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/mpl/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/group/lib.php');
global $DB, $PAGE, $USER;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "ok   " : "FAIL ") . $label . "\n";
    if (!$cond) {
        $fail++;
    }
}
function asuser($u) {
    \core\session\manager::set_user($u);
    global $PAGE;
    $PAGE = new moodle_page();
}
function expect_error(callable $fn, string $code, string $label) {
    try {
        $fn();
        ok(false, $label . ' (no exception)');
    } catch (moodle_exception $e) {
        ok($e->errorcode === $code, $label . ' (' . $e->errorcode . ')');
    }
}
function json_call($result) {
    return json_decode($result['json'], true);
}

$admin = get_admin();
$student = $DB->get_record('user', ['username' => 'schueler1'], '*', MUST_EXIST);
$student2 = $DB->get_record('user', ['username' => 'schueler2'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'lehrer'], '*', MUST_EXIST);
$course = $DB->get_record('course', ['shortname' => 'INF10'], '*', MUST_EXIST);

asuser($admin);
// Clean up an earlier run.
foreach ($DB->get_records_select('mpl', "course = ? AND name LIKE 'TEST %'", [$course->id]) as $old) {
    course_delete_module(get_coursemodule_from_instance('mpl', $old->id)->id);
}

$reference = "summe = 0\nwith open('noten.csv', encoding='utf-8') as f:\n    next(f)\n    for z in f:\n" .
    "        summe += int(z.strip().split(';')[1])\nprint(summe)\n";
$tests = [
    ['id' => 'sum', 'name' => 'Summe', 'type' => 'io', 'points' => 2, 'expected' => ['source' => 'reference']],
    ['id' => 'other', 'name' => 'Andere Daten', 'type' => 'io', 'points' => 1, 'visibility' => 'hidden',
        'expected' => ['source' => 'reference'], 'files' => [['name' => 'noten.csv', 'content' => "n;p\nX;1\nY;2\n"]]],
    ['id' => 'rnd', 'name' => 'Zufall', 'type' => 'io', 'points' => 1, 'repeat' => 3,
        'inputs' => [['kind' => 'int', 'min' => 1, 'max' => 1000]], 'expected' => ['source' => 'none'],
        'properties' => [['type' => 'nonempty']]],
];
$data = (object)[
    'modulename' => 'mpl', 'course' => $course->id, 'section' => 1, 'visible' => 1, 'name' => 'TEST Dateien',
    'introeditor' => ['text' => '<p>Summe der Punkte in noten.csv</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
    'starter' => '', 'reference' => $reference, 'tests' => json_encode(['version' => 1, 'tests' => $tests]),
    'allowrun' => 1, 'allowcheck' => 1, 'checkgraded' => 0, 'maxattempts' => 2, 'grademethod' => 0, 'duedate' => 0,
    'cutoffdate' => 0, 'timeout' => 30, 'memory' => 512, 'grade' => 10, 'cmidnumber' => '', 'aihints' => 1, 'aifree' => 1,
    'aicost' => 5, 'aimax' => 35,
];
// Data file via a draft area, as the settings form does it.
$draftid = file_get_unused_draft_itemid();
\mod_mpl\manager::draft_put($draftid, 'noten.csv', "name;punkte\nAnna;12\nBen;7\nCem;15\n");
expect_error(function() use ($draftid) {
    \mod_mpl\manager::draft_put($draftid, 'main.py', 'x');
}, 'filenameinvalid', 'data file main.py rejected');
$data->datafiles = $draftid;
$mod = create_module($data);
$cmid = $mod->coursemodule;
$manager = \mod_mpl\manager::from_cmid($cmid);
ok(count($manager->stored_files()) === 1 && $manager->data_files()[0]['name'] === 'noten.csv', 'data file saved with the form');

// ---------------------------------------------------------------------------
// Data files.
asuser($student);
$r = json_call(\mod_mpl\external\check::execute($cmid, $reference));
ok($r['score'] == 3 && trim($r['tests'][0]['instances'][0]['actual_display']) === '34', 'check reads the data file: ' . $r['score']);
$r = json_call(\mod_mpl\external\submit::execute($cmid, "print(34)\n"));
ok($r['score'] == 3 && $r['hidden']['count'] == 1 && $r['hidden']['awarded'] == 0,
    'hard-coded answer fails the hidden test with other data');
$sid = \mod_mpl\external\run_start::execute($cmid, "print(open('noten.csv').read().count(';'))\n")['sessionid'];
$out = '';
$offset = 0;
for ($i = 0; $i < 40; $i++) {
    $p = json_decode(\mod_mpl\external\run_poll::execute($cmid, $sid, $offset)['json'], true);
    foreach ($p['events'] as $e) {
        $out .= $e['d'] ?? '';
    }
    $offset = $p['offset'];
    if ($p['done']) {
        break;
    }
}
ok(trim($out) === '4', 'console reads the data file: ' . trim($out));
$info = \mod_mpl\manager::files_info($manager->stored_files());
ok($info[0]['text'] && strpos($info[0]['preview'], 'Anna;12') !== false && strpos($info[0]['url'], 'pluginfile.php') !== false,
    'students get preview and download link');

// Validate (teacher) uses the draft files of the form.
asuser($teacher);
$coursectx = context_course::instance($course->id);
$draft2 = file_get_unused_draft_itemid();
\mod_mpl\manager::draft_put($draft2, 'noten.csv', "n;p\nA;100\n");
$v = json_call(\mod_mpl\external\validate::execute($coursectx->id, $reference, $reference,
    json_encode(['tests' => [$tests[0]]]), 30, 512, $draft2));
ok(trim($v['tests'][0]['instances'][0]['actual_display']) === '100', 'validate uses the files of the form');
$list = json_call(\mod_mpl\external\datafile_list::execute($coursectx->id, $draft2));
ok(count($list) === 1 && $list[0]['name'] === 'noten.csv', 'datafile_list');
$list = json_call(\mod_mpl\external\datafile_put::execute($coursectx->id, $draft2, 'b.txt', base64_encode('hi')));
$list = json_call(\mod_mpl\external\datafile_delete::execute($coursectx->id, $draft2, 'noten.csv'));
ok(count($list) === 1 && $list[0]['name'] === 'b.txt', 'datafile_put / datafile_delete');
asuser($student);
try {
    \mod_mpl\external\datafile_list::execute($coursectx->id, $draft2);
    ok(false, 'student cannot use the data file API');
} catch (Exception $e) {
    ok($e instanceof required_capability_exception, 'student cannot use the data file API');
}

// ---------------------------------------------------------------------------
// Turtle.
asuser($teacher);
$turtleref = "import turtle\nt = turtle.Turtle()\nt.color('red')\nfor _ in range(3):\n    t.forward(100)\n    t.left(120)\n";
$turtletest = [['id' => 'tri', 'name' => 'Dreieck', 'type' => 'turtle',
    'turtle' => ['tolerance' => 3, 'placement' => 'exact', 'criteria' => ['drawing' => ['enabled' => true, 'points' => 1],
        'lines' => ['enabled' => true, 'points' => 3], 'colors' => ['enabled' => true, 'points' => 1]]]]];
$v = json_call(\mod_mpl\external\validate::execute($coursectx->id, $turtleref, $turtleref, json_encode(['tests' => $turtletest]),
    30, 512));
ok($v['score'] == 5 && $v['max_score'] == 5 && count($v['tests'][0]['instances'][0]['plots']['student']) === 1,
    'turtle reference: 5/5 with picture');
$v = json_call(\mod_mpl\external\validate::execute($coursectx->id, str_replace('120', '90', $turtleref), $turtleref,
    json_encode(['tests' => $turtletest]), 30, 512));
ok($v['score'] == 2, 'wrong turtle drawing: only drawing + colour: ' . $v['score']);
ok(\mod_mpl\manager::total_points(\mod_mpl\manager::decode_tests(json_encode(['tests' => $turtletest]))) == 5,
    'total points count turtle criteria');

// ---------------------------------------------------------------------------
// Individual dates and attempts.
asuser($student);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->attempts_left($student->id) === 1, 'one attempt left before override');
$DB->insert_record('mpl_overrides', (object)['mplid' => $manager->mpl->id, 'userid' => $student->id, 'groupid' => null,
    'duedate' => null, 'cutoffdate' => null, 'maxattempts' => 5, 'timemodified' => time()]);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->attempts_left($student->id) === 4 && $manager->effective($student->id)->override, 'user override: 5 attempts');
// Closed for everyone, open for a group.
$DB->set_field('mpl', 'cutoffdate', time() - 3600, ['id' => $manager->mpl->id]);
$groupid = groups_create_group((object)['courseid' => $course->id, 'name' => 'TEST Nachschreiber']);
groups_add_member($groupid, $student2->id);
$groupid2 = groups_create_group((object)['courseid' => $course->id, 'name' => 'TEST Gruppe 2']);
groups_add_member($groupid2, $student2->id);
$DB->insert_record('mpl_overrides', (object)['mplid' => $manager->mpl->id, 'userid' => null, 'groupid' => $groupid,
    'duedate' => null, 'cutoffdate' => time() + 3600, 'maxattempts' => 3, 'timemodified' => time()]);
$DB->insert_record('mpl_overrides', (object)['mplid' => $manager->mpl->id, 'userid' => null, 'groupid' => $groupid2,
    'duedate' => null, 'cutoffdate' => time() + 7200, 'maxattempts' => 0, 'timemodified' => time()]);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->submit_blocker($student->id) === get_string('submissionsclosed', 'mpl'), 'cut-off date closes submissions');
$eff = $manager->effective($student2->id);
ok($manager->is_open($student2->id) && $eff->cutoffdate > time() + 3700 && $eff->maxattempts === 0,
    'two group overrides: most generous values');
asuser($student2);
$r = json_call(\mod_mpl\external\submit::execute($cmid, $reference));
ok($r['score'] == 4 || $r['score'] == 3, 'group member can still submit');
asuser($student);
expect_error(function() use ($cmid, $reference) {
    \mod_mpl\external\submit::execute($cmid, $reference);
}, 'cannotsubmit', 'others cannot submit after cut-off');
$dates = \core\activity_dates::get_dates_for_module(get_fast_modinfo($course->id, $student2->id)->get_cm($cmid),
    $student2->id);
ok($dates && end($dates)['timestamp'] == $eff->cutoffdate, 'activity dates show the individual cut-off');
$DB->set_field('mpl', 'cutoffdate', 0, ['id' => $manager->mpl->id]);
$DB->delete_records('mpl_overrides', ['mplid' => $manager->mpl->id]);

// ---------------------------------------------------------------------------
// Regrading.
asuser($teacher);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->outdated_count() === 0, 'no outdated submissions yet');
$subs = $DB->get_records('mpl_submissions', ['mplid' => $manager->mpl->id], 'id');
$before = array_map(function($s) {
    return [$s->fraction, json_decode($s->result, true)['tests'][2]['instances'][0]['inputs_display']];
}, $subs);
// The teacher fixes the hidden test: it was worth too little.
$tests[1]['points'] = 7;
$DB->set_field('mpl', 'tests', \mod_mpl\manager::normalise_tests_json(json_encode(['tests' => $tests])),
    ['id' => $manager->mpl->id]);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->outdated_count() === count($subs), 'changed tests: all submissions outdated');
$preview = $manager->regrade(false);
ok($preview['total'] === count($subs) && $preview['changed'] >= 1, 'dry run finds changes: ' . $preview['changed']);
ok($DB->get_field('mpl_submissions', 'fraction', ['id' => array_key_first($subs)]) == reset($before)[0],
    'dry run writes nothing');
$applied = $manager->regrade(true);
ok($applied['changed'] === $preview['changed'] && $manager->outdated_count() === 0, 'apply: same changes, nothing outdated');
$after = $DB->get_records('mpl_submissions', ['mplid' => $manager->mpl->id], 'id');
$hard = $DB->get_record_select('mpl_submissions', 'mplid = ? AND userid = ? AND code = ?',
    [$manager->mpl->id, $student->id, "print(34)\n"], '*', MUST_EXIST);
ok(abs($hard->fraction - 3 / 10) < 0.0001 && $hard->timeregraded > 0, 'hard-coded attempt now 3/10');
$same = true;
foreach ($after as $id => $s) {
    $same = $same && json_decode($s->result, true)['tests'][2]['instances'][0]['inputs_display'] === $before[$id][1];
}
ok($same, 'regrading reuses the random inputs of each attempt');
$g = grade_get_grades($course->id, 'mod', 'mpl', $manager->mpl->id, $student->id);
ok(abs((float)$g->items[0]->grades[$student->id]->grade - 3.0) < 0.001, 'gradebook updated after regrade: ' .
    $g->items[0]->grades[$student->id]->grade);

// ---------------------------------------------------------------------------
// Feedback.
$sink = $sink ?? null;
$DB->delete_records('notifications', ['useridto' => $student->id]);
$fb = json_call(\mod_mpl\external\feedback_save::execute($cmid, $hard->id, 0, 1, 'Hier wird nur ausgegeben, nicht gerechnet.'));
$fb = json_call(\mod_mpl\external\feedback_save::execute($cmid, $hard->id, 0, 0, 'Bitte lies die Datei ein.'));
ok(count($fb) === 2 && $fb[0]['line'] === 0 && $fb[1]['line'] === 1, 'teacher writes a general and a line comment');
ok($DB->count_records('notifications', ['useridto' => $student->id, 'component' => 'mod_mpl']) === 1,
    'student notified once (not per comment)');
$fb = json_call(\mod_mpl\external\feedback_save::execute($cmid, $hard->id, $fb[1]['id'], 1, 'Geändert'));
ok($fb[1]['comment'] === 'Geändert', 'comment edited');
asuser($student);
$sub = json_call(\mod_mpl\external\get_submission::execute($cmid, $hard->id));
ok(count($sub['feedback']) === 2, 'student sees the feedback of the attempt');
ok(\mod_mpl\manager::from_cmid($cmid)->feedback_counts($student->id)[$hard->id] == 2, 'feedback counts for the attempt list');
try {
    \mod_mpl\external\feedback_save::execute($cmid, $hard->id, 0, 0, 'selbst');
    ok(false, 'student cannot write feedback');
} catch (Exception $e) {
    ok($e instanceof required_capability_exception, 'student cannot write feedback');
}
asuser($teacher);
$fb = json_call(\mod_mpl\external\feedback_save::execute($cmid, $hard->id, $fb[1]['id'], 1, ''));
ok(count($fb) === 1, 'empty comment deletes it');

// ---------------------------------------------------------------------------
// Exam mode.
asuser($admin);
$DB->set_field('mpl', 'exammode', 1, ['id' => $manager->mpl->id]);
asuser($student);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->hint_state($student->id) === ['enabled' => false], 'exam mode: no AI hints');
expect_error(function() use ($cmid) {
    \mod_mpl\external\hint::execute($cmid, 'x', 'console', '', 'NameError');
}, 'hintsdisabled', 'exam mode: hint request rejected');
\mod_mpl\external\exam_log::execute($cmid, 'left', 'tab', 12);
\mod_mpl\external\exam_log::execute($cmid, 'paste', '300 Zeichen', 0);
$sum = $manager->exam_summary()[$student->id];
ok($sum->lefts == 1 && $sum->seconds == 12 && $sum->pastes == 1, 'exam events logged');
$r = json_call(\mod_mpl\external\check::execute($cmid, $reference));
ok($r['score'] > 0, 'without SEB requirement students can work');

// Safe Exam Browser, configuration from Moodle.
asuser($admin);
$DB->set_field('mpl', 'sebmode', \mod_mpl\manager::SEB_CONFIG, ['id' => $manager->mpl->id]);
$DB->set_field('mpl', 'sebquitpassword', 'ende', ['id' => $manager->mpl->id]);
asuser($student);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->seb_required(), 'SEB required for students');
expect_error(function() use ($cmid, $reference) {
    \mod_mpl\external\check::execute($cmid, $reference);
}, 'sebrequired', 'check without SEB rejected');
expect_error(function() use ($cmid) {
    \mod_mpl\external\run_start::execute($cmid, 'print(1)');
}, 'sebrequired', 'console without SEB rejected');
expect_error(function() use ($cmid) {
    \mod_mpl\external\save_draft::execute($cmid, 'print(1)');
}, 'sebrequired', 'saving without SEB rejected');
$settings = $manager->seb_settings();
$xml = \mod_mpl\seb::plist($settings);
$key = \mod_mpl\seb::config_key($settings);
ok($key === \quizaccess_seb\config_key::generate($xml)->get_hash(), 'config key equals Moodle core implementation');
$qt = $settings;
ksort($qt, SORT_STRING);
ok(hash('sha256', json_encode($qt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === $key,
    'config key also equal with case-sensitive key order (Linux client seb-linux)');
ok(strpos($xml, '<key>startURL</key>') !== false && strpos($xml, hash('sha256', 'ende')) !== false &&
    strpos($xml, '>ende<') === false, 'config: start page, hashed quit password only');
$url = (new moodle_url('/mod/mpl/view.php', ['id' => $cmid]))->out(false);
ok(!$manager->seb_keys_valid($url, hash('sha256', $url . str_repeat('a', 64)), null, true), 'wrong config key rejected');
ok($manager->seb_keys_valid($url, hash('sha256', $url . $key), null, true), 'right config key hash accepted');
$res = \mod_mpl\external\seb_validate::execute($cmid, $url, hash('sha256', $url . $key), '');
ok($res['ok'] && $manager->seb_session_ok(), 'JS API check sets the session flag');
$r = json_call(\mod_mpl\external\check::execute($cmid, $reference));
ok($r['score'] > 0, 'check works inside SEB');
$res = \mod_mpl\external\seb_validate::execute($cmid, 'https://evil.example/mod/mpl/view.php?id=' . $cmid,
    hash('sha256', 'https://evil.example/mod/mpl/view.php?id=' . $cmid . $key), '');
ok(!$res['ok'] && !$manager->seb_session_ok(), 'keys for another URL rejected (and session flag removed)');
ok($DB->count_records('mpl_examlog', ['mplid' => $manager->mpl->id, 'userid' => $student->id, 'event' => 'sebfail']) === 1,
    'failed SEB check logged');
asuser($teacher);
$tm = \mod_mpl\manager::from_cmid($cmid);
ok(!$tm->seb_required(), 'teachers do not need SEB');
// School configuration with own keys (config key or browser exam key).
$DB->set_field('mpl', 'sebmode', \mod_mpl\manager::SEB_KEYS, ['id' => $manager->mpl->id]);
$bek = str_repeat('b', 64);
$DB->set_field('mpl', 'sebkeys', "  " . strtoupper($bek) . "\nunsinn\n", ['id' => $manager->mpl->id]);
asuser($student);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->seb_allowed_keys() === [$bek], 'own keys parsed (case-insensitive, junk ignored)');
ok($manager->seb_keys_valid($url, null, hash('sha256', $url . $bek), false), 'browser exam key accepted');
ok(!$manager->seb_keys_valid($url, null, null, true), 'SEB without keys rejected in key mode');
$DB->set_field('mpl', 'sebmode', \mod_mpl\manager::SEB_ANY, ['id' => $manager->mpl->id]);
$manager = \mod_mpl\manager::from_cmid($cmid);
ok($manager->seb_keys_valid($url, null, null, true) && !$manager->seb_keys_valid($url, null, null, false),
    'any SEB: user agent is enough');

// ---------------------------------------------------------------------------
// Backup and restore (with files, overrides, feedback, exam log).
asuser($admin);
$DB->insert_record('mpl_overrides', (object)['mplid' => $manager->mpl->id, 'userid' => $student->id, 'groupid' => null,
    'duedate' => time() + 86400, 'cutoffdate' => null, 'maxattempts' => 9, 'timemodified' => time()]);
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
$bc = new backup_controller(backup::TYPE_1ACTIVITY, $cmid, backup::FORMAT_MOODLE, backup::INTERACTIVE_NO,
    backup::MODE_GENERAL, $admin->id);
$bc->get_plan()->get_setting('users')->set_value(true);
$bc->execute_plan();
$file = $bc->get_results()['backup_destination'];
$bc->destroy();
$folder = 'mpltest' . time();
$file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $folder);
$rc = new restore_controller($folder, $course->id, backup::INTERACTIVE_NO, backup::MODE_GENERAL, $admin->id,
    backup::TARGET_EXISTING_ADDING);
$rc->get_plan()->get_setting('users')->set_value(true);
$rc->execute_precheck();
$rc->execute_plan();
$rc->destroy();
$file->delete();
$copy = $DB->get_record_select('mpl', 'course = ? AND name = ? AND id <> ?', [$course->id, 'TEST Dateien', $manager->mpl->id],
    '*', MUST_EXIST);
$copycm = get_coursemodule_from_instance('mpl', $copy->id);
$cm2 = \mod_mpl\manager::from_cmid($copycm->id);
ok(count($cm2->stored_files()) === 1 && $copy->exammode == 1 && $copy->sebmode == \mod_mpl\manager::SEB_ANY,
    'restore: data file and exam settings');
ok($DB->count_records('mpl_overrides', ['mplid' => $copy->id]) === 1 && $DB->count_records('mpl_feedback', ['mplid' => $copy->id]) === 1
    && $DB->count_records('mpl_examlog', ['mplid' => $copy->id]) >= 3, 'restore: overrides, feedback, exam log');
$fbcopy = $DB->get_record('mpl_feedback', ['mplid' => $copy->id]);
ok($DB->record_exists('mpl_submissions', ['id' => $fbcopy->submissionid, 'mplid' => $copy->id]), 'restore: feedback linked to the new attempt');

// Privacy export lists the new data.
$contexts = \mod_mpl\privacy\provider::get_contexts_for_userid($student->id)->get_contextids();
ok(in_array(context_module::instance($cmid)->id, $contexts), 'privacy: context found');

// Clean up.
course_delete_module($copycm->id);
course_delete_module($cmid);
groups_delete_group($groupid);
groups_delete_group($groupid2);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
