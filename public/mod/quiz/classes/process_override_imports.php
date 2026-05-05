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

namespace mod_quiz;

use context;
use stdClass;

/**
 * Handles the import and processing of quiz overrides from a CSV file in Moodle.
 *
 * @package   mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_override_imports {
    /**
     * @var stdClass The course object the quiz belongs to.
     */
    protected $course;

    /**
     * @var stdClass The course module object, if applicable.
     */
    protected $cm;

    /**
     * @var stdClass The quiz object that is being overridden.
     */
    protected $quiz;

    /**
     * @var context The context in which the quiz operates.
     */
    protected $context;

    /**
     * @var string Mode of operation, typically 'user' or 'group', determining how overrides are processed.
     */
    protected $mode;

    /**
     * @var csv_import_reader An instance of csv_import_reader used for processing CSV import data.
     */
    protected $importer;

    /**
     * @var string Holds the error string when there is an error in validate_headers.
     */
    protected $headererror = '';

    /**
     * @var stdClass[] Array of overrides to be processed and possibly inserted or updated in the database.
     */
    public $overrides = [];

    /**
     * @var bool Indicates whether import can proceed given the validation status.
     */
    public $canimport = true;

    /**
     * Constructs a process_override_imports instance.
     *
     * @param \csv_import_reader $importer An instance of csv_import_reader used for reading the CSV data.
     * @param string $mode The mode of processing, which can be 'user' or 'group'.
     * @param stdClass $quiz An object representing the quiz for which overrides are being imported.
     * @param stdClass $course An object representing the course associated with the quiz.
     * @param \context|null $context The module context, used for enrollment and capability checks.
     */
    public function __construct(
        \csv_import_reader $importer,
        string $mode,
        stdClass $quiz,
        stdClass $course,
        ?\context $context = null,
    ) {
        $this->importer = $importer;
        $this->mode = $mode;
        $this->quiz = $quiz;
        $this->course = $course;
        $this->context = $context;
    }

    /**
     * Processes the imported CSV data for quiz overrides.
     *
     * @return bool Returns true on successful processing, false otherwise.
     */
    public function process(): bool {
        global $DB;

        $this->importer->init();
        $mode = $this->mode;
        $typeid = $mode . 'id';

        // Ensure that the file contains the correct header structure.
        if (!$this->validate_headers()) {
            return false;
        }

        // Keep track of current row for preview table.
        $currentrow = 0;

        // Track resolved IDs already seen in this import to detect duplicates (error 1002).
        $seenids = [];

        $existingoverrides = [];
        if ($mode == 'group') {
            $existingrecords = $DB->get_records_select(
                'quiz_overrides',
                'quiz = ? AND groupid IS NOT NULL',
                [$this->quiz->id],
                '',
                'groupid, id',
            );

            $existingoverrides = array_filter(array_column($existingrecords, 'groupid'));
        } else {
            $existingrecords = $DB->get_records_select(
                'quiz_overrides',
                'quiz = ? AND userid IS NOT NULL',
                [$this->quiz->id],
                '',
                'userid, id',
            );
            $existingoverrides = array_filter(array_column($existingrecords, 'userid'));
        }

        $expectedfieldcount = ($mode === 'group') ? 8 : 7;

        while ($row = $this->importer->next()) {
            $currentrow++;

            // Detect rows with wrong field count (e.g. unquoted comma in password).
            if (count($row) !== $expectedfieldcount) {
                $this->canimport = false;
                $override = new stdClass();
                $override->recordstatus = 'failed';
                $override->errors = [get_string('errorrowfieldcount', 'quiz', (object)[
                    'actual' => count($row),
                    'expected' => $expectedfieldcount,
                ])];
                $override->csvrow = $currentrow;
                $this->overrides[] = $override;
                continue;
            }

            $csvgroupname = null;
            if ($mode === 'group') {
                [$groupid, $groupname, $timeopen, $timeclose, $timelimit, $attempts, $password, $setpassword] = $row;
                $csvgroupname = $groupname;
                $resolutionresult = $this->resolve_group_id($groupid, $groupname);
                $resolvedid = $resolutionresult['id'];
                $resolutionerror = $resolutionresult['error'];
            } else {
                [$userid, $timeopen, $timeclose, $timelimit, $attempts, $password, $setpassword] = $row;
                $resolvedid = $this->resolve_entity_id($userid);
                $resolutionerror = null;
            }

            // Check if the current row has all empty overrides.
            $options = [$timeopen, $timeclose, $timelimit, $attempts, $password, $setpassword];
            $isoptionsempty = true;
            foreach ($options as $option) {
                if (!empty($option)) {
                    $isoptionsempty = false;
                    break;
                }
            }

            // Error 1002: duplicate ID within this import file.
            if (!empty($resolvedid) && is_numeric($resolvedid)) {
                if (in_array($resolvedid, $seenids)) {
                    $this->canimport = false;
                    $override = new stdClass();
                    $override->$typeid = $resolvedid;
                    $override->recordstatus = 'failed';
                    $override->errors = [get_string('errorduplication', 'quiz')];
                    $override->csvrow = $currentrow;
                    $override->set_password = $setpassword;
                    $override->csv_groupname = $csvgroupname;
                    $this->overrides[] = $override;
                    continue;
                }
                $seenids[] = $resolvedid;
            }

            // Run validation first so identity errors (104, 105, 106) take precedence over error 1001.
            $errors = $this->validate_row_data(
                $resolvedid,
                $timeopen,
                $timeclose,
                $timelimit,
                $attempts,
                $password,
                $setpassword,
                $resolutionerror,
            );

            if ($this->canimport && !empty($errors)) {
                $this->canimport = false;
            }

            // Error 1001: all override fields empty AND no identity/validation errors.
            if ($isoptionsempty && !empty($resolvedid) && empty($errors)) {
                $this->canimport = false;
                $override = new stdClass();
                $override->$typeid = $resolvedid;
                $override->recordstatus = 'failed';
                $override->errors = [get_string('errornooptions', 'quiz')];
                $override->csvrow = $currentrow;
                $override->set_password = $setpassword;
                $override->csv_groupname = $csvgroupname;
                $this->overrides[] = $override;
                continue;
            }

            // Create override object to add to database.
            $override = new stdClass();
            $override->id = $this->get_existing_override_id($this->quiz->id, $typeid, $resolvedid) ?: null;
            $override->quiz = $this->quiz->id;
            $override->$typeid = $resolvedid;
            $override->timeopen = strtotime($timeopen) && empty($errors['timeopen']) ? strtotime($timeopen) : $timeopen;
            $override->timeclose = strtotime($timeclose) && empty($errors['timeclose']) ? strtotime($timeclose) : $timeclose;
            $override->timelimit = $timelimit;
            $override->attempts = is_numeric($attempts) ? intval($attempts) : $attempts;
            if ($setpassword === '1') {
                // Set_password=1: use provided password or generate one if blank.
                // Uses random_string() instead of generate_password() because the latter can
                // produce commas (PASSWORD_NONALPHANUM), which would corrupt CSV exports/re-uploads.
                $override->password = !empty($password) ? $password : random_string(20);
            } else if ($setpassword === '0') {
                // Set_password=0: always clear the password (error 702 blocks import if $password was non-empty).
                $override->password = null;
            } else {
                // Set_password empty: store the password column value as-is.
                $override->password = !empty($password) ? $password : null;
            }

            // Add additional fields for preview table.
            // Check if all provided values are equivalent to quiz defaults (override would be redundant).
            $issamedefault = empty($errors) && $this->is_same_as_defaults($override, $setpassword);

            $action = '';
            if ($issamedefault && isset($override->id)) {
                $action = 'delete';
            } else if ($issamedefault) {
                // No existing override and values match quiz defaults → error 1001.
                $this->canimport = false;
                $override->recordstatus = 'failed';
                $override->errors = [get_string('errornooptions', 'quiz')];
                $override->csvrow = $currentrow;
                $override->set_password = $setpassword;
                $override->csv_groupname = $csvgroupname;
                $this->overrides[] = $override;
                continue;
            } else if (isset($override->id)) {
                $action = 'update';
            } else {
                $action = 'insert';
            }
            $override->recordstatus = $action;
            $override->errors = $errors;
            $override->csvrow = $currentrow;
            $override->set_password = $setpassword;
            $override->csv_groupname = $csvgroupname;

            // Set any empty values explicitly to null for inserting into database.
            // Use strict check: only convert empty strings and actual null, not 0 or '0'
            // (e.g. attempts=0 means 'Unlimited' and must be preserved).
            foreach ($override as $key => &$value) {
                if (!is_array($value) && ($value === '' || $value === null)) {
                    $value = null;
                }
            }
            unset($value);

            if ($override->recordstatus != 'skip') {
                $this->overrides[] = (object) $override;
            }
        }

        if ($currentrow === 0) {
            $this->headererror = get_string('erroremptyfile', 'quiz', $this->mode);
            return false;
        }

        return true;
    }

    /**
     * Retrieves header validation error encountered during the process function.
     *
     * @return string|null The header error message if it exists, or null if no error.
     */
    public function get_header_error(): ?string {
        if (!empty($this->headererror)) {
            return $this->headererror;
        }
        return null;
    }

    /**
     * Imports the processed overrides into the database. Handles insertion,
     * updating, and deletion of quiz overrides based on the overrides
     * collected during processing.
     *
     * @return bool Returns true if the import was successful, false otherwise.
     */
    public function import(): bool {
        global $DB;
        $transaction = $DB->start_delegated_transaction();

        try {
            foreach ($this->overrides as $override) {
                $action = $override->recordstatus;

                // Unset properties used for preview before inserting.
                unset($override->csvrow);
                unset($override->errors);
                unset($override->set_password);
                unset($override->recordstatus);
                unset($override->csv_groupname);

                if ($action == 'update') {
                    $DB->update_record('quiz_overrides', $override);
                    continue;
                }

                if ($action == 'insert') {
                    $DB->insert_record('quiz_overrides', $override);
                    continue;
                }

                if ($action == 'delete') {
                    $DB->delete_records('quiz_overrides', ['id' => $override->id]);
                }
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            $this->errors[] = get_string('errordbinsert', 'quiz', $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Retrieves the existing override ID if it exists.
     *
     * @param int $quizid The ID of the quiz.
     * @param string $typeid The type of override identifier (e.g., 'userid', 'groupid').
     * @param string $modeid The ID of the mode (user or group) to check.
     * @return int|false The existing override ID or null if none exists.
     */
    private function get_existing_override_id(int $quizid, string $typeid, string $modeid): int|false {
        global $DB;

        if (empty($modeid) || !is_numeric($modeid)) {
            return false;
        }

        $conditions = ['quiz' => $quizid, $typeid => $modeid];
        $existingoverride = $DB->get_record('quiz_overrides', $conditions, 'id');

        return $existingoverride ? $existingoverride->id : false;
    }

    /**
     * Validates the headers of the imported CSV file.
     *
     * @return bool Returns true if the headers are correctly formatted.
     */
    private function validate_headers(): bool {
        global $OUTPUT;

        $currentheaders = $this->importer->get_columns();
        $overrideheaders = ['timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'set_password'];
        $requiredheaders = [];
        $isvalid = true;
        if ($this->mode == 'group') {
            $requiredheaders = array_merge(['groupid', 'groupname'], $overrideheaders);
            $isvalid = $currentheaders == $requiredheaders;
        } else {
            $requiredheaders = array_merge(['userid'], $overrideheaders);
            $isvalid = $currentheaders == $requiredheaders;
        }

        if (!$isvalid) {
            $this->headererror = get_string('errorstructure', 'quiz', $this->mode);
            return false;
        }

        return true;
    }

    /**
     * Validates the data for a single row imported from the CSV.
     *
     * @param string $id The user or group ID depending on mode.
     * @param string $timeopen Opening time of the quiz.
     * @param string $timeclose Closing time of the quiz.
     * @param string $timelimit The time limit for the quiz.
     * @param string $attempts The number of attempts allowed.
     * @param string $password The password for accessing the quiz.
     * @param string $setpassword Specifies whether to generate the password automatically (1 or 0).
     * @param string|null $resolutionerror Error key from entity resolution (errors 105/106), or null.
     * @return array An array of error messages if validation fails, or an empty array if validation passes.
     */
    private function validate_row_data(
        string $id,
        string $timeopen,
        string $timeclose,
        string $timelimit,
        string $attempts,
        string $password,
        string $setpassword,
        ?string $resolutionerror = null,
    ): array {
        global $DB;

        $errors = [];

        // Validate the entity ID (user or group).
        if (empty($id)) {
            if ($resolutionerror !== null) {
                // Error 105: groupname was provided but not found in course.
                $errors[$this->mode . 'id'] = get_string($resolutionerror, 'quiz');
            } else {
                $errorkey = $this->mode === 'group' ? 'errorgroupidempty' : 'erroridempty';
                $errors[$this->mode . 'id'] = get_string($errorkey, 'quiz');
            }
        } else {
            // Error 106: resolution error when id was found (groupid/name mismatch).
            if ($resolutionerror !== null) {
                $errors[$this->mode . 'id'] = get_string($resolutionerror, 'quiz');
            }

            if ($this->mode == 'user') {
                // Non-numeric value can never be a valid Moodle user ID.
                if (!is_numeric($id) || !$DB->record_exists('user', ['id' => $id, 'deleted' => 0])) {
                    $errors['userid'] = get_string('errorusernotexist', 'quiz', $id);
                } else {
                    // Ensure user is enrolled in the course and can attempt the quiz (error 103).
                    $coursecontext = \context_course::instance($this->course->id);
                    if (!is_enrolled($coursecontext, $id)) {
                        $errors['userid'] = get_string('errorusernotenrolled', 'quiz', $id);
                    } else if ($this->context && !has_capability('mod/quiz:attempt', $this->context, (int)$id)) {
                        $errors['userid'] = get_string('errorusernotenrolled', 'quiz', $id);
                    }
                }
            }

            // Error 104: group ID does not exist. Only check if no resolution error already set.
            if ($this->mode == 'group' && !isset($errors['groupid'])) {
                if (!is_numeric($id) || !$DB->record_exists('groups', ['id' => $id, 'courseid' => $this->course->id])) {
                    $errors['groupid'] = get_string('errorgroupnotexist', 'quiz', $id);
                }
            }
        }

        // Ensure that dateformat for timeopen is valid.
        $invaliddateformat = false;
        if (!empty($timeopen) && !$this->validate_date($timeopen)) {
            $errors['timeopen'] = get_string('errorinvaliddatetime', 'quiz', 'timeopen');
            $invaliddateformat = true;
        }

        // Ensure that dateformat for timeclose is valid.
        if (!empty($timeclose) && !$this->validate_date($timeclose)) {
            $errors['timeclose'] = get_string('errorinvaliddatetime', 'quiz', 'timeclose');
            $invaliddateformat = true;
        }

        // Ensure that timeopen < timeclose.
        if (!$invaliddateformat && !empty($timeopen) && !empty($timeclose) && strtotime($timeopen) > strtotime($timeclose)) {
            $errors['timeopen'] = get_string('erroropenclose', 'quiz');
        }

        // Ensure that the timelimit is an integer value more than zero.
        if (!empty($timelimit) && (!is_numeric($timelimit) || intval($timelimit) < 0)) {
            $errors['timelimit'] = get_string('errortimelimit', 'quiz', $timelimit);
        }

        // Ensure that attempts is an integer in the valid range (0 = Unlimited, 1-10 for explicit limits).
        if (!empty($attempts) && (!is_numeric($attempts) || intval($attempts) < 0 || intval($attempts) > 10)) {
            $errors['attempts'] = get_string('errorattempts', 'quiz', $attempts);
        }

        // Ensure that password does not contain whitespace.
        // Adapted from validateSubmitValue in MoodleQuickForm_passwordunmask class.
        if (!empty($password) && $password !== trim($password)) {
            $errors['password'] = get_string('errorpassword', 'quiz');
        }

        // Ensure that set_password is a valid boolean value or empty.
        if (!empty($setpassword) && !in_array($setpassword, ["0", "1"], true)) {
            $errors['set_password'] = get_string('errorsetpassword', 'quiz', $setpassword);
        }

        // Error 702: password value provided when set_password is 0 or empty (contradictory).
        if (!empty($password) && ($setpassword === '0' || $setpassword === '')) {
            $errors['set_password'] = get_string('errorsetpasswordzero', 'quiz');
        }

        return $errors;
    }

    /**
     * Validates that a given date string matches the expected format 'Y-m-d H:i P'.
     *
     * @param string $date The date string to validate.
     * @return bool True if the date string matches the format, otherwise false.
     */
    private function validate_date(string $date): bool {
        $format = 'Y-m-d H:i P';
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Returns true if all override values are equivalent to the quiz defaults,
     * meaning the override would have no net effect on the user/group.
     *
     * Empty/null override fields are treated as "no override for this field" and
     * never count as a difference.
     *
     * @param stdClass $override The processed override object (before null-ification).
     * @param string $setpassword The raw set_password CSV value.
     * @return bool
     */
    private function is_same_as_defaults(stdClass $override, string $setpassword): bool {
        foreach (['timeopen', 'timeclose', 'timelimit', 'attempts'] as $field) {
            // Normalise '' to null: both mean "no override for this field".
            $overrideval = ($override->$field === '' || $override->$field === null) ? null : $override->$field;
            $quizval = $this->quiz->$field ?? null;
            // A non-null override that differs from the quiz default is a meaningful change.
            if ($overrideval !== null && $overrideval != $quizval) {
                return false;
            }
        }
        if ($setpassword === '1') {
            if ($override->password != $this->quiz->password) {
                return false;
            }
        } else if ($setpassword === '0') {
            // Clearing a password that exists is a meaningful change.
            if (!empty($this->quiz->password)) {
                return false;
            }
        }
        // Set_password empty → no password intent → not a meaningful change.
        return true;
    }

    /**
     * Resolves the database ID for a user from the CSV identifier column.
     *
     * Accepts a single userid (Moodle internal ID) and returns it as-is;
     * validate_row_data handles existence and enrolment checks.
     *
     * @param string $primary userid.
     * @return string The userid as provided.
     */
    private function resolve_entity_id(string $primary): string {
        return $primary;
    }

    /**
     * Resolves the database group ID from groupid and/or groupname columns.
     *
     * Returns an array with:
     *   'id'    — the resolved group DB ID, or empty string / original $groupid on failure.
     *   'error' — a lang string key for errors 105 (name not found) or 106 (id/name mismatch),
     *             or null when no resolution-level error occurred.
     *
     * @param string $groupid The idnumber value from the CSV.
     * @param string $groupname The group name value from the CSV.
     * @return array{id: string, error: string|null}
     */
    private function resolve_group_id(string $groupid, string $groupname): array {
        global $DB;

        $idbyidnumber = null;
        $idbyname = null;

        if (!empty($groupid)) {
            if (is_numeric($groupid)) {
                $group = $DB->get_record('groups', ['id' => (int)$groupid, 'courseid' => $this->course->id], 'id');
                if ($group) {
                    $idbyidnumber = (string) $group->id;
                }
            }
            // Groupid was provided but either non-numeric or not found in course.
            // Do not fall back to groupname — return as-is so validate_row_data reports error 104.
            if ($idbyidnumber === null) {
                return ['id' => $groupid, 'error' => null];
            }
        }

        if (!empty($groupname)) {
            $group = $DB->get_record('groups', ['name' => $groupname, 'courseid' => $this->course->id], 'id');
            if ($group) {
                $idbyname = (string) $group->id;
            }
        }

        // Error 106: groupid found, groupname provided but not found in DB (can't confirm they match).
        if ($idbyidnumber !== null && !empty($groupname) && $idbyname === null) {
            return ['id' => $idbyidnumber, 'error' => 'errorgroupidnamemismatch'];
        }

        // Error 106: both columns provided, both resolved, but to different groups.
        if ($idbyidnumber !== null && $idbyname !== null && $idbyidnumber !== $idbyname) {
            return ['id' => $idbyidnumber, 'error' => 'errorgroupidnamemismatch'];
        }

        // Resolved by idnumber (or idnumber + name agree on the same group).
        if ($idbyidnumber !== null) {
            return ['id' => $idbyidnumber, 'error' => null];
        }

        // Resolved by name only.
        if ($idbyname !== null) {
            return ['id' => $idbyname, 'error' => null];
        }

        // Neither resolved.
        // Error 105: only groupname was provided (no idnumber) but groupname not found.
        if (empty($groupid) && !empty($groupname)) {
            return ['id' => '', 'error' => 'errorgroupnamenotfound'];
        }

        // Error 104 scenario: groupid was provided but not found — return it as-is
        // So validate_row_data sees a non-numeric value and reports errorgroupnotexist.
        return ['id' => $groupid, 'error' => null];
    }
}
