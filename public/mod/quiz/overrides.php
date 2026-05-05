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
 * This page handles listing of quiz overrides
 *
 * @package    mod_quiz
 * @copyright  2010 Matt Petro
 * @author     2024 Djarran Cotleanu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\overrides_table;
use mod_quiz\quiz_settings;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA); // One of 'user' or 'group', default is 'group'.
$download = optional_param('download', '', PARAM_ALPHA);

$quizobj = quiz_settings::create_for_cmid($cmid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();
$manager = $quizobj->get_override_manager();

require_login($course, false, $cm);

// Check the user has the required capabilities to edit overrides.
$canedit = has_capability('mod/quiz:manageoverrides', $context);

// If not, check if they can view overrides.
if (!$canedit) {
    require_capability('mod/quiz:viewoverrides', $context);
}

$quizgroupmode = groups_get_activity_groupmode($cm);

// Check if the user can see all groups.
$showallgroups = ($quizgroupmode == NOGROUPS) || has_capability('moodle/site:accessallgroups', $context);

// Get the course groups that the current user can access.
$groups = $showallgroups ? groups_get_all_groups($cm->course) : groups_get_activity_allowed_groups($cm);

// Default mode is "group", unless there are no groups.
if ($mode != "user" && $mode != "group") {
    if (!empty($groups)) {
        $mode = "group";
    } else {
        $mode = "user";
    }
}
$groupmode = ($mode == "group");

// Delete orphaned group overrides.
$manager->delete_orphaned_group_overrides_in_course($course->id);

// Work out what else needs to be displayed.
$addenabled = true;

// Check to see if there are any users or groups that can be added.
// If not, disable the add button.
$warningmessage = '';
if ($canedit) {
    if ($groupmode) {
        if (empty($groups)) {
            // There are no groups.
            $warningmessage = get_string('groupsnone', 'quiz');
            $addenabled = false;
        }
    } else {
        // See if there are any students in the quiz.
        if ($showallgroups) {
            $users = get_users_by_capability($context, 'mod/quiz:attempt', 'u.id');
            $nousermessage = get_string('usersnone', 'quiz');
        } else if ($groups) {
            $users = get_users_by_capability($context, 'mod/quiz:attempt', 'u.id', '', '', '', array_keys($groups));
            $nousermessage = get_string('usersnone', 'quiz');
        } else {
            $users = [];
            $nousermessage = get_string('groupsnone', 'quiz');
        }
        $info = new \core_availability\info_module($cm);
        $users = $info->filter_user_list($users);

        if (empty($users)) {
            $addenabled = false;
            $warningmessage = $nousermessage;
        }
    }
}

$table = new overrides_table('Overrides', $mode, $cm, $context, false, $canedit);
$table->define_baseurl(new moodle_url('overrides.php', ['cmid' => $cmid, 'mode' => $mode]));

// Set query to be used by overrides_table.
if ($mode == 'group') {
    $table->set_sql_group($quiz->id, $groups);
} else {
    $table->set_sql_user($quiz->id, $showallgroups, $groups);
}
// Render the page, print the table.
$url = new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cm->id, 'mode' => $mode]);
$title = get_string(
    'overridesforquiz',
    'quiz',
    format_string($quiz->name, true, ['context' => $context]),
);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();
$PAGE->set_secondary_active_tab("mod_quiz_useroverrides");

echo $OUTPUT->header();

// Show success and error messages to the user after import.
if (!empty($SESSION->quiz_import_success)) {
    echo $OUTPUT->notification($SESSION->quiz_import_success, 'success');
    unset($SESSION->quiz_import_success);
}

$renderer = $PAGE->get_renderer('mod_quiz');
$tertiarynav = new \mod_quiz\output\overrides_actions($cmid, $mode, $canedit, $addenabled);
echo $renderer->render($tertiarynav);

if ($mode === 'user') {
    echo $OUTPUT->heading(get_string('useroverrides', 'quiz'));
} else {
    echo $OUTPUT->heading(get_string('groupoverrides', 'quiz'));
}

echo html_writer::start_tag('div', ['id' => 'quizoverrides']);

$table->out(30, true);

$downloadurl = moodle_url::make_pluginfile_url(
    $context->id,
    'mod_quiz',
    'overrides',
    $mode,
    null,
    null,
    true,
);

$button = html_writer::link($downloadurl, get_string('overridedownload', 'quiz'), ['class' => 'btn btn-secondary']);

if ($canedit && !$table->isempty) {
    echo html_writer::empty_tag('br');
    echo $button;
}

if ($table->hasinactive) {
    echo $OUTPUT->notification(get_string('inactiveoverridehelp', 'quiz'), 'info', false);
}

if ($warningmessage) {
    echo $OUTPUT->notification($warningmessage, 'error', false);
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
