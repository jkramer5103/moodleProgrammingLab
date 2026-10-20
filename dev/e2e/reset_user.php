<?php
// Removes everything the e2e test user did, so every run starts clean.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/mod/mpl/lib.php');
$u = $DB->get_record('user', ['username' => 'e2etest'], '*', MUST_EXIST);
foreach (['mpl_submissions', 'mpl_drafts', 'mpl_hints'] as $t) {
    $DB->delete_records($t, ['userid' => $u->id]);
}
foreach ($DB->get_records('mpl') as $mpl) {
    mpl_update_grades($mpl, $u->id);
}
\cache::make('mod_mpl', 'lastresult')->purge();
