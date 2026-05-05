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
 * Table for displaying quiz overrides.
 *
 * @package    mod_quiz
 * @copyright     2024 Djarran Cotleanu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quiz;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/tablelib.php');
use DateTimeZone;
use cm_info;
use core_date;
use table_sql;
use moodle_url;
use html_writer;
use stdClass;

/**
 * Table for displaying quiz overrides class.
 *
 * @package    mod_quiz
 * @copyright  2024 Djarran Cotleanu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides_table extends table_sql {
    /**
     * @var cm_info $cm Stores course module information.
     */
    protected $cm;

    /**
     * @var stdClass $context Stores context information.
     */
    protected $context;

    /**
     * @var bool $hasinactive Indicates if there are inactive users/groups in the table.
     */
    public $hasinactive;

    /**
     * @var bool $ispreview Indicates if we are rendering the import preview table.
     */
    protected $ispreview;

    /**
     * @var bool $canedit Indicates if the user viewing the table can edit overrides.
     */
    protected $canedit;

    /**
     * @var string $mode The mode - either 'group' or 'user'.
     */
    protected $mode;

    /**
     * @var bool $isempty Indicates if the table is empty.
     */
    public $isempty = false;

    /**
     * Constructor
     *
     * @param string $uniqueid A unique identifier for the table.
     * @param string $mode The mode, either 'group' or 'user'.
     * @param cm_info $cm The course module information.
     * @param stdClass $context The context information.
     * @param bool $ispreview Optional parameter to indicate if this is a preview mode.
     * @param bool $canedit Optional parameter to indicate if user can edit overrides.
     */
    public function __construct(
        string $uniqueid,
        string $mode,
        cm_info $cm,
        stdClass $context,
        bool $ispreview = false,
        bool $canedit = true,
    ) {
        parent::__construct($uniqueid);

        $this->cm = $cm;
        $this->context = $context;
        $this->mode = $mode;
        $this->ispreview = $ispreview;
        $this->canedit = $canedit;

        // Set table config.
        $this->set_columns_and_headers();
        $this->collapsible(false);
        $this->sortable(false);
        $this->pageable(true);
        $this->pagesize = 20;
        $this->is_downloadable(false);
    }

    /**
     * Sets the columns and headers for the table based on the mode and whether it is in preview.
     *
     * @return void
     */
    private function set_columns_and_headers(): void {
        $overridecolumns = ['timeopen', 'timeclose', 'timelimit', 'attempts', 'password'];
        $overrideheaders = ['Quiz opens', 'Quiz closes', 'Time limit', 'Attempts', 'Password'];

        if ($this->ispreview) {
            if ($this->mode == 'group') {
                $idcolumns = ['groupid', 'groupname'];
                $idheaders = ['Groupid', 'Group name'];
            } else {
                $idcolumns = ['userid', 'username'];
                $idheaders = ['Userid', 'User name'];
            }
            $this->define_columns(array_merge(
                ['csvrow', 'recordstatus'],
                $idcolumns,
                $overridecolumns,
                ['set_password', 'errors'],
            ));
            $this->define_headers(array_merge(
                ['Line', 'Result'],
                $idheaders,
                $overrideheaders,
                ['Set password', 'Status'],
            ));
        } else {
            $combinedcolumns = [];
            $combinedheaders = [];
            if ($this->mode == 'group') {
                $combinedcolumns = array_merge(['name'], $overridecolumns);
                $combinedheaders = array_merge(['Group'], $overrideheaders);
            } else {
                // Get additional user fields.
                $userfieldsapi = \core_user\fields::for_identity($this->context)->with_name()->with_userpic();
                $useridentityfields = $userfieldsapi->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]);
                $additionalcolumns = $useridentityfields;
                $additionalheaders = array_map([\core_user\fields::class, 'get_display_name'], $useridentityfields);

                $combinedcolumns = array_merge(['name'], $additionalcolumns, $overridecolumns);
                $combinedheaders = array_merge(['User'], $additionalheaders, $overrideheaders);
            }

            // Add action header and column.
            if ($this->canedit) {
                $combinedcolumns = array_merge($combinedcolumns, ['actions']);
                $combinedheaders = array_merge($combinedheaders, ['Actions']);
            }

            $this->define_columns($combinedcolumns);
            $this->define_headers($combinedheaders);
        }
    }

    /**
     * Set SQL for group overrides.
     *
     * @param int $quizid Quiz ID.
     * @param array $groups Array of group objects.
     * @return void
     */
    public function set_sql_group(int $quizid, array $groups): void {
        global $DB;

        $params = ['quizid' => $quizid];

        if (!empty($groups)) {
            // To filter the result by the list of groups that the current user has access to.
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
            $params += $inparams;

            $this->set_sql(
                'o.*, g.name as groupname',
                "{quiz_overrides} o
                JOIN {groups} g ON o.groupid = g.id",
                "o.quiz = :quizid AND g.id $insql",
                $params,
            );
        } else {
            $this->set_sql(
                'o.*, g.name as groupname',
                "{quiz_overrides} o
                JOIN {groups} g ON o.groupid = g.id",
                "1 = 0",
                $params,
            );
        }
    }

    /**
     * Set SQL for user overrides.
     *
     * @param int $quizid Quiz ID.
     * @param bool $showallgroups User can see all groups.
     * @param array $groups Array of group objects.
     * @return void
     */
    public function set_sql_user(int $quizid, bool $showallgroups, array $groups): void {
        global $DB;
        // Get additional user identity fields (showuseridentity user policy).
        $userfieldsapi = \core_user\fields::for_identity($this->context)->with_name()->with_userpic();
        $useridentityfields = $userfieldsapi->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]);

        // Get necessary fields for query.
        $userfieldssql = $userfieldsapi->get_sql('u', true, '', 'userid', false);

        // Get ORDER BY.
        [$sort, $params] = users_order_by_sql('u', null, $this->context, $useridentityfields);

        $params['quizid'] = $quizid;

        if ($showallgroups) {
            $groupsjoin = '';
            $groupswhere = '';
        } else if ($groups) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
            $groupsjoin = 'JOIN {groups_members} gm ON u.id = gm.userid';
            $groupswhere = ' AND gm.groupid ' . $insql;
            $params += $inparams;
        } else {
            // User cannot see any data.
            $groupsjoin = '';
            $groupswhere = ' AND 1 = 2';
        }

        // Required fields from quiz_overrides table - userid retrieved using $userfieldssql.
        $overridefieldssql = 'o.timeopen, o.timeclose, o.timelimit, o.attempts, o.password, o.id';

        $this->set_sql(
            "{$userfieldssql->selects}, {$overridefieldssql}",
            "{quiz_overrides} o
            JOIN {user} u ON o.userid = u.id
            {$userfieldssql->joins}
            $groupsjoin
            ",
            "o.quiz = :quizid
            $groupswhere",
            array_merge($params, $userfieldssql->params),
        );
    }

    /**
     * Outputs the preview table. Separate output function due to how
     * rows are inserted in the preview using format_and_add_array_of_rows
     * as table_sql out() method attempts to execute sql query.
     *
     * @return void
     */
    public function preview_output(): void {

        // Build the table.
        $this->build_table();

        // Finish the output.
        $this->finish_output();
    }

    /**
     * Returns the dimmed CSS class if the override is not active.
     *
     * @param array $row The row data.
     * @return string The CSS class for the row or empty string.
     */
    public function get_row_class($row): string {
        if (!$this->is_active($row)) {
            return 'dimmed_text';
        }
        return '';
    }

    /**
     * Checks whether a given override is active.
     *
     * @param stdClass $row Data containing values for each column in the row.
     * @return bool True if the override is active, false otherwise.
     */
    private function is_active(stdClass $row): bool {
        // This class does not apply to group overrides.
        if ($this->mode == 'group') {
            return true;
        }

        $active = true;
        if (!has_capability('mod/quiz:attempt', $this->context, $row->userid)) {
            // User not allowed to take the quiz.
            $active = false;

            // Display message.
            $this->hasinactive = true;
        } else if (!\core_availability\info_module::is_user_visible($this->cm, $row->userid)) {
            // User cannot access the module.
            $active = false;

            // Display message.
            $this->hasinactive = true;
        }

        return $active;
    }

    /**
     * Generates the content for the name column.
     *
     * @param stdClass $row Data containing values for each column in the row.
     * @return string The content for the name column.
     */
    public function col_name(stdClass $row): string {
        global $DB;

        if ($this->mode == 'group') {
            $groupurl = new moodle_url('/group/overview.php', ['id' => $this->cm->course]);
            $extranamebit = '';

            $groupname = '';
            if ($this->ispreview) {
                $group = $DB->get_record('groups', ['id' => $row->groupid]);
                $groupname = $group->name ?? '';
            } else {
                $groupname = $row->groupname;
            }

            if (empty($groupname)) {
                return 'N/A';
            }

            return html_writer::link(
                new moodle_url($groupurl, ['group' => $row->groupid]),
                format_string($groupname, true, ['context' => $this->context]) . $extranamebit,
            );
        }

        $active = $this->is_active($row);
        $userurl = new moodle_url('/user/view.php', []);

        // If user is not active, add asterix to alert viewer.
        $extranamebit = $active ? '' : '*';

        $fullname = '';
        if ($this->ispreview) {
            $user = $DB->get_record('user', ['id' => $row->userid]);
            $fullname = fullname($user) ?? '';
        } else {
            $fullname = fullname($row);
        }

        if (empty($fullname)) {
            return 'N/A';
        }

        return html_writer::link(
            new moodle_url($userurl, ['id' => $row->userid]),
            $fullname . $extranamebit,
        );
    }

    /**
     * Generates the userid column for the preview table.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the userid column.
     */
    public function col_userid(stdClass $row): string {
        $userid = (string)($row->userid ?? '');
        if (!empty($row->errors['userid'])) {
            return $this->highlighted($userid !== '' ? $userid : '—');
        }
        return $userid !== '' ? $userid : $this->set_dim('—');
    }

    /**
     * Generates the username column for the preview table.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the username column.
     */
    public function col_username(stdClass $row): string {
        global $DB;

        $userid = $row->userid ?? '';
        if (empty($userid) || !is_numeric($userid)) {
            return $this->set_dim('—');
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        if (empty($user)) {
            return $this->set_dim('—');
        }

        $active = $this->is_active($row);
        $extranamebit = $active ? '' : '*';
        return html_writer::link(
            new moodle_url('/user/view.php', ['id' => $userid]),
            fullname($user) . $extranamebit,
        );
    }

    /**
     * Generates the groupid (idnumber) column for the preview table.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the groupid column.
     */
    public function col_groupid(stdClass $row): string {
        $groupid = (string)($row->groupid ?? '');
        if (!empty($row->errors['groupid'])) {
            return $this->highlighted($groupid !== '' ? $groupid : '—');
        }
        return $groupid !== '' ? $groupid : $this->set_dim('—');
    }

    /**
     * Generates the groupname column for the preview table.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the groupname column.
     */
    public function col_groupname(stdClass $row): string {
        global $DB;

        $csvgroupname = $row->csv_groupname ?? null;
        $haserror = !empty($row->errors);

        // When there are errors, show the raw CSV value so users see what they typed.
        if ($haserror) {
            $display = ($csvgroupname !== null && $csvgroupname !== '')
                ? format_string($csvgroupname, true, ['context' => $this->context])
                : '—';
            // Highlight only when the groupname itself caused the error.
            $isgroupnameerror = isset($row->errors['groupname']) || isset($row->errors['groupid']);
            return $isgroupnameerror ? $this->highlighted($display) : $display;
        }

        $groupid = $row->groupid ?? '';
        if (empty($groupid) || !is_numeric($groupid)) {
            return $this->set_dim('—');
        }
        $group = $DB->get_record('groups', ['id' => $groupid], 'id, name');
        if (empty($group) || empty($group->name)) {
            return $this->set_dim('—');
        }
        return html_writer::link(
            new moodle_url('/group/overview.php', ['id' => $this->cm->course, 'group' => $groupid]),
            format_string($group->name, true, ['context' => $this->context]),
        );
    }

    /**
     * Generates the HTML content for the "actions" column.
     *
     * @param stdClass $row Data containing values for each column in the row.
     * @return string The HTML content for the "actions" column.
     */
    public function col_actions(stdClass $row): string {
        global $OUTPUT;

        $overridedeleteurl = new moodle_url('/mod/quiz/overridedelete.php');
        $overrideediturl = new moodle_url('/mod/quiz/overrideedit.php');

        // Code copied from previous override table implementation.
        $actiondiv = html_writer::start_div('actions');

        // Edit button.
        $editurlstr = $overrideediturl->out(true, ['id' => $row->id]);
        $editbutton = html_writer::link(
            $editurlstr,
            $OUTPUT->pix_icon('t/edit', get_string('edit')),
            ['title' => get_string('edit')],
        );
        $actiondiv .= $editbutton;

        // Duplicate button.
        $copyurlstr = $overrideediturl->out(
            false,
            ['id' => $row->id, 'action' => 'duplicate'],
        );
        $copybutton = html_writer::link(
            $copyurlstr,
            $OUTPUT->pix_icon('t/copy', get_string('copy')),
            ['title' => get_string('copy')],
        );
        $actiondiv .= $copybutton;

        // Delete button.
        $deleteurlstr = $overridedeleteurl->out(
            true,
            ['id' => $row->id, 'sesskey' => sesskey()],
        );
        $deletebutton = html_writer::link(
            $deleteurlstr,
            $OUTPUT->pix_icon('t/delete', get_string('delete')),
            ['title' => get_string('delete')],
        );
        $actiondiv .= $deletebutton;

        $actiondiv .= html_writer::end_div();

        return $actiondiv;
    }

    /**
     * Wrap text in HTML to display it dimmed.
     *
     * @param string $text The text to be dimmed.
     * @return string The input text wrapped in HTML span.
     */
    public function set_dim(string $text): string {
        return "<span class='text-muted'>$text</span>";
    }

    /**
     * Format the timeopen column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the "timeopen" column.
     */
    public function col_timeopen(stdClass $row): string {
        global $USER;
        $timeopen = $row->timeopen ?? null;

        if ($this->ispreview && !empty($row->errors['timeopen'])) {
            return $this->highlighted($timeopen);
        }

        if (empty($timeopen)) {
            return $this->set_dim(get_string('noopen', 'quiz'));
        }

        if (intval($timeopen) && intval($timeopen > 0)) {
            return userdate($timeopen);
        }

        return $timeopen;
    }

    /**
     * Format the timeclose column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the "timeclose" column.
     */
    public function col_timeclose(stdClass $row): string {
        global $USER;

        $timeclose = $row->timeclose ?? null;

        if ($this->ispreview && !empty($row->errors['timeclose'])) {
            return $this->highlighted($timeclose);
        }

        if (empty($timeclose)) {
            return $this->set_dim(get_string('noclose', 'quiz'));
        }

        if (intval($timeclose) && intval($timeclose > 0)) {
            return userdate($timeclose);
        }

        return $timeclose;
    }
    /**
     * Generate content for the password column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the "password" column.
     */
    public function col_password(stdClass $row): string {
        $password = $row->password ?? null;

        if ($this->ispreview && !empty($row->errors['password'])) {
            // Preserve whitespace in HTML element.
            $password = str_replace(' ', '&nbsp;', s($password));
            return $this->highlighted($password);
        }

        if ($this->ispreview && !empty($password)) {
            return $password;
        }

        return !empty($password) ? get_string('enabled', 'quiz') : $this->set_dim(get_string('none', 'quiz'));
    }

    /**
     * Generate content for the timelimit column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the "timelimit" column.
     */
    public function col_timelimit(stdClass $row): string {
        $timelimit = $row->timelimit ?? null;

        if ($this->ispreview) {
            if (!empty($row->errors['timelimit'])) {
                return $this->highlighted($timelimit);
            }
        }

        return $timelimit > 0 ?
                format_time($timelimit) : $this->set_dim(get_string('none', 'quiz'));
    }

    /**
     * Generates content for the attempts column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the attempts column.
     */
    public function col_attempts(stdClass $row): string {
        $attempts = $row->attempts ?? null;

        if ($this->ispreview && !empty($row->errors['attempts'])) {
            return $this->highlighted($attempts);
        }

        if ($attempts == null || !isset($attempts)) {
            return $this->set_dim('Unlimited');
        }

        return $attempts;
    }

    /**
     * Generates content for the recordstatus column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the recordstatus column.
     */
    public function col_recordstatus(stdClass $row): string {
        global $OUTPUT;

        if (!empty($row->errors)) {
            return $OUTPUT->pix_icon('i/invalid', get_string('overrideaction_failed', 'quiz'));
        }
        $map = [
            'insert' => $OUTPUT->pix_icon('t/add', get_string('overrideaction_insert', 'quiz')),
            'update' => $OUTPUT->pix_icon('t/edit', get_string('overrideaction_update', 'quiz')),
            'delete' => $OUTPUT->pix_icon('t/delete', get_string('overrideaction_delete', 'quiz')),
        ];
        return $map[$row->recordstatus] ?? '';
    }

    /**
     * Generates content for the errors column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the errors column.
     */
    public function col_errors(stdClass $row): string {
        return implode(', ', $row->errors);
    }

    /**
     * Generates content for the csvrow column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the csvrow column.
     */
    public function col_csvrow(stdClass $row): string {
        return (string) ($row->csvrow + 1);
    }

    /**
     * Generates content for the set_password column.
     *
     * @param stdClass $row The row data.
     * @return string The HTML content for the set_password column.
     */
    public function col_set_password(stdClass $row): string {
        $setpassword = $row->set_password ?? null;
        $displayval = !empty($setpassword) ? 'True' : 'False';

        if ($this->ispreview && !empty($row->errors['set_password'])) {
            return $this->highlighted($displayval);
        }

        return !empty($setpassword) ? $displayval : $this->set_dim($displayval);
    }

    /**
     * Highlights the given content to show user where the error is.
     *
     * @param string $content The content to be highlighted.
     * @return string The HTML for the highlighted content.
     */
    private function highlighted(string $content): string {
        return html_writer::tag('span', $content, ['class' => 'table-warning']);
    }

    /**
     * Override print_nothing_to_display method to show a custom message when there are no rows to display.
     *
     * @return void
     */
    public function print_nothing_to_display(): void {
        global $OUTPUT;

        // Render the dynamic table header.
        echo $this->get_dynamic_table_html_start();

        // Render button to allow user to reset table preferences.
        echo $this->render_reset_button();

        $this->print_initials_bar();

        if ($this->mode == 'group') {
            echo $OUTPUT->notification(get_string('overridesnoneforgroups', 'quiz'), 'info', false);
        } else {
            echo $OUTPUT->notification(get_string('overridesnoneforusers', 'quiz'), 'info', false);
        }

        $this->isempty = true;

        // Render the dynamic table footer.
        echo $this->get_dynamic_table_html_end();
    }
}
