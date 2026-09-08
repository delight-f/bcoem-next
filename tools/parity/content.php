<?php

declare(strict_types=1);

/**
 * Content-level normalization for the parity harness (option B).
 *
 * Reduces a page to its visible text so two apps can be compared by what
 * they SAY rather than how their markup is shaped. The standalone port is
 * deliberately not a byte-for-byte transliteration of legacy templates, so
 * markup-level diffs are expected and triaged separately; only content
 * divergence counts as a regression at this stage.
 *
 * Pipeline: drop script/style/comment blocks, strip tags, decode entities,
 * collapse whitespace, emit one trimmed line per text line. The harness
 * then filters known out-of-scope chrome lines (see chrome-exclude.txt)
 * before diffing.
 */
$html = stream_get_contents(STDIN);

if ($html === false || trim($html) === '') {
    exit(0);
}

$html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', "\n", $html);
$html = preg_replace('#<!--.*?-->#s', "\n", $html);

$text = preg_replace('#<[^>]+>#', "\n", (string) $html);
$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = str_replace("\xc2\xa0", ' ', $text);
$text = preg_replace("/\r\n|\r/", "\n", (string) $text);
$text = preg_replace('/[ \t]+/', ' ', (string) $text);

$lines = [];
foreach (explode("\n", (string) $text) as $line) {
    $line = trim($line);
    if ($line !== '') {
        $lines[] = $line;
    }
}

// Compare word streams: line breaks are markup artifacts (legacy glues
// blocks together, the port separates them), so diff the ordered word
// sequence with punctuation spacing normalized.
$stream = implode(' ', $lines);
$stream = preg_replace('/\s+([,.;:!?)])/', '$1', (string) $stream);
$stream = preg_replace('/\(\s+/', '(', (string) $stream);
$stream = trim((string) $stream);

// Out-of-scope chrome (auth modals, loader, browser warnings) is stripped
// as substrings — legacy emits one giant text line, so line filtering is
// not an option.
$excludeFile = __DIR__.'/chrome-exclude.txt';
if (is_file($excludeFile)) {
    $exclusions = array_filter(array_map('trim', file($excludeFile)));
    // Exclusion lines may carry legacy double spaces; normalize them the
    // same way the stream was normalized so matching is reliable.
    $exclusions = array_map(fn ($e) => preg_replace('/ {2,}/', ' ', $e), $exclusions);
    $stream = str_replace($exclusions, '', (string) $stream);
    $stream = preg_replace('/ {2,}/', ' ', (string) $stream);
}

$stream = trim((string) $stream);

echo $stream."\n";
