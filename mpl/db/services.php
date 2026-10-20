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
 * External functions (AJAX) of mod_mpl.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_mpl_run_start' => [
        'classname' => 'mod_mpl\external\run_start',
        'description' => 'Start an interactive run of the code.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_mpl_run_poll' => [
        'classname' => 'mod_mpl\external\run_poll',
        'description' => 'Fetch new console output of a run.',
        'type' => 'read',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_run_input' => [
        'classname' => 'mod_mpl\external\run_input',
        'description' => 'Send console input to a run.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_run_stop' => [
        'classname' => 'mod_mpl\external\run_stop',
        'description' => 'Stop a run.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_check' => [
        'classname' => 'mod_mpl\external\check',
        'description' => 'Run the visible tests against the code.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_submit' => [
        'classname' => 'mod_mpl\external\submit',
        'description' => 'Submit the code for grading.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_save_draft' => [
        'classname' => 'mod_mpl\external\save_draft',
        'description' => 'Save the current code.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_get_submission' => [
        'classname' => 'mod_mpl\external\get_submission',
        'description' => 'Get a graded attempt.',
        'type' => 'read',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_validate' => [
        'classname' => 'mod_mpl\external\validate',
        'description' => 'Teacher: run the tests against the reference solution.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
        'capabilities' => 'mod/mpl:addinstance',
    ],
    'mod_mpl_hint' => [
        'classname' => 'mod_mpl\\external\\hint',
        'description' => 'AI hint about a failed visible test or a console error.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
    'mod_mpl_hint_followup' => [
        'classname' => 'mod_mpl\\external\\hint_followup',
        'description' => 'Follow-up question about an AI hint.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
    ],
];
