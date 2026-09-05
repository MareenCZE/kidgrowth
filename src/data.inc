<?php

/**
 * Data access and small view helpers for the growth tracker.
 *
 * Uses db_connect.php's db() connection and mysqli prepared statements - no
 * PDO, no ORM, no framework.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/rust.inc';

/* ------------------------------------------------------------- view helpers */

/** Escapes for HTML. Short name because it appears on nearly every output line. */
function rust_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** A number in Czech notation, trailing zeros trimmed. */
function rust_num($value, $decimals = 1)
{
    if ($value === null || $value === '') {
        return '';
    }
    $text = number_format((float)$value, $decimals, ',', ' ');
    if (strpos($text, ',') !== false) {
        $text = rtrim(rtrim($text, '0'), ',');
    }
    return $text;
}

/** 2026-01-19 -> 19. 1. 2026 */
function rust_date_cz($date)
{
    $parts = explode('-', (string)$date);
    if (count($parts) !== 3) {
        return (string)$date;
    }
    return (int)$parts[2] . '. ' . (int)$parts[1] . '. ' . $parts[0];
}

/**
 * The gap between a measurement and the median for that age, as "-7,3 cm
 * oproti mediánu".
 *
 * A percentile says where a child sits among their peers; this says by how
 * much, in the unit the measurement was taken in. It is the more intuitive
 * half of the pair, and for weight-for-height it is close to the number a
 * paediatrician works with directly - the median there is the weight expected
 * at that height, so the difference is how far off that expectation the child
 * is.
 *
 * The sign is always shown, since "3 cm from the median" without it would be
 * ambiguous in exactly the case that matters.
 */
function rust_median_diff_cz($value, $median, $decimals, $unit)
{
    if ($value === null || $median === null) {
        return '';
    }
    $diff = (float)$value - (float)$median;
    /* Below the precision shown, "+0,0" would be noise dressed as information. */
    $step = pow(10, -$decimals) / 2.0;
    if (abs($diff) < $step) {
        return 'přesně medián';
    }
    $sign = ($diff > 0) ? '+' : '-';
    return $sign . rust_num(abs($diff), $decimals) . ' ' . $unit . ' oproti mediánu';
}

/**
 * A number of years after the preposition "za", which takes the accusative:
 * "za 1 rok", "za 2 roky", "za 5 let". A fraction takes the genitive singular,
 * "za 0,97 roku", which is why this cannot simply reuse rust_years_cz().
 */
function rust_years_span_cz($years)
{
    $rounded = round((float)$years, 2);
    $text = rust_num($rounded, 2);

    if (abs($rounded - round($rounded)) > 0.001) {
        return $text . ' roku';          /* 0,97 roku */
    }
    $whole = (int)round($rounded);
    if ($whole === 1) {
        return $text . ' rok';           /* 1 rok */
    }
    if ($whole >= 2 && $whole <= 4) {
        return $text . ' roky';          /* 2 roky */
    }
    return $text . ' let';               /* 5 let */
}

/**
 * A number of years with the right Czech word after it, in the genitive the
 * surrounding phrases need: "ve věku 1 roku", "ve věku 10 let".
 *
 * Czech inflects on the number, so "1 let" reads as plainly wrong to a native
 * speaker in the way "1 years" would in English.
 */
function rust_years_cz($years)
{
    $rounded = round((float)$years, 1);
    $text = rust_num($rounded);
    if (abs($rounded - 1.0) < 0.001) {
        return $text . ' roku';
    }
    return $text . ' let';
}

/** "8 let 4 měsíce", for reading an age at a glance. */
function rust_age_cz($years)
{
    if ($years === null) {
        return '';
    }
    $wholeYears = (int)floor($years);
    $months = (int)floor(($years - $wholeYears) * 12);

    $yearWord = ($wholeYears === 1) ? 'rok' : (($wholeYears < 5) ? 'roky' : 'let');
    $monthWord = ($months === 1) ? 'měsíc' : (($months < 5) ? 'měsíce' : 'měsíců');

    if ($wholeYears === 0) {
        return $months . ' ' . $monthWord;
    }
    if ($months === 0) {
        return $wholeYears . ' ' . $yearWord;
    }
    return $wholeYears . ' ' . $yearWord . ' ' . $months . ' ' . $monthWord;
}

/**
 * Formats a percentile for display, with precision that grows in the tails.
 *
 * A percentile compresses badly at the extremes - the difference between the
 * 1st and the 0.1st is more than a whole standard deviation of growth - so a
 * single decimal is not enough out there. The SDS column beside it stays the
 * more useful number for a child far from the median.
 *
 * On whether an extrapolated percentile may be shown at all: only the Czech
 * tables are affected, since WHO and CDC publish LMS directly while ours is
 * fitted to seven published percentiles spanning P3-P97. Measured against
 * RustCZ's own output for the two children's real history, that extrapolation
 * turns out to be trustworthy for height and not for weight:
 *
 *     height, beyond P3/P97 : worst |dz| 0.011 over 14 points
 *     weight, beyond P3/P97 : worst |dz| 0.331 over 25 points
 *
 * The reason is distribution shape. Height is very nearly normal (L is about
 * 1), so the fitted curve keeps its shape indefinitely into the tail. Weight
 * is strongly right-skewed, its L is far from 1, and nothing in the published
 * percentiles pins down how the tail behaves past P97.
 *
 * So a height percentile is printed exactly however far out it lands, and only
 * an extrapolated weight is marked approximate.
 */
function rust_percentile_cz($percentile, $z, $referenceId, $metric = 'height')
{
    if ($percentile === null) {
        return '–';
    }

    $decimals = 1;
    if ($percentile < 1.0 || $percentile > 99.0) {
        $decimals = 2;
    }
    if ($percentile < 0.1 || $percentile > 99.9) {
        $decimals = 3;
    }
    $text = rust_num($percentile, $decimals) . '.';

    $extrapolated = ($referenceId === 'cav')
        && ($metric === 'weight')
        && ($z !== null)
        && (abs($z) > 1.8807936);

    if ($extrapolated) {
        return '<span class="rust-odhad" title="Mimo rozsah publikovaný SZÚ '
            . '(3.–97. percentil), hodnota je extrapolovaná">~' . $text . '</span>';
    }
    return $text;
}

/* --------------------------------------------------------------- children */

function rust_children()
{
    $link = db();
    $result = mysqli_query($link,
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, kojeno
         FROM rust_deti ORDER BY poradi, datum_narozeni');
    $children = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $children[] = $row;
    }
    return $children;
}

function rust_child($id)
{
    $link = db();
    $stmt = mysqli_prepare($link,
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, kojeno
         FROM rust_deti WHERE id = ?');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** Creates the child if the name is new; returns the id either way. */
function rust_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed = 0)
{
    $link = db();
    $stmt = mysqli_prepare($link, 'SELECT id FROM rust_deti WHERE jmeno = ?');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = mysqli_prepare($link,
        'INSERT INTO rust_deti (jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, kojeno)
         VALUES (?, ?, ?, ?, ?, ?)');
    $breastfedFlag = $breastfed ? 1 : 0;
    mysqli_stmt_bind_param($stmt, 'sssddi', $name, $sex, $born, $fatherCm, $motherCm, $breastfedFlag);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($link);
    mysqli_stmt_close($stmt);
    return (int)$id;
}

function rust_child_update($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed = 0)
{
    $link = db();
    $stmt = mysqli_prepare($link,
        'UPDATE rust_deti SET jmeno = ?, pohlavi = ?, datum_narozeni = ?,
         vyska_otce_cm = ?, vyska_matky_cm = ?, kojeno = ? WHERE id = ?');
    $breastfed = $breastfed ? 1 : 0;
    mysqli_stmt_bind_param($stmt, 'sssddii', $name, $sex, $born, $fatherCm, $motherCm, $breastfed, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/* ----------------------------------------------------------- measurements */

function rust_measurements($childId)
{
    $link = db();
    $stmt = mysqli_prepare($link,
        'SELECT id, datum, vyska_cm, hmotnost_kg, poznamka
         FROM rust_mereni WHERE dite_id = ? ORDER BY datum');
    mysqli_stmt_bind_param($stmt, 'i', $childId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        /* Keep NULL as NULL rather than letting it become 0.0 through a cast.
           A missing height is not a height of zero, and every chart, velocity
           and projection downstream depends on being able to tell them apart. */
        $row['vyska_cm'] = ($row['vyska_cm'] === null) ? null : (float)$row['vyska_cm'];
        $row['hmotnost_kg'] = ($row['hmotnost_kg'] === null) ? null : (float)$row['hmotnost_kg'];
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Inserts or updates the measurement for one child on one date.
 *
 * Keyed on (dite_id, datum) so that saving the same day twice corrects the
 * earlier entry instead of creating a duplicate - which also makes re-running
 * the importer safe.
 */
function rust_measurement_save($childId, $date, $heightCm, $weightKg, $note)
{
    $link = db();
    $stmt = mysqli_prepare($link,
        'INSERT INTO rust_mereni (dite_id, datum, vyska_cm, hmotnost_kg, poznamka)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE vyska_cm = VALUES(vyska_cm),
                                 hmotnost_kg = VALUES(hmotnost_kg),
                                 poznamka = VALUES(poznamka)');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isdds', $childId, $date, $heightCm, $weightKg, $note);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function rust_measurement_delete($childId, $id)
{
    $link = db();
    $stmt = mysqli_prepare($link, 'DELETE FROM rust_mereni WHERE id = ? AND dite_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $childId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/* ---------------------------------------------------------------- request */

/**
 * The reference chosen in the picker, defaulting to the Czech one where it is
 * installed and falling back to CDC - the one reference the repository always
 * ships - where it is not (see rust_installed_reference_ids()).
 */
function rust_selected_reference()
{
    $installed = rust_installed_reference_ids();
    $default = in_array('cav', $installed, true) ? 'cav' : (reset($installed) ?: 'cdc');
    $id = isset($_GET['ref']) ? (string)$_GET['ref'] : $default;
    return in_array($id, $installed, true) ? $id : $default;
}

/**
 * Turns a form field into a number or null.
 *
 * An empty field means "not measured" and must become NULL, never 0 - see the
 * note in rust_measurements(). Czech decimal commas are accepted because a
 * phone keyboard offers whichever separator it likes.
 */
function rust_input_number($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(array(' ', ','), array('', '.'), $value);
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return ($number > 0) ? $number : null;
}

/** Guards against an open redirect on the return-to parameter. */
function rust_safe_return($value, $fallback)
{
    $value = (string)$value;
    if ($value === '' || $value[0] !== '/' || strpos($value, '//') === 0) {
        return $fallback;
    }
    return $value;
}
