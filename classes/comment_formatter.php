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
 * Formats stored AI feedback for display.
 *
 * The AI feedback is stored raw (as returned by the model, typically Markdown with LaTeX
 * delimiters) so nothing filter-specific is baked into the database. All display consumers -- the
 * qtype preview, the grader reference and the two aitext question behaviours -- funnel their stored
 * comment through {@see self::to_html()}, which applies the format and the full filter stack at
 * display time only.
 *
 * @package    qtype_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comment_formatter {

    /**
     * Render a stored AI comment to display HTML.
     *
     * For Markdown comments the backslashes are doubled first: {@see format_text()} runs the comment
     * through Markdown, which consumes one backslash per escape sequence, so doubling keeps a single
     * literal backslash in the output. This is what preserves MathJax delimiters ({@code \( ... \)})
     * long enough for the mathjaxloader filter to act on them at display time. Already-doubled
     * backslashes are collapsed first so they are not over-doubled.
     *
     * Comments stored in any other format (e.g. the legacy {@code FORMAT_HTML} rows written before
     * feedback was stored raw) are passed straight to {@see format_text()} unchanged, so existing
     * attempts keep rendering as before.
     *
     * @param string|null $comment The raw stored feedback, or null when there is none.
     * @param int|string|null $format One of the FORMAT_* constants as stored with the comment.
     * @param \context $context The context to format in (for filters and file rewriting).
     * @return string The comment rendered as display HTML, or the empty string when there is none.
     */
    public static function to_html(?string $comment, $format, \context $context): string {
        if ($comment === null || trim($comment) === '') {
            return '';
        }

        // Missing formats predate raw storage and were implicitly HTML; treat them as such.
        $format = ($format === null || $format === '') ? FORMAT_HTML : (int) $format;

        if ($format === (int) FORMAT_MARKDOWN) {
            $comment = str_replace('\\\\', '\\', $comment);
            $comment = str_replace('\\', '\\\\', $comment);
        }

        return format_text($comment, $format, ['context' => $context]);
    }
}
