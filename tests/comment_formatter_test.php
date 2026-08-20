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

/**
 * Unit tests for {@see \qtype_aitext\comment_formatter}.
 *
 * @package    qtype_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_aitext\comment_formatter
 */
final class comment_formatter_test extends \advanced_testcase {
    /**
     * Empty and whitespace-only comments render to the empty string regardless of format.
     *
     * @return void
     */
    public function test_empty_comment_renders_empty(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();

        $this->assertSame('', comment_formatter::to_html(null, FORMAT_MARKDOWN, $context));
        $this->assertSame('', comment_formatter::to_html('', FORMAT_MARKDOWN, $context));
        $this->assertSame('', comment_formatter::to_html("   \n  ", FORMAT_MARKDOWN, $context));
    }

    /**
     * A Markdown comment is converted to HTML at display time.
     *
     * @return void
     */
    public function test_markdown_is_converted(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();

        $html = comment_formatter::to_html('This is **bold** text.', FORMAT_MARKDOWN, $context);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    /**
     * A stored MathJax delimiter survives Markdown formatting (thanks to backslash doubling) and is
     * picked up by the mathjaxloader filter at display time.
     *
     * @return void
     */
    public function test_mathjax_delimiter_survives_display(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        filter_set_global_state('mathjaxloader', TEXTFILTER_ON);

        // Stored raw: a single backslash before each delimiter parenthesis.
        $html = comment_formatter::to_html('Result \(3 \cdot 7\) is valid.', FORMAT_MARKDOWN, $context);

        $this->assertStringContainsString('filter_mathjaxloader_equation', $html);
    }

    /**
     * A legacy comment stored as FORMAT_HTML is rendered without the Markdown backslash handling, so
     * existing attempts keep displaying as before.
     *
     * @return void
     */
    public function test_legacy_html_format_is_passed_through(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();

        $html = comment_formatter::to_html('<p>Already <em>formatted</em>.</p>', FORMAT_HTML, $context);

        $this->assertStringContainsString('<em>formatted</em>', $html);
    }

    /**
     * A missing/empty format is treated as HTML (legacy default), not Markdown.
     *
     * @return void
     */
    public function test_missing_format_defaults_to_html(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();

        $html = comment_formatter::to_html('<p>Plain <strong>html</strong>.</p>', null, $context);

        $this->assertStringContainsString('<strong>html</strong>', $html);
    }
}
