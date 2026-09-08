<?php
/**
 * PARITY-026 helper: extract legacy lang values for each port site.php key.
 * 
 * Usage: php tools/parity/extract_lang_map.php
 * 
 * Reads the port's lang/en/site.php keys and the legacy lang files to
 * build a mapping. For each port key, finds the best-matching legacy
 * variable by name similarity + value match.
 */

$legacyDir = getenv('LEGACY_DIR') ?: $_SERVER['HOME'].'/dev/bcoe/brewcompetitiononlineentry';
$portDir   = getenv('PORT_DIR')   ?: $_SERVER['HOME'].'/dev/bcoe/bcoem-next';

// Load port keys + en values
$portLang = include "$portDir/lang/en/site.php";
$portKeys = array_keys($portLang);

// Load legacy English labels
$legacyLabels = [];
$legacyFile = "$legacyDir/lang/en/en-US.lang.php";
$lines = file($legacyFile);
foreach ($lines as $line) {
    if (preg_match('/^\s*\\$(label_\w+)\s*=\s*["\'](.+?)["\'];/', $line, $m)) {
        $legacyLabels[$m[1]] = $m[2];
    }
}

// Build a simple mapping: port key → legacy variable name
// Heuristic: strip 'label_' prefix and compare
$mapping = [];
foreach ($portKeys as $key) {
    // Try direct match: 'rules' → 'label_rules'
    $candidate = 'label_'.$key;
    if (isset($legacyLabels[$candidate])) {
        $mapping[$key] = $candidate;
        continue;
    }
    // Try underscore variants
    $found = false;
    foreach ($legacyLabels as $varName => $value) {
        $stripped = str_replace('label_', '', $varName);
        if ($stripped === $key) {
            $mapping[$key] = $varName;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $mapping[$key] = null; // unmapped
    }
}

// Output as JSON
echo json_encode([
    'port_keys' => count($portKeys),
    'legacy_labels' => count($legacyLabels),
    'mapped' => count(array_filter($mapping)),
    'unmapped' => count(array_filter($mapping, fn($v) => $v === null)),
    'mapping' => $mapping,
], JSON_PRETTY_PRINT).PHP_EOL;
