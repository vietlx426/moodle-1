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
 * Tests for the core_question hook listener that updates question bank names on course rename.
 *
 * @package    core_question
 * @copyright  2026 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \core_question\hook_listener
 */
final class hook_listener_test extends \advanced_testcase {
    /**
     * Test that renaming a course updates the default question bank name.
     *
     * @covers ::update_question_bank_names
     */
    public function test_update_question_bank_name_on_course_rename(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course with a temporary name.
        $oldname = 'Old Course Name';
        $course = $this->getDataGenerator()->create_course(['fullname' => $oldname]);

        // Create a default question bank using the helper (mirrors what the UI does).
        $bankname = question_bank_helper::get_bank_name_string('defaultbank', 'core_question', ['coursename' => $oldname]);
        question_bank_helper::create_default_open_instance($course, $bankname);

        // Verify the bank was created with the old name embedded.
        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(incourseids: [$course->id]);
        $this->assertNotEmpty($banks);
        $bank = reset($banks);
        $instanceid = $bank->cminfo->instance;
        $activityname = question_bank_helper::get_default_question_bank_activity_name();
        $currentname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);
        $this->assertStringContainsString($oldname, $currentname);

        // Simulate course rename (what happens after approval).
        $newname = 'New Course Name';
        $oldcourse = clone $course;
        $course->fullname = $newname;

        // Dispatch the hook.
        $hook = new after_course_updated(
            course: $course,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);

        // Verify the bank name was updated.
        $updatedname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);
        $this->assertStringContainsString($newname, $updatedname);
        $this->assertStringNotContainsString($oldname, $updatedname);
    }

    /**
     * Test that a manually customised question bank name is not changed on course rename.
     *
     * @covers ::update_question_bank_names
     */
    public function test_custom_bank_name_not_changed_on_course_rename(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $oldname = 'Old Course Name Beta';
        $course = $this->getDataGenerator()->create_course(['fullname' => $oldname]);

        // Create a bank, then manually rename it to something custom.
        $bankname = question_bank_helper::get_bank_name_string('defaultbank', 'core_question', ['coursename' => $oldname]);
        question_bank_helper::create_default_open_instance($course, $bankname);

        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(incourseids: [$course->id]);
        $bank = reset($banks);
        $instanceid = $bank->cminfo->instance;
        $activityname = question_bank_helper::get_default_question_bank_activity_name();

        // Manually rename the bank to something that doesn't contain the course name.
        $customname = 'My custom exam questions';
        $DB->set_field($activityname, 'name', $customname, ['id' => $instanceid]);

        // Simulate course rename.
        $oldcourse = clone $course;
        $course->fullname = 'New Course Name Beta';

        $hook = new after_course_updated(
            course: $course,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);

        // Verify the custom name was NOT changed.
        $updatedname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);
        $this->assertEquals($customname, $updatedname);
    }

    /**
     * Test that no update occurs when the course fullname has not changed.
     *
     * @covers ::update_question_bank_names
     */
    public function test_no_update_when_fullname_unchanged(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursename = 'Course Gamma';
        $course = $this->getDataGenerator()->create_course(['fullname' => $coursename]);

        $bankname = question_bank_helper::get_bank_name_string('defaultbank', 'core_question', ['coursename' => $coursename]);
        question_bank_helper::create_default_open_instance($course, $bankname);

        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(incourseids: [$course->id]);
        $bank = reset($banks);
        $instanceid = $bank->cminfo->instance;
        $activityname = question_bank_helper::get_default_question_bank_activity_name();
        $originalname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);

        // Simulate a course update where only something else changed (not fullname).
        $oldcourse = clone $course;
        $course->summary = 'New summary';

        $hook = new after_course_updated(
            course: $course,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);

        // Verify name is unchanged.
        $updatedname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);
        $this->assertEquals($originalname, $updatedname);
    }

    /**
     * Test that the listener handles an update whose course data has no fullname property.
     *
     * The course passed to the hook is the submitted update data, which only contains the fields
     * that were part of the update (e.g. toggling visibility), so fullname may be absent entirely.
     *
     * @covers ::update_question_bank_names
     */
    public function test_no_update_when_fullname_not_in_update_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursename = 'Course Delta';
        $course = $this->getDataGenerator()->create_course(['fullname' => $coursename]);

        $bankname = question_bank_helper::get_bank_name_string('defaultbank', 'core_question', ['coursename' => $coursename]);
        question_bank_helper::create_default_open_instance($course, $bankname);

        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(incourseids: [$course->id]);
        $bank = reset($banks);
        $instanceid = $bank->cminfo->instance;
        $activityname = question_bank_helper::get_default_question_bank_activity_name();
        $originalname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);

        // Simulate an update that only changes visibility: the data has no fullname property.
        $oldcourse = clone $course;
        $data = (object) ['id' => $course->id, 'visible' => 0];

        // Should not throw any errors and should not change the bank name.
        $hook = new after_course_updated(
            course: $data,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);

        $updatedname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);
        $this->assertEquals($originalname, $updatedname);
    }

    /**
     * Test that the listener handles a course with no question banks gracefully.
     *
     * @covers ::update_question_bank_names
     */
    public function test_no_banks_on_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Old Name']);
        $oldcourse = clone $course;
        $course->fullname = 'New Name';

        // Should not throw any errors.
        $hook = new after_course_updated(
            course: $course,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);
    }

    /**
     * Test that only matching banks are renamed when multiple banks exist on a course.
     *
     * @covers ::update_question_bank_names
     */
    public function test_multiple_banks_only_matching_renamed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $oldname = 'Test course bla bla';
        $course = $this->getDataGenerator()->create_course(['fullname' => $oldname]);

        // Create the default bank (contains the course name).
        $bankname = question_bank_helper::get_bank_name_string('defaultbank', 'core_question', ['coursename' => $oldname]);
        question_bank_helper::create_default_open_instance($course, $bankname);

        // Create a second bank with a custom name.
        $activityname = question_bank_helper::get_default_question_bank_activity_name();
        $generator = $this->getDataGenerator();
        $custombank = $generator->create_module($activityname, ['course' => $course->id, 'name' => 'My custom bank']);

        // Get instance IDs for both banks.
        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(incourseids: [$course->id]);
        $this->assertCount(2, $banks);

        // Simulate course rename.
        $oldcourse = clone $course;
        $course->fullname = 'Test course renamed';

        $hook = new after_course_updated(
            course: $course,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);

        // Verify: default bank was renamed.
        $defaultbankname = $DB->get_field($activityname, 'name', ['id' => $banks[array_key_first($banks)]->cminfo->instance]);
        $this->assertStringContainsString('Test course renamed', $defaultbankname);
        $this->assertStringNotContainsString('Test course bla bla', $defaultbankname);

        // Verify: custom bank was NOT renamed.
        $custombankname = $DB->get_field($activityname, 'name', ['id' => $custombank->id]);
        $this->assertEquals('My custom bank', $custombankname);
    }

    /**
     * Test that a very long new course name is truncated to fit the bank name column.
     *
     * @covers ::update_question_bank_names
     */
    public function test_long_new_name_is_truncated(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $oldname = 'Short';
        $course = $this->getDataGenerator()->create_course(['fullname' => $oldname]);

        // Create a bank whose name contains the old course name.
        $activityname = question_bank_helper::get_default_question_bank_activity_name();
        $bankname = $oldname . ' course question bank';
        question_bank_helper::create_default_open_instance($course, $bankname);

        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(incourseids: [$course->id]);
        $bank = reset($banks);
        $instanceid = $bank->cminfo->instance;

        // Simulate rename to a very long name (will exceed max length after replacement).
        $oldcourse = clone $course;
        $course->fullname = str_repeat('A', 300);

        $hook = new after_course_updated(
            course: $course,
            oldcourse: $oldcourse,
        );
        hook_listener::update_question_bank_names($hook);

        // Verify the name was updated but does not exceed the maximum length.
        $updatedname = $DB->get_field($activityname, 'name', ['id' => $instanceid]);
        $this->assertLessThanOrEqual(
            question_bank_helper::BANK_NAME_MAX_LENGTH,
            \core_text::strlen($updatedname),
        );
        // The old name should no longer be present.
        $this->assertStringNotContainsString($oldname, $updatedname);
    }
}
