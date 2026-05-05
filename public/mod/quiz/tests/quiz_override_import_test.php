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

/**
 * Contains the class containing unit tests for the quiz overrides import process.
 *
 * @package   mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quiz;

use advanced_testcase;
use context_module;
use mod_quiz\quiz_settings;
use stdClass;


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/csvlib.class.php');


/**
 * Class containing unit tests for the quiz overrides import process.
 *
 * @package mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_quiz\process_override_imports
 */
final class quiz_override_import_test extends advanced_testcase {
    /** @var \stdClass $course Test course to contain quiz. */
    protected $course;

    /** @var \stdClass $quiz A test quiz. */
    protected $quiz;

    /** @var context The quiz context. */
    protected $context;

    /** @var stdClass The course_module. */
    protected $cm;

    /** @var stdClass[] Array of students. */
    protected $students;

    /** @var stdClass[] Array of groups. */
    protected $groups;

    /**
     * Create a course with a quiz, students, and groups.
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        $this->students[] = $this->getDataGenerator()->create_user(['username' => 'student1']);
        $this->students[] = $this->getDataGenerator()->create_user(['username' => 'student2']);
        $this->students[] = $this->getDataGenerator()->create_user(['username' => 'student3']);

        $this->getDataGenerator()->enrol_user($this->students[0]->id, $this->course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($this->students[1]->id, $this->course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($this->students[2]->id, $this->course->id, $studentrole->id);

        // Groups with idnumbers so groupname-fallback tests can resolve by name.
        $this->groups[] = $this->getDataGenerator()->create_group(
            ['courseid' => $this->course->id, 'idnumber' => 'GRP001']
        );
        $this->groups[] = $this->getDataGenerator()->create_group(
            ['courseid' => $this->course->id, 'idnumber' => 'GRP002']
        );

        $this->getDataGenerator()->create_group_member(['userid' => $this->students[0]->id, 'groupid' => $this->groups[0]->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $this->students[1]->id, 'groupid' => $this->groups[1]->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $this->students[2]->id, 'groupid' => $this->groups[1]->id]);
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $this->course->id]);

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->quiz = $quizgenerator->create_instance([
            'course' => $this->course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => 2,
        ]);
        $this->context = context_module::instance($this->quiz->cmid);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
    }

    /**
     * Build a CSV string from an array of associative-array rows.
     * The header row is derived from the keys of the first row.
     *
     * @param array $rows Rows to encode, each as an associative array of column => value.
     * @return string CSV-encoded content (header + rows, newline-terminated).
     */
    private function build_csv(array $rows): string {
        if (empty($rows)) {
            return '';
        }
        $csv = implode(',', array_keys($rows[0])) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }
        return $csv;
    }

    /**
     * Load CSV content into a new csv_import_reader and return it.
     *
     * @param string $csvcontent CSV string to feed into the importer.
     * @return \csv_import_reader Reader pre-loaded with the supplied content.
     */
    private function make_importer(string $csvcontent): \csv_import_reader {
        $importid = \csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new \csv_import_reader($importid, 'importquizoverrides');
        $importer->load_csv_content($csvcontent, 'UTF-8', 'comma');
        return $importer;
    }

    /**
     * Test import functionality with invalid headers.
     */
    public function test_import_invalid_headers(): void {
        // Provide a header that does not match the expected user format.
        $csv = "userid,incorrectheader,timeopen,timeclose,timelimit,attempts,password,set_password\n";
        $csv .= "{$this->students[0]->id},,2024-01-01 08:00 +10:00,2024-01-01 10:00 +10:00,3600,1,mypassword1,\n";

        $process = new process_override_imports(
            $this->make_importer($csv),
            'user',
            $this->quiz,
            $this->course,
        );

        $result = $process->process();
        $this->assertFalse($result, 'Processing should fail due to invalid headers.');
        $this->assertNotEmpty($process->get_header_error(), 'There should be a header error message.');
    }

    /**
     * Test import functionality with valid group overrides.
     */
    public function test_import_valid_group_overrides(): void {
        global $DB;

        $csvdata = [
            [
                'groupid' => (string) $this->groups[0]->id,
                'groupname' => '',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'grouppassword1',
                'set_password' => '1',
            ],
            [
                'groupid' => (string) $this->groups[1]->id,
                'groupname' => '',
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-02 10:00 +10:00',
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'set_password' => '1',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'group',
            $this->quiz,
            $this->course,
        );

        $processed = $process->process();
        $this->assertTrue($processed, 'Processing the uploaded CSV data should be successful.');
        $this->assertCount(2, $process->overrides, 'The number of processed overrides should be 2.');
        $this->assertTrue($process->canimport, 'No errors should have occurred when processing and validating each row.');

        $potentialerrors = ['groupid', 'timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'set_password'];
        foreach ($process->overrides as $override) {
            foreach ($potentialerrors as $potentialerror) {
                $this->assertArrayNotHasKey(
                    $potentialerror,
                    $override->errors,
                    "The {$potentialerror} column contains an invalid value.",
                );
            }
        }

        $imported = $process->import();
        $this->assertTrue($imported, 'Importing the processed CSV data should be successful.');

        $groupids = [$this->groups[0]->id, $this->groups[1]->id];
        foreach (array_values($csvdata) as $i => $row) {
            $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz->id, 'groupid' => $groupids[$i]]);
            $this->assertNotFalse($override, "The override for group {$groupids[$i]} should exist in the database.");
            $this->assertEquals(strtotime($row['timeopen']), $override->timeopen);
            $this->assertEquals(strtotime($row['timeclose']), $override->timeclose);
            $this->assertEquals($row['timelimit'], $override->timelimit);
            $this->assertEquals($row['attempts'], $override->attempts);

            if ($row['set_password'] == 1) {
                $this->assertNotEmpty($override->password);
            } else {
                $this->assertEquals($row['password'], $override->password);
            }
        }
    }

    /**
     * Test import functionality with invalid group overrides.
     */
    public function test_import_invalid_group_overrides(): void {
        $csvdata = [
            [
                // Both identifiers unresolvable → groupid error.
                'groupid' => '999999',
                'groupname' => 'nonexistentgroup',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => 'invalid date', // Invalid.
                'timelimit' => '-3600', // Invalid.
                'attempts' => 'one', // Invalid.
                'password' => '',
                'set_password' => '',
            ],
            [
                'groupid' => '',
                'groupname' => $this->groups[1]->name,
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-01 07:00 +10:00', // Invalid: close before open.
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'set_password' => 'afgsdfg', // Invalid.
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'group',
            $this->quiz,
            $this->course,
        );

        $processed = $process->process();
        $this->assertTrue($processed, 'The CSV file should still have been processed.');
        $this->assertFalse($process->canimport, 'Errors should have occurred when processing and validating each row.');

        $errorsbyrow = [
            0 => ['groupid', 'timeclose', 'timelimit', 'attempts'],
            1 => ['timeopen', 'set_password'],
        ];

        foreach ($process->overrides as $index => $override) {
            foreach ($errorsbyrow[$index] as $expectederror) {
                $this->assertArrayHasKey(
                    $expectederror,
                    $override->errors,
                    "The column '{$expectederror}' should contain an invalid value in row {$index}",
                );
            }
        }
    }

    /**
     * Test import functionality with valid user overrides.
     */
    public function test_import_valid_user_overrides(): void {
        global $DB;

        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'mypassword1',
                'set_password' => '1',
            ],
            [
                'userid' => $this->students[1]->id,
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-02 10:00 +10:00',
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'set_password' => '1',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );

        $processed = $process->process();
        $this->assertTrue($processed, 'Processing the uploaded CSV data should be successful.');
        $this->assertCount(2, $process->overrides, 'The number of processed overrides should be 2.');
        $this->assertTrue($process->canimport, 'No errors should have occurred when processing and validating each row.');

        $potentialerrors = ['userid', 'timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'set_password'];
        foreach ($process->overrides as $override) {
            foreach ($potentialerrors as $potentialerror) {
                $this->assertArrayNotHasKey(
                    $potentialerror,
                    $override->errors,
                    "The {$potentialerror} column contains an invalid value.",
                );
            }
        }

        $imported = $process->import();
        $this->assertTrue($imported, 'Importing the processed CSV data should be successful.');

        foreach ($csvdata as $row) {
            $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz->id, 'userid' => $row['userid']]);
            $this->assertNotFalse($override, 'The override for user ' . $row['userid'] . ' should exist in the database.');
            $this->assertEquals(strtotime($row['timeopen']), $override->timeopen);
            $this->assertEquals(strtotime($row['timeclose']), $override->timeclose);
            $this->assertEquals($row['timelimit'], $override->timelimit);
            $this->assertEquals($row['attempts'], $override->attempts);

            if ($row['set_password'] == 1) {
                $this->assertNotEmpty($override->password);
            } else {
                $this->assertEquals($row['password'], $override->password);
            }
        }
    }

    /**
     * Test import functionality with invalid user overrides.
     */
    public function test_import_invalid_user_overrides(): void {
        $csvdata = [
            [
                'userid' => '', // Empty → erroridempty.
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => 'invalid date', // Invalid.
                'timelimit' => '-3600', // Invalid.
                'attempts' => 'one', // Invalid.
                'password' => '',
                'set_password' => '',
            ],
            [
                'userid' => $this->students[1]->id,
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2023-01-01 07:00 +10:00', // Invalid: close before open.
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'set_password' => 'apples', // Invalid.
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );

        $process->process();
        $this->assertFalse($process->canimport, 'Errors should have occurred when processing and validating each row.');

        $errorsbyrow = [
            0 => ['userid', 'timeclose', 'timelimit', 'attempts'],
            1 => ['timeopen', 'set_password'],
        ];

        foreach ($process->overrides as $index => $override) {
            foreach ($errorsbyrow[$index] as $expectederror) {
                $this->assertArrayHasKey(
                    $expectederror,
                    $override->errors,
                    "The column '{$expectederror}' should contain an invalid value in row {$index}",
                );
            }
        }
    }

    /**
     * Test that a valid userid is used directly as the identifier.
     */
    public function test_user_id_resolution(): void {
        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'pass1',
                'set_password' => '1',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertTrue($process->canimport, 'Valid userid should resolve successfully.');
        $this->assertCount(1, $process->overrides);
        $this->assertEquals($this->students[0]->id, $process->overrides[0]->userid);
    }

    /**
     * Test that group entity resolution falls back from groupid to groupname.
     */
    public function test_group_entity_resolution_fallback(): void {
        $csvdata = [
            // Row 1: resolve by groupid only (empty groupname).
            [
                'groupid' => (string) $this->groups[0]->id,
                'groupname' => '',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'pass1',
                'set_password' => '1',
            ],
            // Row 2: resolve by groupname only (empty groupid).
            [
                'groupid' => '',
                'groupname' => $this->groups[1]->name,
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-02 10:00 +10:00',
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => 'pass2',
                'set_password' => '1',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'group',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertTrue($process->canimport, 'All rows should resolve successfully.');
        $this->assertCount(2, $process->overrides);
        $this->assertEquals($this->groups[0]->id, $process->overrides[0]->groupid);
        $this->assertEquals($this->groups[1]->id, $process->overrides[1]->groupid);
    }

    /**
     * Test that a user who exists but is not enrolled produces the correct error.
     */
    public function test_user_not_enrolled_error(): void {
        $unenrolleduser = $this->getDataGenerator()->create_user(['username' => 'outsider']);

        $csvdata = [
            [
                'userid' => $unenrolleduser->id,
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'pass1',
                'set_password' => '',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertFalse($process->canimport, 'Unenrolled user should produce an error.');
        $this->assertArrayHasKey('userid', $process->overrides[0]->errors);
    }

    /**
     * Test that attempts=0 (Unlimited) is preserved and does not produce an error.
     */
    public function test_attempts_zero_unlimited(): void {
        global $DB;

        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '0',
                'password' => 'mypassword',
                'set_password' => '1',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $processed = $process->process();

        $this->assertTrue($processed);
        $this->assertTrue($process->canimport, 'attempts=0 should be valid (Unlimited).');
        $this->assertArrayNotHasKey('attempts', $process->overrides[0]->errors);
        $this->assertEquals(0, $process->overrides[0]->attempts);

        $process->import();
        $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz->id, 'userid' => $this->students[0]->id]);
        $this->assertEquals(0, $override->attempts, 'attempts=0 must be stored as 0, not null.');
    }

    /**
     * Test update action when an override already exists for the user.
     */
    public function test_update_existing_user_override(): void {
        global $DB;

        $existingid = $DB->insert_record('quiz_overrides', [
            'quiz' => $this->quiz->id,
            'userid' => $this->students[0]->id,
            'timeopen' => strtotime('2024-01-01 08:00 +10:00'),
            'timeclose' => strtotime('2024-01-01 10:00 +10:00'),
            'timelimit' => 1800,
            'attempts' => 1,
            'password' => 'oldpassword',
        ]);

        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '2024-06-01 09:00 +10:00',
                'timeclose' => '2024-06-01 11:00 +10:00',
                'timelimit' => '7200',
                'attempts' => '5',
                'password' => 'newpassword',
                'set_password' => '1',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertTrue($process->canimport);
        $this->assertEquals('update', $process->overrides[0]->recordstatus);
        $this->assertEquals($existingid, $process->overrides[0]->id);

        $process->import();
        $override = $DB->get_record('quiz_overrides', ['id' => $existingid]);
        $this->assertEquals(7200, $override->timelimit);
        $this->assertEquals(5, $override->attempts);
        $this->assertEquals('newpassword', $override->password);
    }

    /**
     * Test delete action when all options are empty and an existing override exists.
     */
    public function test_all_blank_fields_with_existing_override_is_error(): void {
        global $DB;

        $existingid = $DB->insert_record('quiz_overrides', [
            'quiz' => $this->quiz->id,
            'userid' => $this->students[0]->id,
            'timeopen' => strtotime('2024-01-01 08:00 +10:00'),
            'timeclose' => strtotime('2024-01-01 10:00 +10:00'),
            'timelimit' => 3600,
            'attempts' => 1,
            'password' => 'oldpass',
        ]);

        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '',
                'timeclose' => '',
                'timelimit' => '',
                'attempts' => '',
                'password' => '',
                'set_password' => '',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertFalse($process->canimport, 'All-blank fields should trigger error 1001 even when an override exists.');
        $this->assertEquals('failed', $process->overrides[0]->recordstatus);
        $this->assertTrue(
            $DB->record_exists('quiz_overrides', ['id' => $existingid]),
            'The existing override should not be deleted.',
        );
    }

    /**
     * Test error when all options are empty and no existing override exists (error 1001).
     */
    public function test_no_options_no_existing_override_error(): void {
        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '',
                'timeclose' => '',
                'timelimit' => '',
                'attempts' => '',
                'password' => '',
                'set_password' => '',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertFalse($process->canimport, 'No options and no existing override should be an error.');
        $this->assertEquals('failed', $process->overrides[0]->recordstatus);
    }

    /**
     * Test that password with whitespace produces a validation error.
     */
    public function test_password_whitespace_error(): void {
        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => ' has spaces ',
                'set_password' => '',
            ],
        ];

        $process = new process_override_imports(
            $this->make_importer($this->build_csv($csvdata)),
            'user',
            $this->quiz,
            $this->course,
        );
        $process->process();

        $this->assertFalse($process->canimport);
        $this->assertArrayHasKey('password', $process->overrides[0]->errors);
    }
}
