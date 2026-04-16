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

namespace qtype_aitext\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External API for triggering AI regrading of aitext question attempts.
 *
 * Supports single and bulk regrading. The adhoc task runs under the calling
 * teacher's identity so the teacher's AI model and quota are used.
 *
 * @package    qtype_aitext
 * @copyright  2026 ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trigger_regrade extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptstepids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Question attempt step ID'),
                'List of attempt step IDs to regrade'
            ),
        ]);
    }

    /**
     * Trigger AI regrading for the specified attempt steps.
     *
     * @param array $attemptstepids List of question_attempt_step IDs.
     * @return array Result with the count of queued regrades.
     */
    public static function execute(array $attemptstepids): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptstepids' => $attemptstepids,
        ]);
        $attemptstepids = $params['attemptstepids'];

        if (empty($attemptstepids)) {
            return [
                'count' => 0,
                'message' => get_string('regrade_ai_nothingselected', 'qtype_aitext'),
            ];
        }

        $count = 0;
        foreach ($attemptstepids as $stepid) {
            // Load the step and related data.
            $sql = "SELECT qas.id AS stepid,
                           qa.questionid,
                           qa.questionusageid,
                           qu.contextid,
                           qa.id AS questionattemptid
                      FROM {question_attempt_steps} qas
                      JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                      JOIN {question_usages} qu ON qu.id = qa.questionusageid
                     WHERE qas.id = :stepid";
            $record = $DB->get_record_sql($sql, ['stepid' => $stepid]);
            if (!$record) {
                continue;
            }

            // Validate context and capability.
            $context = \core\context::instance_by_id($record->contextid);
            self::validate_context($context);

            // Load the question and verify it is an aitext question.
            $questiondata = \question_bank::load_question_data($record->questionid);
            if ($questiondata->qtype !== 'aitext') {
                continue;
            }
            /** @var \qtype_aitext_question $question */
            $question = \question_bank::make_question($questiondata);

            // Retrieve the student's response from the step data.
            $responserecord = $DB->get_record('question_attempt_step_data', [
                'attemptstepid' => $stepid,
                'name' => 'answer',
            ]);
            if (!$responserecord || empty($responserecord->value)) {
                continue;
            }

            // Trigger the regrade under the teacher's identity.
            $question->trigger_ai_regrade(
                (int) $stepid,
                $responserecord->value,
                (int) $USER->id,
                (int) $record->contextid
            );
            $count++;
        }

        if ($count === 0) {
            return [
                'count' => 0,
                'message' => get_string('regrade_ai_nothingselected', 'qtype_aitext'),
            ];
        }

        return [
            'count' => $count,
            'message' => get_string('regrade_ai_success', 'qtype_aitext', $count),
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of attempts queued for regrading'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }
}
