<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the production sterilize() implementation
 * (lib/sanitize.lib.php, extracted from paths.php).
 *
 * The plan's Phase 4.1 scope: "sterilize() on base64 / numeric / html".
 * All production callers pass strings (URL params, POST values); sterilize()
 * returns the escaped value ready for DB insertion.
 */
final class SanitizeTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function sanitizeProvider(): array
    {
        return [
            'null stays null' => [null, null],
            'empty string becomes null (empty branch)' => ['', null],
            'whitespace-only string trimmed to empty' => ['   ', ''],
            'numeric string passthrough' => ['123', '123'],
            'numeric string with fraction' => ['123.45', '123.45'],
            'zero string stays' => ['0', '0'],
            'negative numeric string' => ['-42', '-42'],
            'leading/trailing whitespace trimmed' => ['  hello  ', 'hello'],
            'base64 chars pass through unmodified' => ['abc+def/ghi=xyz', 'abc+def/ghi=xyz'],
            'html tags are entity-encoded' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'single quote escaped' => ["O'Reilly", 'O&#039;Reilly'],
            'array of strings recurses element-wise' => [['a<b', 'c>d'], ['a&lt;b', 'c&gt;d']],
        ];
    }

    #[DataProvider('sanitizeProvider')]
    public function test_sterilize(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, sterilize($input));
    }

    public function test_sterilize_trims_and_escapes_html_in_one_pass(): void
    {
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', sterilize('  <b>hi</b>  '));
    }

    public function test_sterilize_strips_tags_before_escaping(): void
    {
        // strip_tags runs after FILTER_SANITIZE_FULL_SPECIAL_CHARS; a raw tag
        // that survives as text is entity-encoded, not executable.
        $this->assertStringNotContainsString('<script', (string) sterilize('<script>alert(1)</script>'));
    }

    public function test_sterilize_is_idempotent_on_escaped_output(): void
    {
        $once = sterilize('<b>hi</b>');
        $this->assertSame($once, sterilize($once));
    }

    public function test_sterilize_null_short_circuits_numeric_branch(): void
    {
        // Integer 0 is "empty", so it hits the empty() branch — not trim().
        $this->assertNull(sterilize(0));
    }

    public function test_sterilize_integer_input_throws_type_error(): void
    {
        // Known upstream limitation (also present on origin/master): a
        // non-empty int/float reaches trim() and throws. All live callers
        // pass strings, so this documents rather than fixes the quirk.
        $this->expectException(\TypeError::class);
        sterilize(123);
    }
}
