<?php

declare(strict_types=1);

/**
 * Normalize HTML so two apps rendering "the same page" diff cleanly.
 *
 * Strips everything that legitimately differs between requests/sessions:
 * csrf tokens, session ids, nonces, cache-busters. Anything left is a REAL
 * behavioral difference.
 *
 * Usage: php normalize.php < raw.html > clean.html
 */

$html = stream_get_contents(STDIN);
if ($html === false) {
    exit(1);
}

$replacements = [
    '/name="_token"[^>]*value="[^"]*"/i'                    => 'name="_token"',
    '/"csrfToken":"[^"]*"/i'                                => '"csrfToken":""',
    '/([?&](?:token|sid|phpsessid|nonce))=[a-zA-Z0-9_-]+/i' => '$1=STRIPPED',
    '/value="[a-f0-9]{16,}"/i'                              => 'value="TOKEN"',
    '/\.(js|css|png|jpg)\?v=[a-z0-9]+/i'                    => '.$1',
];

// Collapse whitespace between tags so reformatting never counts as a diff.
$html = (string) preg_replace(array_keys($replacements), array_values($replacements), $html);
$html = (string) preg_replace('/>\s+</', '><', trim($html));

echo $html;
