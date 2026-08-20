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
 * Unit tests for {@see \qtype_aitext\json_object_extractor}.
 *
 * @package    qtype_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_aitext\json_object_extractor
 */
final class json_object_extractor_test extends \basic_testcase {
    /**
     * The locator returns the first balanced top-level object, ignoring surrounding text.
     *
     * @dataProvider extract_provider
     * @param string $text The raw text to scan.
     * @param string|null $expected The substring the locator should return, or null.
     * @return void
     */
    public function test_extract(string $text, ?string $expected): void {
        $this->assertSame($expected, (new json_object_extractor())->extract($text));
    }

    /**
     * Data provider for {@see self::test_extract()}.
     *
     * @return array<string, array{text: string, expected: string|null}>
     */
    public static function extract_provider(): array {
        return [
            'no object at all' => [
                'text' => 'just some prose without any braces',
                'expected' => null,
            ],
            'clean object' => [
                'text' => '{"feedback":"good","marks":"5"}',
                'expected' => '{"feedback":"good","marks":"5"}',
            ],
            'prose wrapped' => [
                'text' => 'Here you go: {"feedback":"good","marks":"5"} thanks!',
                'expected' => '{"feedback":"good","marks":"5"}',
            ],
            'markdown fenced' => [
                // @codingStandardsIgnoreLine moodle.Strings.ForbiddenStrings.Found
                'text' => "```json\n{\"feedback\":\"good\",\"marks\":\"5\"}\n```",
                'expected' => '{"feedback":"good","marks":"5"}',
            ],
            'brace inside string value is not structural' => [
                'text' => '{"feedback":"use the set {1,2,3} here","marks":"5"}',
                'expected' => '{"feedback":"use the set {1,2,3} here","marks":"5"}',
            ],
            'closing brace inside string does not stop early' => [
                'text' => '{"feedback":"the } is fine","marks":"5"}',
                'expected' => '{"feedback":"the } is fine","marks":"5"}',
            ],
            'nested object' => [
                'text' => '{"a":{"b":{"c":"d"}},"marks":"1"}',
                'expected' => '{"a":{"b":{"c":"d"}},"marks":"1"}',
            ],
            'array value' => [
                'text' => '{"items":[1,2,3],"marks":"1"}',
                'expected' => '{"items":[1,2,3],"marks":"1"}',
            ],
            'stray brace before real object' => [
                'text' => 'a { not json } and then {"feedback":"x","marks":"1"}',
                'expected' => '{"feedback":"x","marks":"1"}',
            ],
            'unterminated object' => [
                'text' => '{"feedback":"x","marks":"1"',
                'expected' => null,
            ],
            'escaped quote inside string' => [
                'text' => '{"feedback":"say \\"hi\\" now","marks":"1"}',
                'expected' => '{"feedback":"say \\"hi\\" now","marks":"1"}',
            ],
        ];
    }

    /**
     * Deeply nested input beyond the scanner's depth cap is refused rather than exhausting the stack.
     *
     * @return void
     */
    public function test_extract_refuses_excessive_depth(): void {
        // One more nesting level than the scanner will descend into, so extraction must refuse it.
        $levels = json_object_extractor::MAX_JSON_DEPTH;
        $deep = str_repeat('[', $levels) . str_repeat(']', $levels);
        $text = '{"a":' . $deep . '}';

        $this->assertNull((new json_object_extractor())->extract($text));
    }

    /**
     * The repair pass turns almost-JSON into valid JSON that decodes to the intended value.
     *
     * @dataProvider repair_provider
     * @param string $input The candidate with (possibly) illegal escaping.
     * @param string $expectedfeedback The decoded feedback string once repaired and decoded.
     * @return void
     */
    public function test_repair_string_escapes_decodes(string $input, string $expectedfeedback): void {
        $repaired = json_object_extractor::repair_string_escapes($input);
        $decoded = json_decode($repaired);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Repaired JSON should decode cleanly.');
        $this->assertSame($expectedfeedback, $decoded->feedback);
    }

    /**
     * Data provider for {@see self::test_repair_string_escapes_decodes()}.
     *
     * @return array<string, array{input: string, expectedfeedback: string}>
     */
    public static function repair_provider(): array {
        return [
            'latex alpha with unescaped backslashes' => [
                'input' => '{"feedback":"\(K_\alpha\)","marks":"1"}',
                'expectedfeedback' => '\(K_\alpha\)',
            ],
            'latex beta whose first letter is an escape letter' => [
                'input' => '{"feedback":"\beta and \frac{1}{2}","marks":"1"}',
                'expectedfeedback' => '\beta and \frac{1}{2}',
            ],
            'latex commands r t b f are doubled not treated as escapes' => [
                'input' => '{"feedback":"\rho \tan \bar \forall","marks":"1"}',
                'expectedfeedback' => '\rho \tan \bar \forall',
            ],
            'already escaped backslashes are preserved' => [
                'input' => '{"feedback":"\\\\(K_\\\\alpha\\\\)","marks":"1"}',
                'expectedfeedback' => '\(K_\alpha\)',
            ],
            'valid newline escape is honoured' => [
                'input' => '{"feedback":"line1\nline2","marks":"1"}',
                'expectedfeedback' => "line1\nline2",
            ],
            'valid unicode escape is honoured' => [
                'input' => '{"feedback":"snow \u2603 man","marks":"1"}',
                'expectedfeedback' => "snow \u{2603} man",
            ],
            'bogus unicode escape is doubled' => [
                'input' => '{"feedback":"\underline{x}","marks":"1"}',
                'expectedfeedback' => '\underline{x}',
            ],
            'raw newline control char is escaped and survives' => [
                'input' => "{\"feedback\":\"code:\nline2\",\"marks\":\"1\"}",
                'expectedfeedback' => "code:\nline2",
            ],
            'raw tab control char is escaped and survives' => [
                'input' => "{\"feedback\":\"a\tb\",\"marks\":\"1\"}",
                'expectedfeedback' => "a\tb",
            ],
            'raw carriage return control char is escaped and survives' => [
                'input' => "{\"feedback\":\"a\rb\",\"marks\":\"1\"}",
                'expectedfeedback' => "a\rb",
            ],
            'backspace and form feed control chars are stripped' => [
                'input' => "{\"feedback\":\"a" . chr(0x08) . chr(0x0c) . "b\",\"marks\":\"1\"}",
                'expectedfeedback' => 'ab',
            ],
            'exotic control chars (nul, vertical tab, esc) are stripped' => [
                'input' => "{\"feedback\":\"a" . chr(0x00) . chr(0x0b) . chr(0x1b) . "b\",\"marks\":\"1\"}",
                'expectedfeedback' => 'ab',
            ],
        ];
    }

    /**
     * Repairing valid JSON (under the domain rule) leaves it unchanged: the pass is idempotent.
     *
     * @return void
     */
    public function test_repair_is_idempotent_on_valid_input(): void {
        $valid = '{"feedback":"plain \\\\ and \\" and \\/ and \\n","marks":"1"}';
        $once = json_object_extractor::repair_string_escapes($valid);
        $twice = json_object_extractor::repair_string_escapes($once);

        $this->assertSame($valid, $once);
        $this->assertSame($once, $twice);
    }

    /**
     * Backslashes outside string literals (there are none legal in the grammar) are never touched;
     * structural characters and whitespace between tokens pass through unchanged.
     *
     * @return void
     */
    public function test_repair_leaves_structure_untouched(): void {
        $input = '{ "a" : "\alpha" , "b" : [ 1 , 2 ] }';
        $repaired = json_object_extractor::repair_string_escapes($input);
        $decoded = json_decode($repaired);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('\alpha', $decoded->a);
        $this->assertSame([1, 2], $decoded->b);
    }
}
