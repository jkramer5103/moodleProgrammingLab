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


namespace mod_mpl;

use context_module;
use moodle_exception;
use stdClass;

/**
 * Business logic of a Programming Lab activity.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /** Maximum size of submitted code in bytes. */
    const MAX_CODE = 262144;

    /** @var stdClass activity record */
    public $mpl;

    /** @var \cm_info|stdClass course module */
    public $cm;

    /** @var context_module */
    public $context;

    /** @var stdClass course */
    public $course;

    /**
     * Constructor.
     *
     * @param \cm_info|stdClass $cm
     * @param stdClass $mpl
     * @param stdClass $course
     */
    public function __construct($cm, stdClass $mpl, stdClass $course) {
        $this->cm = $cm;
        $this->mpl = $mpl;
        $this->course = $course;
        $this->context = context_module::instance($cm->id);
    }

    /**
     * Load by course module id.
     *
     * @param int $cmid
     * @return self
     */
    public static function from_cmid(int $cmid): self {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'mpl');
        $mpl = $DB->get_record('mpl', ['id' => $cm->instance], '*', MUST_EXIST);
        return new self($cm, $mpl, $course);
    }

    // ----------------------------------------------------------------------
    // Configuration
    // ----------------------------------------------------------------------

    /**
     * Decode a tests definition.
     *
     * @param string|null $json
     * @return array list of tests
     */
    public static function decode_tests(?string $json): array {
        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            return [];
        }
        $tests = isset($data['tests']) && is_array($data['tests']) ? $data['tests'] : (array_is_list($data) ? $data : []);
        return array_values(array_filter($tests, 'is_array'));
    }

    /**
     * Validate/normalise the JSON coming from the test builder.
     *
     * @param string $json
     * @return string
     */
    public static function normalise_tests_json(string $json): string {
        $tests = self::decode_tests($json);
        $seen = [];
        foreach ($tests as $i => &$test) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($test['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                $id = 't' . ($i + 1) . '_' . substr(md5(uniqid('', true)), 0, 6);
            }
            $seen[$id] = true;
            $test['id'] = $id;
            $test['name'] = clean_param((string)($test['name'] ?? ''), PARAM_TEXT);
            $test['points'] = max(0, (float)($test['points'] ?? 0));
            if (!in_array($test['visibility'] ?? '', ['visible', 'nameonly', 'hidden'])) {
                $test['visibility'] = 'visible';
            }
        }
        unset($test);
        return json_encode(['version' => 1, 'tests' => $tests], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Tests of this activity.
     *
     * @return array
     */
    public function get_tests(): array {
        return self::decode_tests($this->mpl->tests);
    }

    /**
     * Total points of all tests (plot tests: sum of criteria).
     *
     * @param array $tests
     * @return float
     */
    public static function total_points(array $tests): float {
        $sum = 0.0;
        foreach ($tests as $t) {
            if (($t['type'] ?? '') === 'plot') {
                foreach (($t['plot']['criteria'] ?? []) as $c) {
                    if (is_array($c) && ($c['enabled'] ?? true)) {
                        $sum += (float)($c['points'] ?? 0);
                    }
                }
            } else {
                $sum += (float)($t['points'] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * Execution limits.
     *
     * @return array
     */
    public function limits(): array {
        return ['timeout' => (int)$this->mpl->timeout ?: 30, 'memory' => (int)$this->mpl->memory ?: 512];
    }

    // ----------------------------------------------------------------------
    // Permissions & state
    // ----------------------------------------------------------------------

    /**
     * Can the user see everything (teacher)?
     *
     * @return bool
     */
    public function can_grade(): bool {
        return has_capability('mod/mpl:grade', $this->context);
    }

    /**
     * Can the user hand in graded attempts?
     *
     * @return bool
     */
    public function can_submit(): bool {
        return has_capability('mod/mpl:submit', $this->context);
    }

    /**
     * Number of graded attempts of a user.
     *
     * @param int $userid
     * @return int
     */
    public function attempts_used(int $userid): int {
        global $DB;
        return $DB->count_records('mpl_submissions', ['mplid' => $this->mpl->id, 'userid' => $userid]);
    }

    /**
     * Remaining attempts (null = unlimited).
     *
     * @param int $userid
     * @return int|null
     */
    public function attempts_left(int $userid): ?int {
        if (empty($this->mpl->maxattempts)) {
            return null;
        }
        return max(0, (int)$this->mpl->maxattempts - $this->attempts_used($userid));
    }

    /**
     * Is handing in still possible (cut-off date)?
     *
     * @return bool
     */
    public function is_open(): bool {
        return empty($this->mpl->cutoffdate) || time() <= $this->mpl->cutoffdate;
    }

    /**
     * Why the user cannot submit right now, or null.
     *
     * @param int $userid
     * @return string|null
     */
    public function submit_blocker(int $userid): ?string {
        if (!$this->can_submit()) {
            return get_string('nosubmitcapability', 'mpl');
        }
        if (!$this->is_open()) {
            return get_string('submissionsclosed', 'mpl');
        }
        $left = $this->attempts_left($userid);
        if ($left !== null && $left <= 0) {
            return get_string('noattemptsleft', 'mpl');
        }
        return null;
    }

    // ----------------------------------------------------------------------
    // Actions
    // ----------------------------------------------------------------------

    /**
     * Ensure the code is acceptable.
     *
     * @param string $code
     * @return string
     */
    public static function clean_code(string $code): string {
        if (strlen($code) > self::MAX_CODE) {
            throw new moodle_exception('codetoolarge', 'mpl');
        }
        return str_replace("\r\n", "\n", $code);
    }

    /**
     * Store the latest code of the user.
     *
     * @param int $userid
     * @param string $code
     */
    public function save_draft(int $userid, string $code): void {
        global $DB;
        $code = self::clean_code($code);
        $existing = $DB->get_record('mpl_drafts', ['mplid' => $this->mpl->id, 'userid' => $userid]);
        if ($existing) {
            if ($existing->code !== $code) {
                $existing->code = $code;
                $existing->timemodified = time();
                $DB->update_record('mpl_drafts', $existing);
            }
        } else {
            $DB->insert_record('mpl_drafts', (object)['mplid' => $this->mpl->id, 'userid' => $userid,
                'code' => $code, 'timemodified' => time()]);
        }
    }

    /**
     * Code to show in the editor.
     *
     * @param int $userid
     * @return string
     */
    public function initial_code(int $userid): string {
        global $DB;
        $draft = $DB->get_field('mpl_drafts', 'code', ['mplid' => $this->mpl->id, 'userid' => $userid]);
        return $draft !== false ? (string)$draft : (string)$this->mpl->starter;
    }

    /**
     * Run the non-hidden tests ("Prüfen"). Not graded unless checkgraded is set.
     *
     * @param int $userid
     * @param string $code
     * @return array filtered result
     */
    public function check(int $userid, string $code): array {
        if (!empty($this->mpl->checkgraded) && $this->can_submit()) {
            return $this->submit($userid, $code);
        }
        $code = self::clean_code($code);
        $this->save_draft($userid, $code);
        $full = $this->can_grade();
        $tests = array_values(array_filter($this->get_tests(), function($t) use ($full) {
            return $full || ($t['visibility'] ?? 'visible') !== 'hidden';
        }));
        $result = (new runner())->evaluate($code, (string)$this->mpl->reference, $tests, $this->limits());
        $out = self::filter_result($result, $full);
        $out['mode'] = 'check';
        return $out;
    }

    /**
     * Graded attempt.
     *
     * @param int $userid
     * @param string $code
     * @return array filtered result incl. attempt information
     */
    public function submit(int $userid, string $code): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/mpl/lib.php');
        $code = self::clean_code($code);
        if ($blocker = $this->submit_blocker($userid)) {
            throw new moodle_exception('cannotsubmit', 'mpl', '', $blocker);
        }
        $this->save_draft($userid, $code);
        $result = (new runner())->evaluate($code, (string)$this->mpl->reference, $this->get_tests(), $this->limits());

        // Re-check the limit after the (slow) evaluation to avoid double submits.
        if ($blocker = $this->submit_blocker($userid)) {
            throw new moodle_exception('cannotsubmit', 'mpl', '', $blocker);
        }
        $max = (float)($result['max_score'] ?? 0);
        $score = (float)($result['score'] ?? 0);
        $record = (object)[
            'mplid' => $this->mpl->id,
            'userid' => $userid,
            'attempt' => $this->attempts_used($userid) + 1,
            'code' => $code,
            'score' => $score,
            'maxscore' => $max,
            'fraction' => $max > 0 ? min(1, max(0, $score / $max)) : 0,
            'status' => 'graded',
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('mpl_submissions', $record);
        mpl_update_grades($this->mpl, $userid);

        \mod_mpl\event\submission_created::create([
            'objectid' => $record->id,
            'context' => $this->context,
            'relateduserid' => $userid,
            'other' => ['attempt' => $record->attempt, 'fraction' => $record->fraction],
        ])->trigger();

        $out = self::filter_result($result, $this->can_grade());
        $out['mode'] = 'submit';
        $out['attempt'] = (int)$record->attempt;
        $out['submissionid'] = (int)$record->id;
        $out['grade'] = $this->format_grade($record->fraction);
        $out['attemptsleft'] = $this->attempts_left($userid);
        $out['currentgrade'] = $this->current_grade_text($userid);
        return $out;
    }

    /**
     * Format a fraction as grade text.
     *
     * @param float $fraction
     * @return string
     */
    public function format_grade(float $fraction): string {
        if ($this->mpl->grade > 0) {
            return format_float($fraction * $this->mpl->grade, 2) . ' / ' . format_float($this->mpl->grade, 0);
        }
        return format_float($fraction * 100, 0) . ' %';
    }

    /**
     * The grade that currently counts (according to the grade method).
     *
     * @param int $userid
     * @return string|null
     */
    public function current_grade_text(int $userid): ?string {
        global $DB;
        $subs = $DB->get_records('mpl_submissions', ['mplid' => $this->mpl->id, 'userid' => $userid],
            'attempt ASC', 'id, fraction');
        if (!$subs) {
            return null;
        }
        $fractions = array_map(function($s) {
            return (float)$s->fraction;
        }, $subs);
        $value = $this->mpl->grademethod == MPL_GRADE_LAST ? end($fractions) : max($fractions);
        return $this->format_grade($value);
    }

    /**
     * Strip everything a student must not see.
     *
     * Visible tests: full details. "Name only": name, points and pass/fail.
     * Hidden tests: only aggregated.
     *
     * @param array $result runner result
     * @param bool $full teacher view
     * @return array
     */
    public static function filter_result(array $result, bool $full): array {
        $out = [
            'score' => (float)($result['score'] ?? 0),
            'max_score' => (float)($result['max_score'] ?? 0),
            'tests' => [],
            'hidden' => ['count' => 0, 'passed' => 0, 'points' => 0.0, 'awarded' => 0.0],
            'full' => $full,
            'gate_failed' => array_values($result['gate_failed'] ?? []),
        ];
        if ($full) {
            $out['seed'] = $result['seed'] ?? null;
            $out['duration'] = $result['duration'] ?? null;
        }
        foreach (($result['tests'] ?? []) as $test) {
            $visibility = $full ? 'visible' : ($test['visibility'] ?? 'visible');
            if ($visibility === 'hidden') {
                $out['hidden']['count']++;
                $out['hidden']['points'] += (float)$test['points'];
                $out['hidden']['awarded'] += (float)$test['awarded'];
                if (($test['status'] ?? '') === 'passed') {
                    $out['hidden']['passed']++;
                }
                continue;
            }
            $t = [
                'id' => $test['id'] ?? '',
                'name' => $test['name'] ?? '',
                'type' => $test['type'] ?? '',
                'points' => (float)($test['points'] ?? 0),
                'awarded' => (float)($test['awarded'] ?? 0),
                'status' => $test['status'] ?? 'failed',
                'visibility' => $test['visibility'] ?? 'visible',
                'detail' => $visibility === 'visible',
                'instances' => [],
            ];
            if (!empty($test['config_error'])) {
                $t['config_error'] = $full ? $test['config_error'] : true;
            }
            $t['required'] = !empty($test['required']);
            $showexpected = $full || ($test['type'] ?? '') === 'code' || self::show_expected($test);
            foreach (($test['instances'] ?? []) as $inst) {
                if ($visibility === 'visible') {
                    if (!$full) {
                        unset($inst['reference_error'], $inst['check_error']);
                    }
                    if (!$showexpected) {
                        $inst = self::strip_expected($inst);
                    }
                    $t['instances'][] = $inst;
                } else {
                    $error = $inst['error'] ?? null;
                    $t['instances'][] = [
                        'passed' => !empty($inst['passed']),
                        'fraction' => (float)($inst['fraction'] ?? 0),
                        'error' => $error ? ['type' => $error['type'] ?? '', 'message' => '', 'traceback' => ''] : null,
                    ];
                }
            }
            $out['tests'][] = $t;
        }
        return $out;
    }

    /**
     * Should students see the expected result of a test?
     *
     * "auto": only for tests with random inputs – for fixed inputs the expected
     * result never changes and would give the solution away.
     *
     * @param array $test test of a runner result
     * @return bool
     */
    public static function show_expected(array $test): bool {
        $mode = $test['show_expected'] ?? 'auto';
        if ($mode === 'always') {
            return true;
        }
        if ($mode === 'never') {
            return false;
        }
        return !empty($test['random']);
    }

    /**
     * Remove everything from an instance that reveals the expected result.
     *
     * @param array $inst
     * @return array
     */
    protected static function strip_expected(array $inst): array {
        $inst['expected_display'] = null;
        $inst['expected_hidden'] = true;
        unset($inst['hint']);
        if (!empty($inst['plots'])) {
            $inst['plots']['reference'] = [];
        }
        if (!empty($inst['criteria'])) {
            foreach ($inst['criteria'] as &$c) {
                if (in_array($c['key'] ?? '', ['title', 'xlabel', 'ylabel', 'legend', 'kind', 'count'])) {
                    $c['message'] = '';
                }
            }
            unset($c);
        }
        return $inst;
    }
}
