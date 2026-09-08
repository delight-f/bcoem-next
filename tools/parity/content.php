<?php

declare(strict_types=1);

/**
 * Content-level normalization for the parity harness.
 *
 * Reduces a page to its visible text so two apps can be compared by what
 * they SAY rather than how their markup is shaped (option B). The port is
 * deliberately not a byte-for-byte transliteration of legacy templates.
 *
 * Pipeline: drop script/style/comment blocks, strip tags, decode entities,
 * collapse whitespace, emit the ordered word stream, strip known
 * out-of-scope chrome substrings (chrome-exclude.txt).
 *
 * With --classify, ALSO strips noise hunks so the harness can bucket a
 * page whose only differences are chrome (P3 Slice 1). Noise signatures
 * are derived from the run-20260830-074646 diff corpus: navbar session
 * blocks, icon glyphs that leak as text, and binary payloads. Word-level
 * hunk classification lives in classify.php (diff-aware); the
 * single-sided signatures here cover insert-only chrome that never
 * appears on the legacy side.
 */
$classify = in_array('--classify', $argv, true);

$html = stream_get_contents(STDIN);

if ($html === false || trim($html) === '') {
    exit(0);
}

// Binary payload guard (P3 Slice 1): urls.txt carries non-HTML targets
// (barcode PNGs). Parsing those as HTML yields garbage tokens (\n inside
// mojibake) that surface as hunks. A payload with control bytes outside
// text-safe ranges is not a page — skip comparison via sentinel.
if (preg_match('/[\x00-\x08\x0e-\x1f]/', $html)) {
    echo "BINARY\n";
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

$stream = implode(' ', $lines);
$stream = preg_replace('/\s+([,.;:!?)])/', '$1', (string) $stream);
$stream = preg_replace('/\(\s+/', '(', (string) $stream);

// Out-of-scope chrome (auth modals, loader, browser warnings) is stripped
// as substrings — legacy emits one giant text line, so line filtering is
// not an option.
$excludeFile = __DIR__.'/chrome-exclude.txt';
if (is_file($excludeFile)) {
    $exclusions = array_filter(array_map('trim', file($excludeFile)));
    $exclusions = array_map(fn ($e) => preg_replace('/ {2,}/', ' ', $e), $exclusions);
    $stream = str_replace($exclusions, '', (string) $stream);
    $stream = preg_replace('/ {2,}/', ' ', (string) $stream);
}

$stream = trim((string) $stream);

if ($classify) {
    // Single-sided chrome insertions the legacy side never renders. The
    // diff-aware pair signatures (navbar block replaced by nothing on
    // legacy) live in classify.php.
    $insertNoise = [
        'ReqSpec',        // Bootstrap/BS3 glyph aria leak
        '×',              // close button rendered as text
    ];
    foreach ($insertNoise as $needle) {
        $stream = str_replace(' '.$needle.' ', ' ', ' '.$stream.' ');
    }
    $stream = trim((string) $stream);
}

echo $stream."\n";
