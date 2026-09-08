<?php

/**
 * PARITY-026: generate non-en site.php lang packs from legacy lang files.
 *
 * Usage: LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry php tools/parity/generate_lang_packs.php
 *
 * Reads the port's en/site.php keys + legacy lang files for each locale,
 * maps legacy $label_* vars to port array keys by name (strip 'label_'
 * prefix), and writes lang/{locale}/site.php for each non-en locale.
 * Unmapped keys get the English value as fallback (partial coverage).
 */
$legacyDir = getenv('LEGACY_DIR') ?: $_SERVER['HOME'].'/dev/bcoe/brewcompetitiononlineentry';
$portDir = getenv('PORT_DIR') ?: $_SERVER['HOME'].'/dev/bcoe/bcoem-next';

$locales = [
    'cs' => 'cs-CZ',
    'es' => 'es-419',
    'fr' => 'fr-FR',
    'hu' => 'hu-HU',
    'pt' => 'pt-BR',
];

// Load port keys + en values
$portLang = include "$portDir/lang/en/site.php";
$enValues = $portLang; // English fallback

// For each locale, load the legacy lang file and build the port key → translated value map
foreach ($locales as $folder => $legacyCode) {
    $legacyFile = "$legacyDir/lang/$folder/$legacyCode.lang.php";
    if (! file_exists($legacyFile)) {
        echo "SKIP $folder: $legacyFile not found\n";

        continue;
    }

    // Extract $label_* = "..." assignments from the legacy file
    $legacyLabels = [];
    foreach (file($legacyFile) as $line) {
        if (preg_match('/^\s*\\$(label_\w+)\s*=\s*["\'](.+?)["\'];/', $line, $m)) {
            $legacyLabels[$m[1]] = $m[2];
        }
    }

    // Build the translated site.php: for each port key, try to find the
    // matching legacy variable by stripping 'label_' prefix
    $translated = [];
    $mapped = 0;
    foreach ($portLang as $key => $enValue) {
        $legacyVar = 'label_'.$key;
        if (isset($legacyLabels[$legacyVar])) {
            $translated[$key] = $legacyLabels[$legacyVar];
            $mapped++;
        } else {
            $translated[$key] = $enValue; // English fallback
        }
    }

    // Write the lang pack file
    $outFile = "$portDir/lang/$folder/site.php";
    $fp = fopen($outFile, 'w');
    fwrite($fp, "<?php\n\n");
    fwrite($fp, "// PARITY-026: $legacyCode translations for the port's public surface.\n");
    fwrite($fp, "// Auto-generated from legacy $legacyCode.lang.php.\n");
    fwrite($fp, "// Mapped: $mapped/".count($portLang)." keys; rest are English fallbacks.\n");
    fwrite($fp, "// Admin strings stay en-US per legacy convention.\n\n");
    fwrite($fp, "return [\n");
    foreach ($translated as $key => $value) {
        $escaped = addslashes($value);
        fwrite($fp, "    '$key' => '$escaped',\n");
    }
    fwrite($fp, "];\n");
    fclose($fp);

    echo "$folder ($legacyCode): $mapped/".count($portLang)." keys mapped → $outFile\n";
}
