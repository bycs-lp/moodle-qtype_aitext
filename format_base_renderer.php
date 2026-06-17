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
 * Abstract base renderer for different response formats.
 *
 * @package    qtype_aitext
 * @subpackage aitext
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Abstract out the differences between different type of response format.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class qtype_aitext_format_renderer_base extends plugin_renderer_base {
    /** @var question_display_options Question display options instance for any necessary information for rendering the question. */
    protected $displayoptions;

    /**
     * Question number setter.
     *
     * @param question_display_options $displayoptions
     */
    public function set_displayoptions(question_display_options $displayoptions): void {
        $this->displayoptions = $displayoptions;
    }

    /**
     * Render the students response when the question is in read-only mode.
     *
     * @param string $name the variable name this input edits.
     * @param question_attempt $qa the question attempt being display.
     * @param question_attempt_step $step the current step.
     * @param int $lines approximate size of input box to display.
     * @param object $context the context teh output belongs to.
     * @return string html to display the response.
     */
    public function response_area_read_only($name, $qa, $step, $lines, $context) {
        global $USER;

        $question = $qa->get_question();
        $uniqid = uniqid();
        $readonlyareaid = 'aitext_readonly_area' . $uniqid;
        $spellcheckeditbuttonid = 'aitext_spellcheckedit' . $uniqid;

        $response = $this->prepare_response($name, $qa, $step, $context);

        $labelbyid = $qa->get_qt_field_name($name) . '_label';
        $responselabel = $this->displayoptions->add_question_identifier_to_label(get_string('answertext', 'qtype_aitext'));

        $divoptions = [
            'id' => $readonlyareaid,
            'role' => 'textbox',
            'aria-readonly' => 'true',
            'aria-labelledby' => $labelbyid,
            'class' => $this->class_name() . ' qtype_aitext_response readonly',
            'style' => 'min-height: ' . ($lines * 1.25) . 'em;',
        ];
        // Height $lines * 1.25 because that is a typical line-height on web pages.
        // That seems to give results that look OK.

        $isspellcheckused = $question->spellcheck;
        $spellcheckedresponse = $this->prepare_response_spellcheck($qa);

        if ($isspellcheckused) {
            // Lib to display the spellcheck diff.
            $this->page->requires->js_call_amd('qtype_aitext/diff');
            $this->page->requires->js_call_amd(
                'qtype_aitext/spellcheck',
                'init',
                ['#' . $readonlyareaid, '#' . $spellcheckeditbuttonid]
            );

            $divoptions['data-spellcheck'] = $spellcheckedresponse;
            $divoptions['data-questionattemptid'] = $qa->get_database_id();
            $divoptions['data-answer'] = $response;
        }

        $output = html_writer::tag('h4', $responselabel, ['id' => $labelbyid, 'class' => 'visually-hidden']);
        $output .= html_writer::tag('div', $response, $divoptions);

        if (
                $isspellcheckused &&
                (
                        has_capability('mod/quiz:grade', $context) ||
                        has_capability('mod/quiz:regrade', $context) ||
                        ($context->contextlevel === CONTEXT_USER && intval($USER->id) === intval($context->instanceid))
                )
        ) {
            $btnoptions = ['id' => $spellcheckeditbuttonid, 'class' => 'btn btn-link'];
            $output .= html_writer::tag(
                'button',
                $this->output->pix_icon(
                    'i/edit',
                    get_string('spellcheckedit', 'qtype_aitext'),
                    'moodle'
                ) . " " . get_string('spellcheckedit', 'qtype_aitext'),
                $btnoptions
            );
        }

        return $output;
    }

    /**
     * Reduce the spellcheck text to plain text suitable for char-by-char diffing in JS.
     *
     * The AI-generated value (_spellcheckresponse) has no inherent format and is
     * treated as plain text. The teacher-edited value (spellcheckedit) carries
     * its own format (HTML, Markdown, etc.) which we render to HTML first and
     * then strip down to plain text.
     *
     * @param question_attempt $qa the question attempt.
     * @return string plain text spellcheck content.
     */
    protected function prepare_response_spellcheck(question_attempt $qa): string {
        $teacheredit = $qa->get_last_behaviour_var('spellcheckedit');
        if ($teacheredit !== null) {
            $format = (int) ($qa->get_last_behaviour_var('spellcheckeditformat') ?? FORMAT_HTML);
            $html = format_text($teacheredit, $format, ['para' => false, 'noclean' => false]);
            return html_to_text($html, 0, false);
        }
        $ai = $qa->get_last_behaviour_var('_spellcheckresponse') ?? '';
        // AI output is treated as plain text — no markup expected.
        return html_to_text(format_text($ai, FORMAT_PLAIN, ['para' => false]), 0, false);
    }

    /**
     * Render the students respone when the question is in read-only mode.
     * @param string $name the variable name this input edits.
     * @param question_attempt $qa the question attempt being display.
     * @param question_attempt_step $step the current step.
     * @param int $lines approximate size of input box to display.
     * @param object $context the context teh output belongs to.
     * @return string html to display the response for editing.
     */
    abstract public function response_area_input(
        $name,
        question_attempt $qa,
        question_attempt_step $step,
        $lines,
        $context
    );

    /**
     * Specific class name to add to the input element.
     *
     * @return string
     */
    abstract protected function class_name();
}
