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
 * A test regarding MDL-77625.
 *
 * @package    core_question
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_course;

use backup_controller;
use restore_controller;
use backup;

defined('MOODLE_INTERNAL') or die();
global $CFG;

require_once($CFG->dirroot . '/mod/quiz/tests/quiz_question_helper_test_trait.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class quiz_question_duplication_bug_test extends \advanced_testcase {
    use \quiz_question_helper_test_trait;

    /**
     * Setup routine executed before each test method.
     */
    protected function setUp(): void {
        global $USER;
        parent::setUp();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $USER;
        $this->resetAfterTest(true);
    }

    /**
     * Tests the course import process by simulating a course backup from one course and
     * restoring it into another twice, verifying that quiz questions are duplicated the
     * first time and reused from the original course the second time.
     */
    public function test_course_import() {
        // Create two courses and a user with editing teacher capabilities.
        $generator = $this->getDataGenerator();
        $course1 = $this->course;
        $course2 = $generator->create_course();
        $teacher = $this->user;
        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $generator->enrol_user($teacher->id, $course2->id, 'editingteacher');

        // Create a quiz with questions in the first course.
        $quiz = $this->create_test_quiz($course1);
        $coursecontext = \context_course::instance($course1->id);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        // Create a question category.
        $cat = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);

        // Create a short answer question.
        $saq = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        // Optionally update the question to simulate editing.
        $questiongenerator->update_question($saq);
        // Add question to quiz.
        quiz_add_quiz_question($saq->id, $quiz);

        // Create a numerical question.
        $numq = $questiongenerator->create_question('numerical', null, ['category' => $cat->id]);
        // Optionally update the question to simulate multiple versions.
        $questiongenerator->update_question($numq);
        $questiongenerator->update_question($numq);
        // Add question to quiz.
        quiz_add_quiz_question($numq->id, $quiz);

        // Create a true false question.
        $tfq = $questiongenerator->create_question('truefalse', null, array('category' => $cat->id));
        // Optionally update the question to simulate multiple versions.
        $questiongenerator->update_question($tfq);
        $questiongenerator->update_question($tfq);
        // Add question to quiz.
        quiz_add_quiz_question($tfq->id, $quiz);

        // Capture original question IDs for verification after import.
        $questionIdsOriginal = [];
        foreach ($this->get_questions_of_last_quiz_in_course($course1) as $slot) {
            array_push($questionIdsOriginal, intval($slot->questionid));
        }

        // Backup and restore for the first time
        $this->backup_and_restore($course1, $course2, $teacher);

        // Verify the question ids from the quiz in the original course are different from the question ids in the duplicated quiz in the second course.
        foreach ($this->get_questions_of_last_quiz_in_course($course2) as $slot) {
            $this->assertNotContains(intval($slot->questionid), $questionIdsOriginal, "Question ID {$slot->questionid} should not be in the original course's question IDs.");
        }

        $this->backup_and_restore($course1, $course2, $teacher);

        // Verify the question ids from the quiz in the original course are STILL different from the question ids in the duplicated quiz in the second course.
        foreach ($this->get_questions_of_last_quiz_in_course($course2) as $slot) {
            $this->assertNotContains(intval($slot->questionid), $questionIdsOriginal, "Question ID {$slot->questionid} should not be in the original course's question IDs even after a second backup/restore.");
        }
    }

    private function backup_and_restore($backup_course, $restore_course, $teacher) {
        $bc = new backup_controller(backup::TYPE_1COURSE, $backup_course->id, backup::FORMAT_MOODLE,
                        backup::INTERACTIVE_NO, backup::MODE_IMPORT, $teacher->id);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $rc = new restore_controller($backupid, $restore_course->id, backup::INTERACTIVE_NO, backup::MODE_IMPORT,
                        $teacher->id, backup::TARGET_CURRENT_ADDING);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
    }

    private function get_questions_of_last_quiz_in_course($course) {
        $modules = get_fast_modinfo($course->id)->get_instances_of('quiz');
        $module = end($modules);
        return \mod_quiz\question\bank\qbank_helper::get_question_structure(
                $module->instance, $module->context);
    }
}
