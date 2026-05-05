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
 * This file contains the form for importing a framework from a file.
 *
 * @package   mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quiz\form;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

use moodleform;
use core_text;
use context_module;
use csv_import_reader;

require_once($CFG->libdir . '/formslib.php');

/**
 * Import user/group quiz overrides.
 *
 * @package   mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_override_form extends moodleform {
    /**
     * Import quiz overrides form definition.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        // Additional code to capture parameters.
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $mode = optional_param('mode', '', PARAM_ALPHANUMEXT);
        $context = context_module::instance($cmid);

        // Get the course module object.
        $cm = get_coursemodule_from_id(null, $cmid);
        if (!$cm) {
            throw new moodle_exception('invalidcoursemodule');
        }

        // Get the course object.
        $course = get_course($cm->course);

        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $mform = $this->_form;

        $mform->addElement('header', 'importheader', get_string('import'));

        // Include cmid and action as hidden elements to pass parameters on submission.
        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'mode', $mode);
        $mform->setType('mode', PARAM_ALPHANUMEXT);

        // Filepicker.
        $element = $mform->createElement(
            'filepicker',
            'overridefile',
            get_string('overridefile', 'quiz'),
            null,
            ['accepted_types' => '.csv'],
        );
        $mform->addElement($element);
        $mform->addHelpButton('overridefile', 'overridefile_' . $mode, 'quiz');
        $mform->addRule('overridefile', null, 'required');
        $mform->addElement('hidden', 'confirm', 0);
        $mform->setType('confirm', PARAM_BOOL);

        // Template File.
        $downloadurl = \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_quiz',
            'overrides',
            $mode,
            null,
            null,
            true,
        );
        $downloadurl->params([
            'template' => 1,
        ]);

        $templatename = clean_filename("{$course->shortname}-{$quiz->name}-{$mode}-overrides-template") . '.csv';
        $templatelink = \html_writer::link($downloadurl, $templatename);
        $mform->addElement('static', 'templatefile', get_string('templatefile', 'quiz'), $templatelink);
        $mform->addHelpButton('templatefile', 'templatefile', 'quiz', null, null, $mode . 's');

        // Action buttons.
        $this->add_action_buttons(true, get_string('importvalidate', 'quiz'));
    }

    /**
     * Display an error on the import form.
     * @param string $msg
     */
    public function set_import_error($msg) {
        $mform = $this->_form;

        $mform->setElementError('overridefile', $msg);
    }
}
