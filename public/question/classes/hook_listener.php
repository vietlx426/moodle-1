<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_question;

use core_course\hook\after_course_updated;
use core_question\local\bank\question_bank_helper;

/**
 * Hook listener for core_question.
 *
 * @package    core_question
 * @copyright  2026 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Update the default question bank name when a course is renamed.
     *
     * When a course fullname changes, any shared question bank whose name contains the old
     * course fullname will have the old name replaced with the new one. This handles the case
     * where the default question bank was created with a temporary name (e.g. containing
     * an approval status) and the course is later renamed.
     *
     * @param after_course_updated $hook The course updated hook.
     */
    public static function update_question_bank_names(after_course_updated $hook): void {
        global $DB;

        $course = $hook->course;
        $oldcourse = $hook->oldcourse;

        // The course passed to this hook is the submitted update data, which only contains the
        // fields that were part of the update. If fullname was not part of the update, there is
        // nothing to do. Likewise bail if the old course record has no fullname to compare against.
        if (!isset($course->fullname) || !isset($oldcourse->fullname)) {
            return;
        }

        // Only act if the course fullname has actually changed.
        if ($course->fullname === $oldcourse->fullname) {
            return;
        }

        $activityname = question_bank_helper::get_default_question_bank_activity_name();

        // Query the qbank instances directly to avoid modinfo cache issues during hook execution.
        $sql = "SELECT q.id, q.name
                  FROM {{$activityname}} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                 WHERE cm.course = :courseid
                       AND cm.deletioninprogress = 0";
        $banks = $DB->get_records_sql($sql, [
            'modulename' => $activityname,
            'courseid' => $course->id,
        ]);

        $renamed = false;
        foreach ($banks as $bank) {
            // Only rename if the old course fullname is present in the bank name.
            if (str_contains($bank->name, $oldcourse->fullname)) {
                $newname = str_replace($oldcourse->fullname, $course->fullname, $bank->name);
                if (\core_text::strlen($newname) > question_bank_helper::BANK_NAME_MAX_LENGTH) {
                    $newname = shorten_text($newname, question_bank_helper::BANK_NAME_MAX_LENGTH);
                }
                $DB->set_field($activityname, 'name', $newname, ['id' => $bank->id]);
                $renamed = true;
            }
        }

        // Rebuild the course cache so the updated names are visible immediately.
        // This is needed because the course cache was already rebuilt before this hook fired.
        if ($renamed) {
            rebuild_course_cache($course->id, true);
        }
    }
}
