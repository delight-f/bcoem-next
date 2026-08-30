<?php

declare(strict_types=1);

/**
 * Diff-aware noise classifier for the parity harness (P3 Slice 1).
 *
 * A page whose content streams differ only by known chrome noise should
 * not count as a real content regression. This script splits both text
 * streams into words, runs `diff` over them (word-per-line, so hunks are
 * word-aligned exactly like the corpus analysis), and classifies each
 * hunk against noise signatures derived from the run-20260830-074646
 * diff corpus.
 *
 * Usage: php classify.php legacy.text new.text
 * Output (single line + optional detail):
 *   VERDICT PASS    streams identical
 *   VERDICT SKIP    either side is the BINARY sentinel
 *   VERDICT NOISE   all hunks matched a noise signature
 *   VERDICT REAL    >=1 real hunk, followed by up to 5 example lines
 * Exit code 0 always; parity.sh buckets on the verdict.
 */

$signatures = [
    // Navbar session block: legacy renders its full logged-in navbar
    // (Toggle navigation Home <email> ... Log Out [Auto Log Out in N])
    // where the port emits only the compact user menu, or vice versa.
    // Match the side that is a pure deletion/insertion beginning with
    // the navbar anchor and ending in Log Out (optionally the countdown).
    'navbar_session' => static function (string $a, string $b): bool {
        $nav = '/^(?:Toggle navigation )?Home \S+@\S+.*Log Out(?: Auto Log Out in \S+)?$/';
        return (trim($a) === '' && preg_match($nav, trim($b)) === 1)
            || (trim($b) === '' && preg_match($nav, trim($a)) === 1);
    },
    // Session countdown tail.
    'auto_logout' => static function (string $a, string $b): bool {
        return (trim($a) === '' && str_contains($b, 'Auto Log Out in'))
            || (trim($b) === '' && str_contains($a, 'Auto Log Out in'));
    },
    // Icon/aria glyphs that leak into the text stream, either as a pure
    // insertion/deletion or a lone glyph token stranded by word diffing
    // (the countdown modal renders `x Session About To Expire ...` on
    // both sides; the single x can land in its own hunk).
    'glyph' => static function (string $a, string $b): bool {
        $lone = static fn (string $x): bool => in_array(trim($x), ['ReqSpec', '×', '*'], true);
        return $lone($a) || $lone($b);
    },
];

$legacyFile = $argv[1] ?? null;
$portFile = $argv[2] ?? null;
if (! $legacyFile || ! $portFile || ! is_file($legacyFile) || ! is_file($portFile)) {
    fwrite(STDERR, "usage: php classify.php legacy.text new.text\n");
    exit(2);
}

$legacy = trim((string) file_get_contents($legacyFile));
$port = trim((string) file_get_contents($portFile));

if ($legacy === $port) {
    echo "VERDICT PASS\n";
    exit(0);
}
if ($legacy === 'BINARY' || $port === 'BINARY') {
    echo "VERDICT SKIP\n";
    exit(0);
}

// Word-align: one word per line, then plain `diff`. Hunks come back as
// runs of "< w" (legacy-only) and "> w" (port-only) lines.
$tmpL = tempnam(sys_get_temp_dir(), 'cl-l-');
$tmpP = tempnam(sys_get_temp_dir(), 'cl-p-');
file_put_contents($tmpL, implode("\n", preg_split('/\s+/', $legacy) ?: []));
file_put_contents($tmpP, implode("\n", preg_split('/\s+/', $port) ?: []));
exec(sprintf('diff %s %s', escapeshellarg($tmpL), escapeshellarg($tmpP)), $out, $code);
unlink($tmpL);
unlink($tmpP);
// $code 0 = identical (handled above), 2 = trouble; treat as REAL so it
// stays visible rather than being silently bucketed away.
if ($code !== 1) {
    echo "VERDICT REAL\n";
    exit(0);
}

// Parse diff output into hunks: consecutive '<'/'>' runs between
// separator lines form one hunk.
$hunks = [];
$curL = [];
$curP = [];
$flush = static function () use (&$hunks, &$curL, &$curP): void {
    if ($curL || $curP) {
        $hunks[] = [implode(' ', $curL), implode(' ', $curP)];
        $curL = [];
        $curP = [];
    }
};
foreach ($out as $line) {
    if (str_starts_with($line, '< ')) {
        $curL[] = substr($line, 2);
    } elseif (str_starts_with($line, '> ')) {
        $curP[] = substr($line, 2);
    } elseif (str_starts_with($line, '---')) {
        $flush();
    }
}
$flush();

$real = [];
foreach ($hunks as [$a, $b]) {
    $isNoise = false;
    foreach ($signatures as $sig) {
        if ($sig($a, $b)) {
            $isNoise = true;
            break;
        }
    }
    if (! $isNoise) {
        $real[] = [$a, $b];
    }
}

if ($real === []) {
    echo "VERDICT NOISE\n";
    exit(0);
}

echo "VERDICT REAL\n";
foreach (array_slice($real, 0, 5) as [$a, $b]) {
    echo "- [".mb_substr($a, 0, 90)."]\n";
    echo "+ [".mb_substr($b, 0, 90)."]\n";
}
