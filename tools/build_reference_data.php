<?php

/**
 * Builds the growth-reference tables from the command line.
 *
 * All of the work is in src/reference_build.inc, which src/install.php drives
 * too. This file is only the shell front end: parse two arguments, decide
 * which references to build, and call the builders in order.
 *
 * The build lives under src/ rather than here because people install this
 * application by uploading src/ over FTP, and code outside src/ is code they
 * do not have. Keeping this script a wrapper is what stops the two paths
 * drifting apart -- a bug fixed for one is fixed for both, which matters more
 * than it sounds: the browser path is the one most installations will use, and
 * this one is the one most likely to be tested.
 *
 * Usage:
 *   php tools/build_reference_data.php
 *   php tools/build_reference_data.php --only=cdc,who
 *   php tools/build_reference_data.php --cache-dir=/somewhere
 *   php tools/build_reference_data.php --list
 */

require_once __DIR__ . '/../src/reference_build.inc';

$opts = getopt('', ['cache-dir::', 'only::', 'list']);

$catalog = growth_reference_catalog();

if (isset($opts['list'])) {
    foreach ($catalog as $id => $ref) {
        printf("%-10s %-38s %-16s %s\n", $id, $ref['label'], $ref['ages'], $ref['licence']);
    }
    exit(0);
}

$cacheDir = $opts['cache-dir'] ?? sys_get_temp_dir() . '/growth-reference-cache';
@mkdir($cacheDir, 0777, true);

$outDir = __DIR__ . '/../src/data';
@mkdir($outDir, 0777, true);

$wanted = array_keys($catalog);
if (isset($opts['only']) && $opts['only'] !== '') {
    $wanted = array_map('trim', explode(',', $opts['only']));
    foreach ($wanted as $id) {
        if (!isset($catalog[$id])) {
            fwrite(STDERR, "unknown reference '$id'. Known: " . implode(', ', array_keys($catalog)) . "\n");
            exit(2);
        }
    }
}

/* Pulls in anything the chosen set depends on -- asking for the breastfed
   curves alone quietly builds CAV first, because that is what they are
   calibrated against. */
$plan = growth_reference_plan($wanted);

$cav = null;
foreach ($plan as $id) {
    switch ($id) {
        case 'cav':
            $cav = growth_build_cav($cacheDir, $outDir);
            break;
        case 'cdc':
            growth_build_cdc($cacheDir, $outDir);
            break;
        case 'who':
            growth_build_who($cacheDir, $outDir);
            break;
        case 'pol':
            growth_build_pol($cacheDir, $outDir);
            break;
        case 'breastfed':
            if ($cav === null) {
                $cav = growth_build_cav($cacheDir, $outDir);
            }
            growth_build_breastfed($cacheDir, $outDir, $cav);
            break;
    }
}

fwrite(STDERR, "done.\n");
