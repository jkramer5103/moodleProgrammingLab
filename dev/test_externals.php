<?php
// Dev smoke test of the external functions: docker compose exec -u www-data moodle php /mpl-dev/test_externals.php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB, $PAGE;

$student = $DB->get_record('user', ['username' => 'schueler1'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'lehrer'], '*', MUST_EXIST);
$cms = [];
foreach ($DB->get_records('mpl', [], 'name') as $m) {
    $cms[$m->name] = get_coursemodule_from_instance('mpl', $m->id)->id;
}
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

asuser($student);
$DB->delete_records('mpl_submissions', ['userid' => $student->id]);
$cm = $cms['1 · Zahlen verdoppeln'];
$r = json_decode(\mod_mpl\external\check::execute($cm, "zahl = int(input('Zahl: '))\nprint(zahl * 2)\n")['json'], true);
ok($r['score'] == 10 && $r['max_score'] == 10, 'check verdoppeln correct: ' . $r['score']);
ok($r['tests'][2]['detail'] === false && !isset($r['tests'][2]['instances'][0]['inputs_display']), 'nameonly test hides details');
$r = json_decode(\mod_mpl\external\check::execute($cm, "zahl = int(input())\nprint(10)\n")['json'], true);
ok($r['score'] < 10, 'hardcoded fails: ' . $r['score']);

$cm = $cms['3 · Caesar-Verschlüsselung'];
$wrong = "def encrypt(text, shift):\n    return text.upper()\n";
$r = json_decode(\mod_mpl\external\check::execute($cm, $wrong)['json'], true);
ok(count($r['tests']) == 3 && $r['hidden']['count'] == 0, 'check excludes hidden tests');
$r = json_decode(\mod_mpl\external\submit::execute($cm, $wrong)['json'], true);
ok($r['hidden']['count'] == 1 && $r['attempt'] == 1 && $r['attemptsleft'] == 4, 'submit includes hidden summary, attempt 1');
$ref = $DB->get_field('mpl', 'reference', ['name' => '3 · Caesar-Verschlüsselung']);
$r = json_decode(\mod_mpl\external\submit::execute($cm, $ref)['json'], true);
ok($r['score'] == 10 && $r['grade'] !== '', 'submit correct: ' . $r['grade']);
require_once($CFG->libdir . '/gradelib.php');
$mplid = $DB->get_field('mpl', 'id', ['name' => '3 · Caesar-Verschlüsselung']);
$g = grade_get_grades(2, 'mod', 'mpl', $mplid, $student->id);
ok((float)$g->items[0]->grades[$student->id]->grade == 10.0, 'gradebook grade = 10 (best)');
$json = json_encode($r);
ok(strpos($json, 'chr((ord') === false, 'reference solution never in student response');

// Draft.
\mod_mpl\external\save_draft::execute($cm, "# draft\n");
ok($DB->get_field('mpl_drafts', 'code', ['userid' => $student->id, 'mplid' => $mplid]) === "# draft\n", 'draft saved');

// Interactive run.
$cm = $cms['1 · Zahlen verdoppeln'];
$sid = \mod_mpl\external\run_start::execute($cm, "n = int(input('Zahl: '))\nprint('Doppelt:', n*2)\n")['sessionid'];
$text = '';
$offset = 0;
$sent = false;
for ($i = 0; $i < 20; $i++) {
    $d = json_decode(\mod_mpl\external\run_poll::execute($cm, $sid, $offset)['json'], true);
    $offset = $d['offset'];
    foreach ($d['events'] as $e) {
        $text .= $e['d'] ?? '';
    }
    if (!$sent && strpos($text, 'Zahl:') !== false) {
        \mod_mpl\external\run_input::execute($cm, $sid, "21\n");
        $sent = true;
    }
    if ($d['done']) {
        break;
    }
}
ok(strpos($text, 'Doppelt: 42') !== false, 'interactive console: ' . trim($text));

// Session hijack protection.
asuser($DB->get_record('user', ['username' => 'schueler2']));
try {
    \mod_mpl\external\run_poll::execute($cm, $sid, 0);
    ok(false, 'other user cannot poll foreign session');
} catch (moodle_exception $e) {
    ok($e->errorcode === 'sessionnotfound', 'other user cannot poll foreign session');
}
try {
    \mod_mpl\external\get_submission::execute($cms['3 · Caesar-Verschlüsselung'],
        $DB->get_field('mpl_submissions', 'id', ['userid' => $student->id, 'attempt' => 1]));
    ok(false, 'other student cannot read submission');
} catch (moodle_exception $e) {
    ok(true, 'other student cannot read submission (' . $e->errorcode . ')');
}

// Teacher sees hidden test details when checking; validate works.
asuser($teacher);
$r = json_decode(\mod_mpl\external\check::execute($cms['3 · Caesar-Verschlüsselung'], $ref)['json'], true);
ok(count($r['tests']) == 4 && $r['full'], 'teacher check includes hidden tests with details');
$m = $DB->get_record('mpl', ['name' => '5 · Temperaturverlauf (Diagramm)']);
$ctx = context_module::instance($cms[$m->name]);
$r = json_decode(\mod_mpl\external\validate::execute($ctx->id, $m->reference, $m->reference, $m->tests, 30, 512)['json'], true);
ok($r['score'] == $r['max_score'] && $r['max_score'] == 10, 'validate plot reference: ' . $r['score'] . '/' . $r['max_score']);
$m = $DB->get_record('mpl', ['name' => '4 · Bankkonto (Klasse)']);
$r = json_decode(\mod_mpl\external\validate::execute($ctx->id, $m->reference, $m->reference, $m->tests, 30, 512)['json'], true);
ok($r['score'] == 10, 'validate class reference: ' . $r['score']);
$m = $DB->get_record('mpl', ['name' => '2 · Primzahlen bis 100']);
$lines = "for n in range(2, 101):\n    if all(n % d for d in range(2, n)):\n        print(n)\n";
$r = json_decode(\mod_mpl\external\validate::execute($ctx->id, $lines, $m->reference, $m->tests, 30, 512)['json'], true);
ok($r['score'] == 10, 'primes one-per-line accepted: ' . $r['score']);

// Students cannot validate.
asuser($student);
$DB->delete_records('mpl_submissions', ['userid' => $student->id]);
try {
    \mod_mpl\external\validate::execute($ctx->id, 'print(1)', '', '[]', 30, 512);
    ok(false, 'student cannot use validate');
} catch (Exception $e) {
    ok(true, 'student cannot use validate');
}
echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
