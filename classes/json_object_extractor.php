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
 * Locates the first top-level JSON object embedded in a noisy string.
 *
 * LLM responses often wrap the JSON in prose, Markdown code fences or HTML, and the feedback string
 * itself may legitimately contain '{', '}', '[' or ']' characters. A naive brace counter therefore
 * either stops too soon (on a '}' inside a string value) or over-catches (on a '{' inside a string
 * value). This class runs a small recursive-descent scanner that understands JSON structure --
 * objects, arrays and strings -- so structural braces are only counted outside of string literals.
 *
 * The scanner is deliberately lenient about the *content* of strings and scalars: it only needs to
 * find where the top-level object ends, not to validate the JSON. In particular a backslash inside a
 * string escapes the next character unconditionally, so invalid escapes (e.g. LaTeX such as
 * "\(K_\alpha\)") do not break boundary detection. Repairing such invalid escaping so that the
 * located candidate can be decoded is a separate, non-recursive concern handled by
 * {@see self::repair_string_escapes()}.
 *
 * The scan cursor is kept as instance state ({@see self::$pos}) rather than being threaded through
 * every method by reference, so one extractor instance handles a single {@see self::extract()} call.
 *
 * @package    qtype_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_object_extractor {
    /**
     * Maximum JSON nesting depth the scanner will descend into.
     *
     * This mirrors json_decode()'s default depth limit and bounds the scanner's recursion so that
     * pathologically nested (untrusted) LLM output cannot exhaust the PHP call stack.
     */
    public const MAX_JSON_DEPTH = 512;

    /**
     * JSON insignificant whitespace characters, keyed for O(1) isset() lookup.
     *
     * @var array<string, bool>
     */
    private const WHITESPACE = [' ' => true, "\t" => true, "\n" => true, "\r" => true];

    /**
     * Structural characters that terminate an unquoted scalar, keyed for O(1) isset() lookup.
     *
     * @var array<string, bool>
     */
    private const END_SCALAR = [',' => true, '}' => true, ']' => true];

    /** @var string The text currently being scanned. */
    private string $text = '';

    /** @var int The length in bytes of the text currently being scanned. */
    private int $len = 0;

    /** @var int The current scan cursor position within the text. */
    private int $pos = 0;

    /**
     * Locate and return the first top-level JSON object embedded in the given text.
     *
     * @param string $text The raw text possibly containing a JSON object with extra text around it.
     * @return string|null The first balanced {...} object substring, or null if none is found.
     */
    public function extract(string $text): ?string {
        $this->text = $text;
        $this->len = strlen($text);
        // Try each '{' in turn: the first one may not begin a structurally valid object (e.g. prose
        // containing a stray brace), in which case we jump to the next '{' and try again.
        $start = strpos($text, '{');
        while ($start !== false) {
            $this->pos = $start;
            if ($this->scan_object(0)) {
                return substr($this->text, $start, $this->pos - $start);
            }
            $start = strpos($text, '{', $start + 1);
        }
        return null;
    }

    /**
     * Repair illegal escaping inside the string literals of an almost-JSON candidate.
     *
     * LLMs frequently emit feedback that is structurally a JSON object but whose string values
     * contain sequences that are not legal JSON: typically LaTeX with unescaped backslashes
     * (e.g. "\(K_\alpha\)", "\frac", "\beta") or literal control characters such as the raw
     * newlines of a fenced code block. This method walks the text with a flat, non-recursive
     * in-string state machine and fixes exactly those two problems so the result can be decoded in
     * a single json_decode() call.
     *
     * Backslash handling uses a domain-tuned notion of a "valid escape": inside a string literal a
     * backslash is kept only when it begins one of \\  \"  \/  \n  or \uXXXX (four hex digits).
     * Every other backslash -- including \b \f \r \t, which in maths-tutoring context are far
     * more likely to start a LaTeX command (\beta, \frac, \rho, \theta) than to be a genuinely
     * intended backspace / form-feed / carriage-return / tab -- is treated as an illegal lone
     * backslash and doubled.
     *
     * Raw control characters (bytes below 0x20) are illegal inside a JSON string too. Those that
     * carry meaning for the Markdown-formatted feedback -- newline, tab and carriage return -- are
     * escaped to their JSON form so they survive decoding as the same byte (e.g. a fenced code
     * block's newlines and a LaTeX alignment tab are preserved). Every other control character is
     * display noise with no Markdown meaning, so it is dropped.
     *
     * The method is idempotent on candidates whose escaping is already valid under this rule, so it
     * is safe to run on the located substring before decoding. Callers that must preserve a
     * legitimately escaped \b \f \r or \t should try to decode the candidate as-is first and only fall
     * back to this repair when that decode fails.
     *
     * Invariant: although it receives the whole object substring, the flat state machine only ever
     * emits a *changed* byte while inside a string literal; every structural byte ('{', '}', '[',
     * ']', ':', ',', whitespace, scalars) is copied verbatim. Its string boundaries provably match
     * those of the locator that produced the candidate, because both treat a backslash the same way
     * at every quote: a quote is always a valid escape (skipped as a pair), so this pass never
     * reprocesses a '"' as an ordinary character and therefore closes each string on exactly the
     * same quote the locator did. It only ever *adds* escaping inside strings, so on structurally
     * broken input the worst outcome is that the subsequent json_decode() still fails -- it cannot
     * turn broken JSON into a valid-but-wrong object.
     *
     * @param string $json The located candidate object substring, possibly with illegal escaping.
     * @return string The same text with in-string escaping made valid JSON.
     */
    public static function repair_string_escapes(string $json): string {
        $len = strlen($json);
        $out = '';
        $instring = false;
        $i = 0;
        while ($i < $len) {
            $char = $json[$i];
            if (!$instring) {
                $instring = ($char === '"');
                $out .= $char;
                $i++;
                continue;
            }
            // Inside a string literal.
            if ($char === '"') {
                $instring = false;
                $out .= $char;
                $i++;
                continue;
            }
            if ($char === '\\') {
                $next = $i + 1 < $len ? $json[$i + 1] : '';
                if ($next === 'u' && preg_match('/^[0-9a-fA-F]{4}$/', substr($json, $i + 2, 4))) {
                    // A well-formed unicode escape: keep '\u'; the four hex digits follow verbatim.
                    $out .= '\\u';
                    $i += 2;
                    continue;
                }
                if ($next === '"' || $next === '\\' || $next === '/' || $next === 'n') {
                    // A valid escape we honour: copy the backslash and its escaped character as-is.
                    $out .= '\\' . $next;
                    $i += 2;
                    continue;
                }
                // Illegal lone backslash (LaTeX command, stray '\', ...): double it. The following
                // character stays in place to be processed normally on the next iteration.
                $out .= '\\\\';
                $i++;
                continue;
            }
            if (ord($char) < 0x20) {
                // A raw control character is illegal inside a JSON string. Newline, tab and carriage
                // return are meaningful whitespace, so escape them to survive json_decode() as the
                // same byte; every other control character is display noise and is dropped.
                $out .= self::sanitise_control_char($char);
                $i++;
                continue;
            }
            $out .= $char;
            $i++;
        }
        return $out;
    }

    /**
     * Return the JSON representation of a single raw control character (a byte below 0x20).
     *
     * Newline, tab and carriage return are meaningful whitespace and are returned as their JSON
     * escape sequence ("\n", "\t", "\r"). Every other control character -- backspace, form feed,
     * NUL, vertical tab, ESC and the rest of 0x00-0x1F -- has no meaning for the Markdown-formatted
     * feedback and only survives as display noise, so it is dropped (the empty string is returned).
     *
     * @param string $char A single-byte control character.
     * @return string The JSON escape for meaningful whitespace, or '' for anything to be dropped.
     */
    private static function sanitise_control_char(string $char): string {
        switch ($char) {
            case "\n":
                return '\\n';
            case "\t":
                return '\\t';
            case "\r":
                return '\\r';
            default:
                return '';
        }
    }

    /**
     * Scan a JSON value at the cursor, advancing the cursor past it on success.
     *
     * @param int $depth The nesting depth of the container holding this value.
     * @return bool True if a structurally complete value was consumed.
     */
    private function scan_value(int $depth): bool {
        $this->skip_whitespace();
        if ($this->pos >= $this->len) {
            return false;
        }
        $char = $this->text[$this->pos];
        if ($char === '{') {
            return $this->scan_object($depth);
        }
        if ($char === '[') {
            return $this->scan_array($depth);
        }
        if ($char === '"') {
            return $this->scan_string();
        }
        return $this->scan_scalar();
    }

    /**
     * Scan a JSON object at the cursor (which must point at '{'), advancing past the closing '}'.
     *
     * @param int $depth The nesting depth of this object's parent (0 for the top-level object).
     * @return bool True if a structurally complete object was consumed.
     */
    private function scan_object(int $depth): bool {
        // Entering this object adds one level of nesting; refuse to descend past the limit.
        $depth++;
        if ($depth > self::MAX_JSON_DEPTH) {
            return false;
        }
        $this->pos++; // Consume the opening '{'.
        $this->skip_whitespace();
        if ($this->pos < $this->len && $this->text[$this->pos] === '}') {
            $this->pos++; // Empty object.
            return true;
        }
        while ($this->pos < $this->len) {
            $this->skip_whitespace();
            // A member key must be a string.
            if ($this->pos >= $this->len || $this->text[$this->pos] !== '"' || !$this->scan_string()) {
                return false;
            }
            $this->skip_whitespace();
            if ($this->pos >= $this->len || $this->text[$this->pos] !== ':') {
                return false;
            }
            $this->pos++; // Consume ':'.
            if (!$this->scan_value($depth)) {
                return false;
            }
            $this->skip_whitespace();
            if ($this->pos >= $this->len) {
                return false;
            }
            if ($this->text[$this->pos] === ',') {
                $this->pos++;
                continue;
            }
            if ($this->text[$this->pos] === '}') {
                $this->pos++;
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Scan a JSON array at the cursor (which must point at '['), advancing past the closing ']'.
     *
     * @param int $depth The nesting depth of this array's parent.
     * @return bool True if a structurally complete array was consumed.
     */
    private function scan_array(int $depth): bool {
        // Entering this array adds one level of nesting; refuse to descend past the limit.
        $depth++;
        if ($depth > self::MAX_JSON_DEPTH) {
            return false;
        }
        $this->pos++; // Consume the opening '['.
        $this->skip_whitespace();
        if ($this->pos < $this->len && $this->text[$this->pos] === ']') {
            $this->pos++; // Empty array.
            return true;
        }
        while ($this->pos < $this->len) {
            if (!$this->scan_value($depth)) {
                return false;
            }
            $this->skip_whitespace();
            if ($this->pos >= $this->len) {
                return false;
            }
            if ($this->text[$this->pos] === ',') {
                $this->pos++;
                continue;
            }
            if ($this->text[$this->pos] === ']') {
                $this->pos++;
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Scan a JSON string at the cursor (which must point at '"'), advancing past the closing '"'.
     *
     * A backslash escapes the following character unconditionally, so the scanner tolerates invalid
     * escape sequences (e.g. LaTeX) while still finding the correct string boundary.
     *
     * @return bool True if a terminated string was consumed.
     */
    private function scan_string(): bool {
        $this->pos++; // Consume the opening '"'.
        while ($this->pos < $this->len) {
            $char = $this->text[$this->pos];
            if ($char === '\\') {
                $this->pos += 2; // Skip the backslash and the escaped character.
                continue;
            }
            if ($char === '"') {
                $this->pos++; // Consume the closing '"'.
                return true;
            }
            $this->pos++;
        }
        return false; // Unterminated string.
    }

    /**
     * Scan a JSON scalar (number, true, false or null) by consuming up to the next structural
     * delimiter, advancing the cursor.
     *
     * @return bool True if at least one character was consumed.
     */
    private function scan_scalar(): bool {
        $scalarstart = $this->pos;
        while ($this->pos < $this->len) {
            $char = $this->text[$this->pos];
            if (isset(self::END_SCALAR[$char]) || isset(self::WHITESPACE[$char])) {
                break;
            }
            $this->pos++;
        }
        return $this->pos > $scalarstart;
    }

    /**
     * Advance the cursor past any JSON insignificant whitespace.
     */
    private function skip_whitespace(): void {
        while ($this->pos < $this->len) {
            $char = $this->text[$this->pos];
            if (!isset(self::WHITESPACE[$char])) {
                break;
            }
            $this->pos++;
        }
    }
}
