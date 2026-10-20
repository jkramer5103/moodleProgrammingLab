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

use core\activity_dates;

/**
 * Dates shown in the activity header and on the course page.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates extends activity_dates {

    /**
     * Dates of this activity.
     *
     * @return array
     */
    protected function get_dates(): array {
        global $DB;
        $mpl = $DB->get_record('mpl', ['id' => $this->cm->instance], 'duedate, cutoffdate', MUST_EXIST);
        $dates = [];
        if ($mpl->duedate) {
            $dates[] = ['dataid' => 'duedate', 'label' => get_string('activitydate:due', 'mpl'),
                'timestamp' => (int)$mpl->duedate];
        }
        if ($mpl->cutoffdate) {
            $dates[] = ['dataid' => 'cutoffdate', 'label' => get_string('activitydate:cutoff', 'mpl'),
                'timestamp' => (int)$mpl->cutoffdate];
        }
        return $dates;
    }
}
