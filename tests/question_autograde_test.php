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

namespace qtype_aitext;

use advanced_testcase;
use question_attempt_step;
use question_bank;
use question_state;
use qtype_aitext\task\grade_response;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
require_once($CFG->dirroot . '/question/type/aitext/tests/helper.php');
require_once($CFG->dirroot . '/question/type/aitext/question.php');

/**
 * Tests for the autograde setting and teacher regrade functionality (MBS-10691).
 *
 * @package    qtype_aitext
 * @copyright  2026 ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_aitext_question
 */
final class question_autograde_test extends advanced_testcase {
    /** @var string The adhoc task class name used in DB queries. */
    private const TASK_CLASSNAME = '\\qtype_aitext\\task\\grade_response';

    /**
     * Create a question_attempt_step record in the DB and return its ID.
     *
     * @param int $questionid The question ID to link to.
     * @return int The created step ID.
     */
    private function create_db_step(int $questionid): int {
        global $DB;

        $attemptid = $DB->insert_record('question_attempts', [
            'questionusageid' => 1,
            'slot' => 1,
            'behaviour' => 'manualgraded',
            'questionid' => $questionid,
            'variant' => 1,
            'maxmark' => 1,
            'minfraction' => 0,
            'maxfraction' => 1,
            'flagged' => 0,
            'questionsummary' => '',
            'rightanswer' => '',
            'responsesummary' => '',
            'timemodified' => time(),
        ]);
        return (int) $DB->insert_record('question_attempt_steps', [
            'questionattemptid' => $attemptid,
            'sequencenumber' => 0,
            'state' => 'todo',
            'fraction' => null,
            'timecreated' => time(),
            'userid' => 2,
        ]);
    }

    /**
     * Build a question_attempt_step with a specific ID and apply it to a question.
     *
     * Uses reflection to set the step's ID because Moodle's step constructor
     * does not accept an ID parameter.
     *
     * @param \qtype_aitext_question $question The question to apply the step to.
     * @param int $stepid The step ID from the DB.
     */
    private function apply_step_to_question(\qtype_aitext_question $question, int $stepid): void {
        $step = new question_attempt_step();
        $reflection = new \ReflectionProperty($step, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($step, $stepid);
        $question->apply_attempt_state($step);
    }

    /**
     * Create an aitext question via the generator with a specific autograde value.
     *
     * @param int $autograde The autograde setting (1 = enabled, 0 = disabled).
     * @return \qtype_aitext_question The loaded question instance.
     */
    private function create_question_with_autograde(int $autograde): \qtype_aitext_question {
        global $DB;

        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $questiondata = $generator->create_question('aitext', null, [
            'category' => $category->id,
        ]);

        $DB->set_field('qtype_aitext', 'autograde', $autograde, ['questionid' => $questiondata->id]);

        /** @var \qtype_aitext_question $question */
        $question = question_bank::load_question($questiondata->id);
        $question->autograde = $autograde;

        return $question;
    }

    /**
     * Count adhoc tasks for the grade_response task class.
     *
     * @return int The number of queued tasks.
     */
    private function count_grade_tasks(): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
    }

    /**
     * Get a step data value from the DB.
     *
     * @param int $stepid The step ID.
     * @param string $name The data key.
     * @return string|false The value, or false if not found.
     */
    private function get_step_data_value(int $stepid, string $name): string|false {
        global $DB;
        $record = $DB->get_record('question_attempt_step_data', [
            'attemptstepid' => $stepid,
            'name' => $name,
        ]);
        return $record ? $record->value : false;
    }

    /**
     * Count how many records exist for a specific step data key.
     *
     * @param int $stepid The step ID.
     * @param string $name The data key.
     * @return int The record count.
     */
    private function count_step_data_records(int $stepid, string $name): int {
        global $DB;
        return $DB->count_records('question_attempt_step_data', [
            'attemptstepid' => $stepid,
            'name' => $name,
        ]);
    }

    /**
     * Grade_response() behaviour based on autograde setting.
     */

    /**
     * Data provider for testing grade_response() with different autograde values.
     *
     * @return array[] Each case: [autograde, expectTaskQueued, expectedAigradedValue].
     */
    public static function autograde_grade_response_provider(): array {
        return [
            'autograde enabled (default) — task is queued' => [1, true, '0'],
            'autograde disabled — no task, pending_teacher' => [0, false, 'pending_teacher'],
        ];
    }

    /**
     * Test that grade_response() correctly queues or skips the adhoc task
     * depending on the autograde setting, and sets the correct step data.
     *
     * @dataProvider autograde_grade_response_provider
     * @param int $autograde The autograde setting value.
     * @param bool $expecttaskqueued Whether an adhoc task should be queued.
     * @param string $expectedaigradedvalue The expected -aigraded step data value.
     */
    public function test_grade_response_autograde_behaviour(
        int $autograde,
        bool $expecttaskqueued,
        string $expectedaigradedvalue,
    ): void {
        $this->resetAfterTest();

        $question = $this->create_question_with_autograde($autograde);
        $stepid = $this->create_db_step($question->id);
        $this->apply_step_to_question($question, $stepid);

        $tasksbefore = $this->count_grade_tasks();

        $result = $question->grade_response(['answer' => 'The quick brown fox jumps over the lazy dog.']);

        // Both paths must return needs grading with zero fraction.
        $this->assertEqualsWithDelta(0.0, $result[0], PHP_FLOAT_EPSILON);
        $this->assertSame(question_state::$needsgrading, $result[1]);

        // Verify task queuing.
        $tasksafter = $this->count_grade_tasks();
        if ($expecttaskqueued) {
            $this->assertGreaterThan(
                $tasksbefore,
                $tasksafter,
                'An adhoc grading task should have been queued.'
            );
        } else {
            $this->assertEquals(
                $tasksbefore,
                $tasksafter,
                'No adhoc task should be queued when autograde is disabled.'
            );
        }

        // Verify step data.
        $this->assertEquals(
            $expectedaigradedvalue,
            $this->get_step_data_value($stepid, '-aigraded')
        );
    }

    /**
     * When autograde is disabled, the comment step data must contain
     * the pending_teacher hint for the teacher.
     */
    public function test_autograde_disabled_sets_pending_teacher_comment(): void {
        $this->resetAfterTest();

        $question = $this->create_question_with_autograde(0);
        $stepid = $this->create_db_step($question->id);
        $this->apply_step_to_question($question, $stepid);

        $question->grade_response(['answer' => 'Some answer text']);

        $comment = $this->get_step_data_value($stepid, '-comment');
        $this->assertNotFalse($comment, 'Comment step data must be written.');
        $this->assertStringContainsString(
            strip_tags(get_string('autograde_pending_teacher', 'qtype_aitext')),
            strip_tags($comment),
            'Pending teacher message should be present in the comment.'
        );

        $format = $this->get_step_data_value($stepid, '-commentformat');
        $this->assertEquals(FORMAT_HTML, $format, 'Comment format must be HTML.');
    }

    /**
     * Trigger_ai_regrade() method.
     */

    /**
     * Data provider for testing trigger_ai_regrade() with different teacher users.
     *
     * Verifies that the task always runs under the identity of whoever triggered it.
     *
     * @return array[] Each case: teacher index (created dynamically).
     */
    public static function regrade_teacher_identity_provider(): array {
        return [
            'first teacher triggers regrade' => [0],
            'second teacher triggers regrade' => [1],
        ];
    }

    /**
     * Test that trigger_ai_regrade() queues a task under the correct teacher's identity.
     *
     * @dataProvider regrade_teacher_identity_provider
     * @param int $teacherindex Which teacher from the created pair to use (0 or 1).
     */
    public function test_trigger_ai_regrade_uses_correct_teacher_identity(int $teacherindex): void {
        $this->resetAfterTest();
        global $DB;

        $question = $this->create_question_with_autograde(0);
        $stepid = $this->create_db_step($question->id);

        // Create two teachers so we can verify the right one is assigned.
        $teachers = [
            $this->getDataGenerator()->create_user(['username' => 'teacher_a']),
            $this->getDataGenerator()->create_user(['username' => 'teacher_b']),
        ];
        $teacher = $teachers[$teacherindex];

        $question->trigger_ai_regrade(
            $stepid,
            'Student wrote this response.',
            (int) $teacher->id,
            \core\context\system::instance()->id
        );

        // Verify the task was queued with the correct teacher.
        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertCount(1, $tasks, 'Exactly one task should be queued.');

        $task = reset($tasks);
        $this->assertEquals(
            $teacher->id,
            $task->userid,
            "Task must run as teacher '{$teacher->username}' (index {$teacherindex})."
        );
    }

    /**
     * Test that trigger_ai_regrade() sets the step to in-progress state.
     */
    public function test_trigger_ai_regrade_sets_in_progress_state(): void {
        $this->resetAfterTest();

        $question = $this->create_question_with_autograde(0);
        $stepid = $this->create_db_step($question->id);
        $teacher = $this->getDataGenerator()->create_user();

        $question->trigger_ai_regrade(
            $stepid,
            'Student response',
            (int) $teacher->id,
            \core\context\system::instance()->id
        );

        // Verify all expected step data was written.
        $this->assertEquals(
            '0',
            $this->get_step_data_value($stepid, '-aigraded'),
            'Step should be marked as grading in progress.'
        );
        $this->assertNotFalse(
            $this->get_step_data_value($stepid, '-comment'),
            'A placeholder comment must be set.'
        );
        $this->assertEquals(
            FORMAT_HTML,
            $this->get_step_data_value($stepid, '-commentformat'),
            'Comment format must be HTML.'
        );
        // Progress idnumber is only stored when the task gets a valid ID after queueing.
    }

    /**
     * Data provider for upsert (update-or-insert) behaviour of trigger_ai_regrade().
     *
     * Tests that existing step data is overwritten, not duplicated, and that
     * new data is inserted when none existed before.
     *
     * @return array[] Each case: pre-existing step data records.
     */
    public static function regrade_upsert_provider(): array {
        return [
            'no pre-existing data (pure insert)' => [
                [],
            ],
            'pre-existing completed grading (update from 1 to 0)' => [
                [
                    '-aigraded' => '1',
                    '-comment' => 'Old AI feedback from previous grading',
                    '-commentformat' => (string) FORMAT_HTML,
                ],
            ],
            'pre-existing pending_teacher state (update from pending to 0)' => [
                [
                    '-aigraded' => 'pending_teacher',
                    '-comment' => '<em>Waiting for teacher</em>',
                    '-commentformat' => (string) FORMAT_HTML,
                ],
            ],
            'pre-existing error state (update from error to 0)' => [
                [
                    '-aigraded' => 'error',
                    '-comment' => 'AI grading failed.',
                    '-commentformat' => (string) FORMAT_HTML,
                ],
            ],
        ];
    }

    /**
     * Test that trigger_ai_regrade() correctly handles insert and update (upsert)
     * of step data without creating duplicate records.
     *
     * @dataProvider regrade_upsert_provider
     * @param array $preexistingdata Step data records to pre-populate before triggering regrade.
     */
    public function test_trigger_ai_regrade_upsert_behaviour(array $preexistingdata): void {
        $this->resetAfterTest();
        global $DB;

        $question = $this->create_question_with_autograde(0);
        $stepid = $this->create_db_step($question->id);
        $teacher = $this->getDataGenerator()->create_user();

        // Pre-populate step data if specified.
        foreach ($preexistingdata as $name => $value) {
            $DB->insert_record('question_attempt_step_data', [
                'attemptstepid' => $stepid,
                'name' => $name,
                'value' => $value,
            ]);
        }

        // Trigger regrade.
        $question->trigger_ai_regrade(
            $stepid,
            'Student response for regrading.',
            (int) $teacher->id,
            \core\context\system::instance()->id
        );

        // The -aigraded must be '0' (in progress), regardless of previous state.
        $this->assertEquals(
            '0',
            $this->get_step_data_value($stepid, '-aigraded'),
            'After regrade, -aigraded must be 0 (in progress).'
        );

        // The -comment must contain the async placeholder, not old data.
        $comment = $this->get_step_data_value($stepid, '-comment');
        $this->assertStringContainsString(
            strip_tags(get_string('async_grading_placeholder', 'qtype_aitext')),
            strip_tags($comment),
            'Comment must be overwritten with the async placeholder.'
        );

        // No duplicate records for the core keys.
        foreach (['-aigraded', '-comment', '-commentformat'] as $key) {
            $this->assertEquals(
                1,
                $this->count_step_data_records($stepid, $key),
                "There must be exactly one '{$key}' record — no duplicates."
            );
        }

        // Progress idnumber may or may not be written depending on task ID availability.
        $progresscount = $this->count_step_data_records($stepid, '-aiprogressidnumber');
        $this->assertLessThanOrEqual(
            1,
            $progresscount,
            'There must be at most one -aiprogressidnumber record.'
        );
    }

    /**
     * Test helper / factory methods.
     */

    /**
     * Data provider for testing the autograde option via the test helper.
     *
     * @return array[] Each case: [options array, expected autograde value].
     */
    public static function autograde_helper_provider(): array {
        return [
            'default (no option passed) — should be 1' => [[], 1],
            'explicitly enabled' => [['autograde' => 1], 1],
            'explicitly disabled' => [['autograde' => 0], 0],
        ];
    }

    /**
     * Test that the test helper correctly sets the autograde property.
     *
     * @dataProvider autograde_helper_provider
     * @param array $options Options to pass to the helper.
     * @param int $expected Expected autograde value.
     */
    public function test_helper_autograde_option(array $options, int $expected): void {
        $this->resetAfterTest();

        $question = \qtype_aitext_test_helper::make_aitext_question($options);
        $this->assertSame($expected, $question->autograde);
    }

    /**
     * Persistence: autograde saved and loaded through questiontype.
     */

    /**
     * Data provider for autograde persistence through save/load cycle.
     *
     * @return array[] Each case: [autograde value to save].
     */
    public static function autograde_persistence_provider(): array {
        return [
            'save autograde=1 and reload' => [1],
            'save autograde=0 and reload' => [0],
        ];
    }

    /**
     * Test that the autograde setting persists correctly through
     * save_question_options() and load_question().
     *
     * @dataProvider autograde_persistence_provider
     * @param int $autograde The autograde value to save.
     */
    public function test_autograde_persists_through_save_load_cycle(int $autograde): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $questiondata = $generator->create_question('aitext', 'editor', [
            'category' => $category->id,
        ]);

        // Build form data and set the autograde value.
        $formdata = \test_question_maker::get_question_form_data('aitext', 'editor');
        $formdata->id = $questiondata->id;
        $formdata->context = \core\context::instance_by_id($category->contextid);
        $formdata->autograde = $autograde;

        $qtype = question_bank::get_qtype('aitext');
        $qtype->save_question_options($formdata);

        // Reload and verify.
        /** @var \qtype_aitext_question $loaded */
        $loaded = question_bank::load_question($questiondata->id);
        $this->assertSame(
            $autograde,
            (int) $loaded->autograde,
            "After save/load cycle, autograde should be {$autograde}."
        );
    }

    /**
     * Edge cases.
     */

    /**
     * Test that an incomplete response is not graded regardless of autograde setting.
     */
    public function test_incomplete_response_returns_zero_regardless_of_autograde(): void {
        $this->resetAfterTest();

        // Test with autograde disabled — should return zero and queue no task.
        $question = $this->create_question_with_autograde(0);
        $stepid = $this->create_db_step($question->id);
        $this->apply_step_to_question($question, $stepid);

        $result = $question->grade_response(['answer' => '']);

        $this->assertEquals(0, $result[0]);
        $this->assertEquals(question_state::$needsgrading, $result[1]);
        $this->assertEquals(
            0,
            $this->count_grade_tasks(),
            'No task should be queued for an incomplete response with autograde disabled.'
        );
    }

    /**
     * Test that triggering regrade twice on the same step doesn't create duplicate step data,
     * and both tasks are queued independently.
     */
    public function test_double_regrade_does_not_duplicate_step_data(): void {
        $this->resetAfterTest();
        global $DB;

        $question = $this->create_question_with_autograde(0);
        $stepid = $this->create_db_step($question->id);
        $teacher = $this->getDataGenerator()->create_user();
        $contextid = \core\context\system::instance()->id;

        // First regrade.
        $question->trigger_ai_regrade($stepid, 'Response v1', (int) $teacher->id, $contextid);

        // Second regrade (e.g. teacher clicked again) — should be blocked by race condition guard.
        $question->trigger_ai_regrade($stepid, 'Response v1', (int) $teacher->id, $contextid);

        // Only one task should be queued because the guard blocks the second call while first is in progress.
        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertCount(1, $tasks, 'Second regrade should be blocked while first is in progress.');

        // No duplicate records for any key.
        foreach (['-aigraded', '-comment', '-commentformat'] as $key) {
            $this->assertEquals(
                1,
                $this->count_step_data_records($stepid, $key),
                "There must be exactly one '{$key}' record — no duplicates."
            );
        }

        // Progress idnumber may or may not be written depending on task ID availability.
        $progresscount = $this->count_step_data_records($stepid, '-aiprogressidnumber');
        $this->assertLessThanOrEqual(
            1,
            $progresscount,
            'There must be at most one -aiprogressidnumber record.'
        );
    }
}
