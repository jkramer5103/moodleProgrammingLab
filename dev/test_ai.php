<?php
// Dev test of the AI hint flow (against the mock AI: docker compose --profile aimock up -d,
// runner with MPL_AI_BASE_URL=http://aimock:9000/v1). Run:
//   docker compose exec -u www-data moodle php /mpl-dev/test_ai.php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/gradelib.php');
global $DB, $PAGE;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "ok   " : "FAIL ") . $label . "\n";
    if (!$cond) {
        $fail++;
    }
}
function asuser($u) {
    global $PAGE;
    \core\session\manager::set_user($u);
    $PAGE = new moodle_page();
}
function expect_error($fn, $code, $label) {
    try {
        $fn();
        ok(false, $label);
    } catch (moodle_exception $e) {
        ok($e->errorcode === $code, $label . " ($e->errorcode)");
    }
}
$log = '/mpl-dev/aimock-log/requests.jsonl';
function lastrequest() {
    global $log;
    $lines = file($log);
    return json_decode(end($lines), true);
}

$student = $DB->get_record('user', ['username' => 'e2etest'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'lehrer'], '*', MUST_EXIST);
$mpl = $DB->get_record('mpl', ['name' => '3 · Caesar-Verschlüsselung'], '*', MUST_EXIST);
$DB->update_record('mpl', (object)['id' => $mpl->id, 'aihints' => 1, 'aifree' => 1, 'aicost' => 5, 'aimax' => 35]);
$cm = get_coursemodule_from_instance('mpl', $mpl->id)->id;
$DB->delete_records('mpl_hints', ['userid' => $student->id]);
$DB->delete_records('mpl_submissions', ['userid' => $student->id]);
\cache::make('mod_mpl', 'lastresult')->purge();

asuser($student);
$wrong = "def encrypt(text, shift):\n    return text.upper()\n";
$r = json_decode(\mod_mpl\external\check::execute($cm, $wrong)['json'], true);
ok($r['ai']['enabled'] && $r['ai']['nextcost'] == 0, 'first hint is free');

$h = json_decode(\mod_mpl\external\hint::execute($cm, $wrong, 'test', 'abc', '')['json'], true);
ok(strpos($h['hint']['hint'], 'Mock-Hinweis') === 0 && $h['hint']['cost'] == 0, 'hint 1 received, free');
ok($h['ai']['nextcost'] == 5, 'next hint costs 5 %');

$req = lastrequest();
$all = json_encode($req['body'], JSON_UNESCAPED_UNICODE);
ok(strpos(getenv('MPL_AI_BASE_URL') ?: '', 'openrouter') !== false ? $req['body']['provider']['data_collection'] === 'deny'
    : !isset($req['body']['provider']), 'provider preferences only for OpenRouter');
ok(strpos($all, 'chr((ord(ch)') !== false, 'reference solution is given to the AI');
ok(strpos($all, 'e2etest') === false && strpos($all, 'E2E') === false && strpos($all, 'Testkonto') === false,
    'no user names in the AI request');
ok(strpos($all, 'Versteckte Tests') === false, 'hidden test not in the AI request');
ok(strpos($all, "'bcd'") === false, 'expected value of fixed test (hidden by auto) not sent');
ok(strpos($all, 'encrypt(\'abc\', 1)') !== false, 'visible input is sent');

expect_error(function() use ($cm, $wrong) {
    \mod_mpl\external\hint::execute($cm, $wrong, 'test', 'hidden', '');
}, 'hintnotvisible', 'no hint for hidden test');
$ref = $mpl->reference;
expect_error(function() use ($cm, $ref) {
    \mod_mpl\external\hint::execute($cm, $ref, 'test', 'abc', '');
}, 'hintnotfailed', 'no hint for a passed test');
expect_error(function() use ($cm, $wrong) {
    \mod_mpl\external\hint::execute($cm, $wrong, 'test', 'abc', '');
}, 'hinttoofast', 'rate limit between hints');

// Follow-ups: 3 free, then stop.
for ($i = 1; $i <= 3; $i++) {
    $f = json_decode(\mod_mpl\external\hint_followup::execute($cm, $h['hint']['id'], "Was meinst du $i?")['json'], true);
}
ok(count($f['hint']['followups']) == 3 && $f['ai']['penalty'] == 0, '3 follow-ups, still no deduction');
$req = lastrequest();
ok(count($req['body']['messages']) == 2 + 1 + 5, 'follow-up thread sent to the AI');
expect_error(function() use ($cm, $h) {
    \mod_mpl\external\hint_followup::execute($cm, $h['hint']['id'], 'noch eine');
}, 'followupsexhausted', 'max. 3 follow-ups');

// Paid hints until the cap: 7 × 5 % = 35 %.
for ($i = 0; $i < 7; $i++) {
    $DB->execute('UPDATE {mpl_hints} SET timecreated = timecreated - 60 WHERE userid = ?', [$student->id]);
    $h = json_decode(\mod_mpl\external\hint::execute($cm, $wrong, 'test', 'random', '')['json'], true);
}
ok($h['ai']['penalty'] == 35 && $h['ai']['blocked'], 'after 1 free + 7 paid hints: −35 %, blocked');
$req = lastrequest();
ok(count($req['body']['messages']) == 2 && substr_count($req['body']['messages'][1]['content'], 'Mock-Hinweis') == 7,
    'earlier hints are given to the AI');
$DB->execute('UPDATE {mpl_hints} SET timecreated = timecreated - 60 WHERE userid = ?', [$student->id]);
expect_error(function() use ($cm, $wrong) {
    \mod_mpl\external\hint::execute($cm, $wrong, 'console', '', "Traceback\nTypeError: x");
}, 'hintsexhausted', 'no hints after the cap');

// Grade: full points minus 35 %.
$s = json_decode(\mod_mpl\external\submit::execute($cm, $ref)['json'], true);
$g = grade_get_grades($mpl->course, 'mod', 'mpl', $mpl->id, $student->id);
ok(abs((float)$g->items[0]->grades[$student->id]->grade - 6.5) < 0.001, 'gradebook: 10 − 35 % = 6.5 → ' .
    $g->items[0]->grades[$student->id]->grade);
ok(strpos($s['currentgrade'], '35') !== false, 'current grade mentions the deduction: ' . $s['currentgrade']);

// Console hint (other activity) and teacher (no deduction).
$m2 = $DB->get_record('mpl', ['name' => '1 · Zahlen verdoppeln'], '*', MUST_EXIST);
$DB->set_field('mpl', 'aihints', 1, ['id' => $m2->id]);
$cm2 = get_coursemodule_from_instance('mpl', $m2->id)->id;
$c = json_decode(\mod_mpl\external\hint::execute($cm2, "print(int('x'))", 'console', '',
    "Traceback (most recent call last):\n  File \"main.py\", line 1\nValueError: invalid literal")['json'], true);
ok($c['hint']['kind'] === 'console' && $c['hint']['title'] === 'ValueError' && $c['hint']['cost'] == 0, 'console hint');
expect_error(function() use ($cm2) {
    \mod_mpl\external\hint::execute($cm2, "print(1)", 'console', '', '   ');
}, 'hintnotfailed', 'no console hint without output');

asuser($teacher);
$DB->delete_records('mpl_hints', ['userid' => $teacher->id]);
for ($i = 0; $i < 2; $i++) {
    $DB->execute('UPDATE {mpl_hints} SET timecreated = timecreated - 60 WHERE userid = ?', [$teacher->id]);
    $t = json_decode(\mod_mpl\external\hint::execute($cm, $wrong, 'test', 'abc', '')['json'], true);
}
ok($t['hint']['cost'] == 0 && $t['ai']['teacher'], 'teacher hints cost nothing');

// Disabled activity.
$DB->set_field('mpl', 'aihints', 0, ['id' => $m2->id]);
asuser($student);
expect_error(function() use ($cm2) {
    \mod_mpl\external\hint::execute($cm2, "x", 'console', '', 'Error');
}, 'hintsdisabled', 'disabled activity: no hints');
$DB->set_field('mpl', 'aihints', 1, ['id' => $m2->id]);

// Task authoring assistant (teacher only).
asuser($teacher);
$coursectx = context_course::instance($mpl->course);
$start = json_decode(\mod_mpl\external\author_start::execute($coursectx->id,
    json_encode([['role' => 'teacher', 'content' => 'Funktion quadrat']]), json_encode([]))['json'], true);
ok($start['status'] === 'running' && !empty($start['id']), 'author job started');
$job = $start;
for ($i = 0; $i < 60 && $job['status'] === 'running'; $i++) {
    sleep(1);
    $job = json_decode(\mod_mpl\external\author_poll::execute($coursectx->id, $start['id'])['json'], true);
}
ok($job['status'] === 'done' && $job['result']['task']['name'] === 'Mock-Aufgabe: Quadrat', 'author job done');
ok($job['result']['validation']['reference'] == 3 && $job['result']['validation']['wrong'][0]['score'] < 3,
    'author validation: reference full, wrong solution caught');
ok($job['result']['task']['settings'] == ['aihints' => false, 'maxattempts' => 1, 'timeout' => 120],
    'author settings whitelisted and clamped');
// Assistant asks for an upload, then creates a task with a data file of its own.
$draftid = file_get_unused_draft_itemid();
\mod_mpl\manager::draft_put($draftid, 'messwerte.csv', "zeit;wert\n1;5\n");
$runjob = function($content) use ($coursectx, $draftid) {
    $start = json_decode(\mod_mpl\external\author_start::execute($coursectx->id,
        json_encode([['role' => 'teacher', 'content' => $content]]), json_encode([]), $draftid)['json'], true);
    $job = $start;
    for ($i = 0; $i < 60 && $job['status'] === 'running'; $i++) {
        sleep(1);
        $job = json_decode(\mod_mpl\external\author_poll::execute($coursectx->id, $start['id'])['json'], true);
    }
    return $job;
};
$job = $runjob('MOCK-UPLOAD Messwerte auswerten');
ok($job['status'] === 'done' && $job['result']['request_upload']['name'] === 'messwerte.csv' && empty($job['result']['task']),
    'author asks for an upload instead of inventing data');
$log = array_map('json_decode', file('/mpl-dev/aimock-log/requests.jsonl'));
$lastreq = json_encode(end($log)->body->messages);
ok(strpos($lastreq, 'messwerte.csv') !== false && strpos($lastreq, 'zeit;wert') !== false,
    'AI sees name and preview of the uploaded files');
$job = $runjob('MOCK-FILE Summe aus Datei');
ok($job['status'] === 'done' && $job['result']['task']['files'][0]['name'] === 'werte.txt' && !$job['result']['problems'],
    'author creates a data file; validation runs with it (problems: ' . json_encode($job['result']['problems'] ?? null) . ')');
ok($job['result']['validation']['reference'] == 3 && $job['result']['validation']['wrong'][0]['score'] < 3,
    'per-test file catches the hard-coded solution');

expect_error(function() use ($coursectx) {
    \mod_mpl\external\author_poll::execute($coursectx->id, 'fremderjob');
}, 'sessionnotfound', 'unknown author job rejected');
asuser($student);
try {
    \mod_mpl\external\author_start::execute($coursectx->id, json_encode([['role' => 'teacher', 'content' => 'x']]), '{}');
    ok(false, 'student cannot use the authoring AI');
} catch (Exception $e) {
    ok($e instanceof required_capability_exception, 'student cannot use the authoring AI');
}

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
