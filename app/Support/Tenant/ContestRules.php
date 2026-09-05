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
     * Content for the admin edit textareas (competition_info admin page).
     *
     * Legacy with ENABLE_MARKDOWN on converts stored HTML to Markdown for
     * editing (Markdownify + strip_tags); with the flag off it shows the
     * escaped HTML source. The port has no markdown flag, so the edit box
     * always shows readable text: block boundaries become blank lines,
     * remaining tags are stripped, entities decoded. Non-HTML content is
     * returned unchanged (it is already the editable text form). Saving
     * stores exactly what the organizer sees; the public renderText()
     * markdown branch renders it back to HTML.
     */
    public static function editText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (! self::isHtml($text)) {
            return $text;
        }

        // Block-level boundaries → paragraph breaks.
        $out = preg_replace(
            '/\s*<\/(p|div|li|h[1-6]|blockquote|pre|tr|table|ul|ol|section)>/i',
            "\n\n",
            $text,
        ) ?? $text;
        $out = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $out) ?? $out;
        // Remaining tags (inline or stray) drop, keeping their text.
        $out = strip_tags($out);
        // Collapse leftover whitespace runs around the inserted breaks and
        // normalize CRLF so paragraph breaks are clean blank lines.
        $out = preg_replace('/[ \t]+\n/', "\n", $out) ?? $out;
        $out = preg_replace("/\r\n?/", "\n", $out) ?? $out;
        $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;
        $out = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($out);
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
