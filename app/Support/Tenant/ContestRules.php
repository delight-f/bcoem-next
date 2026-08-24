<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use Illuminate\Support\Str;

/**
 * Renders the free-text areas of contest_info that arrive as JSON blobs
 * (contest_rules.contestRules etc.). Legacy behavior: when the content is
 * already HTML it is emitted verbatim; otherwise it is treated as
 * Markdown (ENABLE_MARKDOWN) and converted.
 */
final class ContestRules
{
    public static function renderCompetitionRules(?string $contestRulesJson): string
    {
        if ($contestRulesJson === null || $contestRulesJson === '') {
            return '';
        }

        $decoded = json_decode($contestRulesJson, true);

        if (! is_array($decoded)) {
            return '';
        }

        return self::renderText($decoded['competition_rules'] ?? null);
    }

    public static function renderText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (self::isHtml($text)) {
            return $text;
        }

        return Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Legacy is_html(): a leading "<" followed by a tag-ish run means the
     * organizer pasted markup; anything else is Markdown source.
     */
    public static function isHtml(string $text): bool
    {
        return preg_match('/^\s*<[a-zA-Z][^>]*>/', $text) === 1;
    }
}
