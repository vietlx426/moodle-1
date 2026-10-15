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

namespace mod_quiz\output;

use advanced_testcase;
use core\context\course as context_course;
use mod_quiz\structure;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/tests/quiz_question_helper_test_trait.php');

/**
 * Tests for {@see edit_renderer}.
 *
 * @package   mod_quiz
 * @copyright 2026 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(edit_renderer::class)]
final class edit_renderer_test extends advanced_testcase {
    use \quiz_question_helper_test_trait;

    /**
     * Create a quiz with a single random question slot.
     *
     * @return \mod_quiz\quiz_settings the quiz.
     */
    protected function create_quiz_with_random_slot(): \mod_quiz\quiz_settings {
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quizobj = $quizgenerator->create_test_quiz([]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => $quizobj->get_context()->id]);
        $this->add_random_questions($quizobj->get_quizid(), 1, $cat->id, 1);
        return $quizobj;
    }

    public function test_random_question_links_to_source_bank(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $quizobj = $this->create_quiz_with_random_slot();
        $structure = structure::create_for_quiz($quizobj);

        $renderer = $PAGE->get_renderer('mod_quiz', 'edit');
        $html = $renderer->random_question($structure, 1, new \moodle_url('/mod/quiz/edit.php'));

        $this->assertStringContainsString('mod_quiz_random_qbank_link', $html);
        $this->assertStringContainsString('cmid=' . $quizobj->get_cmid(), $html);
    }

    public function test_random_question_without_resolvable_source_bank_omits_link(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $quizobj = $this->create_quiz_with_random_slot();

        // Point the random slot at a context that is not a module context, so no source bank can be resolved.
        $DB->set_field(
            'question_set_references',
            'questionscontextid',
            context_course::instance($quizobj->get_courseid())->id,
            ['component' => 'mod_quiz', 'questionarea' => 'slot'],
        );

        $structure = structure::create_for_quiz($quizobj);
        $this->assertNull($structure->get_source_bank(1));

        $renderer = $PAGE->get_renderer('mod_quiz', 'edit');
        $html = $renderer->random_question($structure, 1, new \moodle_url('/mod/quiz/edit.php'));

        $this->assertStringNotContainsString('mod_quiz_random_qbank_link', $html);
        $this->assertStringContainsString('data-action="editrandomquestion"', $html);
    }
}
