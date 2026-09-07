<?php

/**
 * Converts a RustCZ measurement database into the CSV that src/import.php
 * consumes.
 *
 * Usage:
 *   php tools/import_rustcz.php meda.rcz export-child1.txt export-child2.txt > rust.csv
 *
 * The .rcz file is the authoritative source for dates and values; the text
 * exports supply the things it does not carry in an easily readable form -
 * name, date of birth, sex and the parents' heights.
 *
 * The legacy parsing lives here rather than in the web application on purpose:
 * this runs once, and the site should not carry code for a format it will never
 * see again. CSV stays the single documented import path.
 *
 *
 * meda.rcz format, reverse-engineered and verified against the text exports.
 * A flat array of fixed 68-byte records, no header and no footer, so
 * filesize/68 is exactly the record count:
 *
 *   offset 0-15   16 bytes     child GUID
 *   offset 16-23  float64 LE   date as a Delphi TDateTime, days since
 *                              1899-12-30
 *   offset 24-27  float32 LE   height in cm, 0.0 when not measured
 *   offset 28-31  float32 LE   weight in kg, 0.0 when not measured
 *   offset 32-67  zero         the other metrics, unused in this data
 *
 * Note the mixed widths: the date is a double but the two measurements are
 * singles. Reading the measurements as doubles yields plausible-looking numbers
 * rather than an obvious error, which is the trap this format sets.
 *
 * peda.rcz, the companion file holding the child records, is deliberately not
 * parsed: everything needed from it is printed in plain text at the top of the
 * exports, which is a far more robust source than a second binary layout.
 */

require_once __DIR__ . '/../src/rustcz.inc';

$args = array_slice($argv, 1);
$rczPaths = array();
$exports = array();
foreach ($args as $arg) {
    if (substr($arg, -4) === '.rcz') {
        $rczPaths[] = $arg;
    } else {
        $exports[] = $arg;
    }
}

if (!$rczPaths) {
    fwrite(STDERR,
        "usage: php tools/import_rustcz.php <peda.rcz> <meda.rcz> > rust.csv\n"
        . "   or: php tools/import_rustcz.php <meda.rcz> <export.txt> [...] > rust.csv\n"
        . "\n"
        . "The first form is the whole backup and needs nothing else. The second\n"
        . "is for a measurement file that arrived without its companion, and\n"
        . "reconstructs the children from RustCZ's printed exports instead.\n"
        . "\n"
        . "Neither is the ordinary way in any more: src/import_rustcz.php does\n"
        . "this in a browser, which is where the people with these files are.\n");
    exit(2);
}
foreach ($rczPaths as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "no such file: $path\n");
        exit(2);
    }
}

/* ------------------------------------------------------ read the .rcz files */

/* The binary layouts live in src/rustcz.inc, once. They used to live here as
   well, and two copies of a reverse-engineered format is two copies to get
   wrong - the browser importer and this tool would drift apart on exactly the
   kind of detail nobody re-derives twice. */

$bodies = array();
foreach ($rczPaths as $path) {
    $bodies[$path] = (string)file_get_contents($path);
}

$childFile = null;
$measurementFile = null;
foreach ($bodies as $path => $body) {
    $read = growth_rustcz_children($body);
    if ($read['children'] && $childFile === null) {
        $childFile = $path;
        continue;
    }
    $read = growth_rustcz_measurements($body);
    if ($read['groups'] && $measurementFile === null) {
        $measurementFile = $path;
    }
}
if ($measurementFile === null) {
    fwrite(STDERR, sprintf(
        "FATAL: none of the given .rcz files is a RustCZ measurement file.\n"
        . "       One should be %d bytes per measurement; check you have meda.rcz.\n",
        GROWTH_RCZ_MEASUREMENT_SIZE
    ));
    exit(1);
}

$measurements = growth_rustcz_measurements($bodies[$measurementFile]);
$groups = $measurements['groups'];
$recordCount = 0;
foreach ($groups as $rows) {
    $recordCount += count($rows);
}
fwrite(STDERR, sprintf("%s: %d records in %d groups\n",
    basename($measurementFile), $recordCount, count($groups)));

/* -------------------------------------------------- read the text exports */

/** Pulls the child's identity out of an export header. */
function read_export($path)
{
    $text = file_get_contents($path);
    if (@preg_match('~~u', $text) !== 1) {
        $text = iconv('Windows-1250', 'UTF-8//TRANSLIT', $text);
    }
    $child = array('name' => null, 'sex' => null, 'born' => null,
                   'father' => null, 'mother' => null, 'ages' => array());

    if (preg_match('~jm.no:\s*(.+)$~mu', $text, $m)) {
        $child['name'] = trim($m[1]);
    }
    if (preg_match('~Pohlav.*?:\s*(\S+)~u', $text, $m)) {
        $child['sex'] = (stripos($m[1], 'chlapec') !== false) ? 'm' : 'f';
    }
    if (preg_match('~narozen.:\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})~u', $text, $m)) {
        $child['born'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    /* "Otec" then "Nar: 1982 Výška: 177 [cm]", and the same for "Matka" */
    if (preg_match('~Otec\s*\R\s*Nar:.*?V.{1,3}ka:\s*(\d+(?:\.\d+)?)~us', $text, $m)) {
        $child['father'] = (float)$m[1];
    }
    if (preg_match('~Matka\s*\R\s*Nar:.*?V.{1,3}ka:\s*(\d+(?:\.\d+)?)~us', $text, $m)) {
        $child['mother'] = (float)$m[1];
    }
    /* every decimal age printed anywhere in the tables, used to match a GUID */
    if (preg_match_all('~^\s*(\d+\.\d{2})\s~m', $text, $m)) {
        $child['ages'] = array_unique(array_map('floatval', $m[1]));
    }
    return $child;
}

$children = array();


if ($childFile !== null) {
    /* The companion file names every child and carries the GUID of the
       measurements that belong to them, so there is nothing here to pair and
       nothing to guess. Everything below this branch exists only for the case
       where that file is missing. */
    $read = growth_rustcz_children($bodies[$childFile]);
    foreach ($read['errors'] as $message) {
        fwrite(STDERR, "  $message\n");
    }
    foreach ($read['children'] as $index => $child) {
        $children[$index] = array(
            'name' => $child['name'],
            'sex' => $child['sex'],
            'born' => $child['birth_date'],
            'father' => $child['father_cm'],
            'mother' => $child['mother_cm'],
            'ages' => array(),
        );
        $assigned[$index] = $child['guid'];
        fwrite(STDERR, sprintf(
            "%s: %s, %s, born %s, parents %s/%s cm, %d measurements\n",
            basename($childFile), $child['name'], $child['sex'] === 'm' ? 'boy' : 'girl',
            $child['birth_date'], $child['father_cm'] ?: '?', $child['mother_cm'] ?: '?',
            isset($groups[$child['guid']]) ? count($groups[$child['guid']]) : 0
        ));
    }
    if (!$children) {
        fwrite(STDERR, "FATAL: no child could be read from $childFile.\n");
        exit(1);
    }
} else {

if (!$exports) {
    fwrite(STDERR, "FATAL: without the companion .rcz file, RustCZ's printed text\n"
        . "       exports are needed to say who the measurements belong to.\n");
    exit(2);
}
foreach ($exports as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "no such file: $path\n");
        exit(2);
    }
    $child = read_export($path);
    if (!$child['name'] || !$child['sex'] || !$child['born']) {
        fwrite(STDERR, "FATAL: could not read name/sex/date of birth from $path\n");
        exit(1);
    }
    $children[] = $child;
    fwrite(STDERR, sprintf(
        "%s: %s, %s, born %s, parents %s/%s cm, %d distinct ages\n",
        basename($path), $child['name'], $child['sex'] === 'm' ? 'boy' : 'girl',
        $child['born'], $child['father'] ?: '?', $child['mother'] ?: '?',
        count($child['ages'])
    ));
}

/* ------------------------------------------------------ pair GUIDs to kids */

/**
 * Scores how well a GUID's records fit a child, by converting each record date
 * to an age under that child's date of birth and counting how many of those
 * ages appear in the child's own export.
 *
 * Matching on content rather than trusting file order matters because the GUID
 * is opaque - nothing in meda.rcz says which child it belongs to, and getting
 * it backwards would silently plot one child's growth against the other's sex
 * and birth date.
 */
function score_pairing($records, $child)
{
    $born = strtotime($child['born']);
    $ages = array();
    foreach ($child['ages'] as $age) {
        $ages[(string)number_format($age, 2, '.', '')] = true;
    }

    $hits = 0;
    foreach ($records as $record) {
        $days = (strtotime($record['date']) - $born) / 86400.0;
        if ($days < -1) {
            return -1;  /* measured before birth: wrong child, full stop */
        }
        $age = number_format($days / 365.25, 2, '.', '');
        if (isset($ages[$age])) {
            $hits++;
        }
    }
    return $hits;
}


$usedGuids = array();
foreach ($children as $index => $child) {
    $bestGuid = null;
    $bestScore = 0;
    foreach ($groups as $guid => $records) {
        if (isset($usedGuids[$guid])) {
            continue;
        }
        $score = score_pairing($records, $child);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestGuid = $guid;
        }
    }
    if ($bestGuid === null) {
        fwrite(STDERR, "FATAL: no group in the .rcz file matches {$child['name']}.\n");
        exit(1);
    }
    $usedGuids[$bestGuid] = true;
    $assigned[$index] = $bestGuid;
    fwrite(STDERR, sprintf(
        "matched %s -> %s... (%d of %d record dates line up with the export)\n",
        $child['name'], substr($bestGuid, 0, 8), $bestScore, count($groups[$bestGuid])
    ));
}

}   /* end of the text-export fallback */

/* --------------------------------------------------------------- emit CSV */

$out = fopen('php://output', 'w');
fputcsv($out, array('child', 'sex', 'birth_date', 'father_cm', 'mother_cm',
                    'date', 'height_cm', 'weight_kg'));

$written = 0;
$empty = 0;
foreach ($children as $index => $child) {
    $guid = $assigned[$index];
    /* A child in the companion file with no measurements in the other one is
       not an error - a record created and never used - but it is not a row
       either. */
    foreach (isset($groups[$guid]) ? $groups[$guid] : array() as $record) {
        if ($record['height_cm'] === null && $record['weight_kg'] === null) {
            /* RustCZ keeps rows for visits where only a metric we do not track
               was recorded; they would import as blank measurements. */
            $empty++;
            continue;
        }
        fputcsv($out, array(
            $child['name'],
            $child['sex'],
            $child['born'],
            $child['father'],
            $child['mother'],
            $record['date'],
            $record['height_cm'],
            $record['weight_kg'],
        ));
        $written++;
    }
}
fclose($out);

fwrite(STDERR, sprintf("wrote %d measurement rows (%d skipped as empty)\n", $written, $empty));
