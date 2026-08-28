<?php

/**
 * Link-map checker — compares the link graph of a legacy page and its port
 * twin. Reads the raw HTML pairs saved by parity.sh, extracts every href,
 * resolves and normalizes it to a canonical page key, translates legacy
 * query URLs through the urls.txt inventory, and reports:
 *
 *   MISSING  — legacy link with no equivalent in the port page
 *   EXTRA    — port link with no legacy equivalent
 *
 * Usage: php linkmap.php <raw-legacy-html> <raw-new-html> <urls.txt>
 * Exit 0 if no MISSING links (EXTRA is informational).
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: php linkmap.php <legacy.html> <new.html> <urls.txt>\n");
    exit(2);
}

/** Normalize a URL path to a canonical "/..." form (root = "/"). */
function canonPath(string $path): string
{
    $path = trim($path, '/');

    return $path === '' ? '/' : '/'.$path;
}

/** @return array<string,string> canonical key => original href */
function extractLinks(string $html, string $host): array
{
    $links = [];
    if (! preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
        return $links;
    }
    foreach ($m[1] as $href) {
        // Raw HTML hrefs are entity-encoded (&amp;); unescape before parsing
        // so &amp;-variants canonicalize to the same key as a real '&' query.
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^(mailto:|javascript:|#|tel:)/i', $href)) {
            continue;
        }
        $parts = parse_url($href);
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        if (isset($parts['host']) && $parts['host'] !== $host) {
            continue; // external
        }
        // Canonical key: path + sorted query pairs (drop fragment).
        parse_str($query, $q);
        ksort($q);
        $key = canonPath($path);
        if ($q !== []) {
            $key .= '?'.http_build_query($q);
        }
        $links[$key] = $href;
    }

    return $links;
}

$legacyHtml = (string) file_get_contents($argv[1]);
$newHtml = (string) file_get_contents($argv[2]);
$host = '127.0.0.1';
// Legacy emits commented-out navbar rows (nav.sec.php:356-362) whose hrefs
// are not user-visible links. Strip comments before extraction so they
// do not count as MISSING.
$legacyHtml = preg_replace('/<!--.*?-->/s', '', $legacyHtml);
$newHtml = preg_replace('/<!--.*?-->/s', '', $newHtml);

// Inventory: legacy query-URL (normalized) => port path.
$map = [];
foreach (file($argv[3], FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $line = preg_replace('/^[a-z]+\|/', '', $line);
    $parts = explode('|', $line);
    $legacy = $parts[0];
    $port = $parts[1] ?? $parts[0];
    $lp = parse_url($legacy);
    parse_str($lp['query'] ?? '', $q);
    ksort($q);
    // No-query legacy links (e.g. bare "/") key as "/" not "/?" — mirror
    // extractLinks' key form so lookups hit.
    $mapKey = canonPath($lp['path'] ?? '/');
    if ($q !== []) {
        $mapKey .= '?'.http_build_query($q);
    }
    $map[$mapKey] = canonPath($port);
}

$legacyLinks = extractLinks($legacyHtml, $host);
$newLinks = extractLinks($newHtml, $host);

// Translate legacy keys to port keys via the inventory.
$translated = [];
foreach ($legacyLinks as $key => $href) {
    // Strip legacy wrapper: index.php?section=... stays as-is in the key.
    $translated[$map[$key] ?? 'UNMAPPED:'.$key] = $href;
}

$missing = array_diff(array_keys($translated), array_keys($newLinks));
$extra = array_diff(array_keys($newLinks), array_keys($translated));

foreach ($missing as $key) {
    $src = $translated[$key];
    echo "MISSING\t".($key === $src ? 'UNMAPPED '.$key : $key)."\t(legacy: $src)\n";
}
foreach ($extra as $key) {
    echo "EXTRA\t$key\t(port: {$newLinks[$key]})\n";
}

exit($missing === [] ? 0 : 1);
