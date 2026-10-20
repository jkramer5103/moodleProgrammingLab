<?php
// Dev seed: runner settings, demo course, users and example activities.
// Run via setup.sh (docker compose exec -u www-data moodle php /mpl-dev/seed.php).
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

global $DB, $USER;
\core\session\manager::set_user(get_admin());

set_config('runnerurl', 'http://runner:8000', 'mod_mpl');
set_config('runnersecret', 'dev-secret-0123456789abcdef', 'mod_mpl');
set_config('runnerverifytls', 0, 'mod_mpl');

// German language pack (UI language of the demo users).
if (!get_string_manager()->translation_exists('de', false)) {
    $controller = new \tool_langimport\controller();
    try {
        $controller->install_languagepacks('de');
    } catch (Throwable $e) {
        echo "Sprachpaket 'de' konnte nicht installiert werden: {$e->getMessage()}\n";
    }
}
$lang = file_exists($CFG->dataroot . '/lang/de/langconfig.php') ? 'de' : 'en';

$users = [];
foreach ([['lehrer', 'Lena', 'Lehrerin'], ['schueler1', 'Sam', 'Schüler'], ['schueler2', 'Mia', 'Muster'], ['e2etest', 'E2E', 'Testkonto']] as [$u, $f, $l]) {
    $existing = $DB->get_record('user', ['username' => $u]);
    if ($existing) {
        $DB->set_field('user', 'lang', $lang, ['id' => $existing->id]);
        $users[$u] = $existing;
        continue;
    }
    $id = user_create_user((object)['username' => $u, 'password' => 'Test1234!', 'firstname' => $f, 'lastname' => $l,
        'email' => "$u@example.com", 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'auth' => 'manual',
        ], true, false);
    // Set the language directly: user_create_user() checks it against a list cached before the pack was installed.
    $DB->set_field('user', 'lang', $lang, ['id' => $id]);
    $users[$u] = $DB->get_record('user', ['id' => $id]);
}

$course = $DB->get_record('course', ['shortname' => 'INF10']);
if (!$course) {
    $course = create_course((object)['fullname' => 'Informatik 10 – Python', 'shortname' => 'INF10', 'category' => 1,
        'format' => 'topics', 'numsections' => 1]);
}
$enrol = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
$roles = ['lehrer' => 'editingteacher', 'schueler1' => 'student', 'schueler2' => 'student', 'e2etest' => 'student'];
foreach ($roles as $u => $role) {
    $enrol->enrol_user($instance, $users[$u]->id, $DB->get_field('role', 'id', ['shortname' => $role]));
}

$activities = json_decode(file_get_contents(__DIR__ . '/seed_activities.json'), true);
foreach ($activities as $a) {
    if ($DB->record_exists('mpl', ['course' => $course->id, 'name' => $a['name']])) {
        continue;
    }
    $data = (object)array_merge([
        'modulename' => 'mpl', 'course' => $course->id, 'section' => 1, 'visible' => 1,
        'introeditor' => ['text' => $a['intro'], 'format' => FORMAT_HTML, 'itemid' => 0],
        'starter' => '', 'reference' => '', 'allowrun' => 1, 'allowcheck' => 1, 'checkgraded' => 0,
        'maxattempts' => 0, 'grademethod' => 0, 'duedate' => 0, 'cutoffdate' => 0, 'timeout' => 30,
        'memory' => 512, 'grade' => 10, 'cmidnumber' => '', 'aihints' => 1, 'aifree' => 1, 'aicost' => 5, 'aimax' => 35,
    ], $a);
    $data->tests = json_encode(['version' => 1, 'tests' => $a['tests']]);
    unset($data->intro);
    create_module($data);
    echo "created: {$a['name']}\n";
}
purge_all_caches();
echo "course id: {$course->id}\n";
