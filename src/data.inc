<?php

/**
 * Small view helpers for the growth tracker.
 *
 * Children/measurement storage itself lives behind storage.inc - see that
 * file for the backend contract (JSON files by default, SQLite or MySQL
 * where configured).
 *
 * Every page includes this file before producing any output, directly or via
 * shell.inc, which is what makes it the right place for the checks and
 * headers that belong on every single request: the fail-closed auth guard
 * (auth.inc), starting the session CSRF protection needs (csrf.inc), and a
 * couple of headers cheap enough to just always send.
 */

require_once __DIR__ . '/auth.inc';
require_once __DIR__ . '/csrf.inc';
require_once __DIR__ . '/units.inc';
require_once __DIR__ . '/storage.inc';
require_once __DIR__ . '/rust.inc';

rust_require_auth();

/* Started here, unconditionally, before any page has echoed a single byte -
 * not lazily on first use inside a form far down the page. session_start()
 * only sends its Set-Cookie header if nothing has been sent yet; a page with
 * enough HTML before its first form (dite.php easily clears the 4 KB default
 * output_buffering threshold on PHP's built-in server, and plenty of real
 * php.ini configs set the same default) would otherwise cross that threshold,
 * silently lose the cookie, and hand every visitor a fresh session - and
 * therefore a fresh CSRF token - on every single request. Caught by actually
 * running the app against PHP's built-in server, not by the test suite.
 */
rust_session_start();

/* This application is never meant to be framed by another site, and never
   serves anything a browser should try to sniff into a different content
   type than what Content-Type already says. */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

/* ------------------------------------------------------------- view helpers */

/** Escapes for HTML. Short name because it appears on nearly every output line. */
function rust_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * A number in the active locale's notation, trailing zeros trimmed.
 *
 * Czech uses a comma for the decimal separator; English a point. Neither
 * needs a thousands separator here - nothing this application displays
 * (a height, a weight, a percentile, an SD) ever reaches four digits.
 */
function rust_num($value, $decimals = 1)
{
    if ($value === null || $value === '') {
        return '';
    }
    $point = (rust_locale() === 'cs') ? ',' : '.';
    $text = number_format((float)$value, $decimals, $point, '');
    if (strpos($text, $point) !== false) {
        $text = rtrim(rtrim($text, '0'), $point);
    }
    return $text;
}

/** 2026-01-19 -> "19. 1. 2026" (cs) or "19 Jan 2026" (en). */
function rust_date_cz($date)
{
    $parts = explode('-', (string)$date);
    if (count($parts) !== 3) {
        return (string)$date;
    }
    list($year, $month, $day) = $parts;
    if (rust_locale() === 'cs') {
        return (int)$day . '. ' . (int)$month . '. ' . $year;
    }
    $months = array('', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                     'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
    return (int)$day . ' ' . $months[(int)$month] . ' ' . $year;
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
        return t('median_diff_exact');
    }
    $sign = ($diff > 0) ? '+' : '-';
    return t('median_diff', array(
        'sign' => $sign,
        'value' => rust_num(abs($diff), $decimals),
        'unit' => $unit,
    ));
}

/**
 * A number of years after the preposition "za" ("over"/"in"), which in
 * Czech takes the accusative: "za 1 rok", "za 2 roky", "za 5 let". A
 * fraction takes the genitive singular, "za 0,97 roku", which is why this
 * cannot simply reuse rust_years_cz(). English needs none of that - a
 * fractional year is just "0.97 years", the same plural as any other count
 * that isn't exactly one.
 */
function rust_years_span_cz($years)
{
    $rounded = round((float)$years, 2);
    $text = rust_num($rounded, 2);

    if (rust_locale() !== 'cs') {
        return $text . ' ' . t(abs($rounded - 1.0) < 0.001 ? 'unit_year' : 'unit_years');
    }

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
 * A number of years with the right word after it, in the case Czech's
 * surrounding phrases need: "ve věku 1 roku", "ve věku 10 let". Czech
 * inflects on the number, so "1 let" reads as plainly wrong to a native
 * speaker in the way "1 years" would in English.
 */
function rust_years_cz($years)
{
    $rounded = round((float)$years, 1);
    $text = rust_num($rounded);

    if (rust_locale() !== 'cs') {
        return $text . ' ' . t(abs($rounded - 1.0) < 0.001 ? 'unit_year' : 'unit_years');
    }
    if (abs($rounded - 1.0) < 0.001) {
        return $text . ' roku';
    }
    return $text . ' let';
}

/** "8 let 4 měsíce" / "8 years 4 months", for reading an age at a glance. */
function rust_age_cz($years)
{
    if ($years === null) {
        return '';
    }
    $wholeYears = (int)floor($years);
    $months = (int)floor(($years - $wholeYears) * 12);

    if (rust_locale() !== 'cs') {
        $yearWord = t($wholeYears === 1 ? 'unit_year' : 'unit_years');
        $monthWord = t($months === 1 ? 'unit_month' : 'unit_months');
        if ($wholeYears === 0) {
            return $months . ' ' . $monthWord;
        }
        if ($months === 0) {
            return $wholeYears . ' ' . $yearWord;
        }
        return $wholeYears . ' ' . $yearWord . ' ' . $months . ' ' . $monthWord;
    }

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
        return '<span class="rust-odhad" title="' . th('percentile_extrapolated_title')
            . '">~' . $text . '</span>';
    }
    return $text;
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
 * True only for a real calendar date in RRRR-MM-DD form.
 *
 * The regex this replaced (^\d{4}-\d{2}-\d{2}$) matches the shape of a date
 * without checking it is one - "2026-13-45" and "0000-00-00" both pass it,
 * and would then reach strtotime(), which is lenient enough to silently roll
 * an invalid date into a real but wrong one rather than reject it.
 * checkdate() actually validates the calendar.
 */
function rust_valid_date($value)
{
    if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', (string)$value, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
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
