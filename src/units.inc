<?php

/**
 * Imperial/metric unit preference and conversion.
 *
 * Storage and every internal computation (z-scores, percentiles, smoothing,
 * the LMS maths) stay metric always - the reference tables are metric, and
 * converting them would introduce rounding into medical data for no
 * benefit. Conversion happens only at the edges: parsing a form field
 * into metric before it is stored, and converting a metric value into the
 * display unit only at the moment it is turned into text.
 *
 * Unit choice is a per-visitor preference, independent of language - plenty
 * of people in the US want metric and vice versa - so it gets its own
 * cookie, set the same way growth_locale() sets growth_lang (see i18n.inc).
 */

const GROWTH_CM_PER_INCH = 2.54;
const GROWTH_KG_PER_LB = 0.45359237;

function growth_units()
{
    static $units = null;
    if ($units !== null) {
        return $units;
    }
    if (isset($_GET['units']) && in_array($_GET['units'], array('metric', 'imperial'), true)) {
        $units = $_GET['units'];
        growth_set_preference_cookie('growth_units', $units);
        return $units;
    }
    if (isset($_COOKIE['growth_units']) && in_array($_COOKIE['growth_units'], array('metric', 'imperial'), true)) {
        $units = $_COOKIE['growth_units'];
        return $units;
    }
    $units = 'metric';
    return $units;
}

/* --------------------------------------------------------------- display */

/** A stored length in cm to the active display unit (cm or in), unrounded. */
function growth_display_length($cm)
{
    if ($cm === null) {
        return null;
    }
    return (growth_units() === 'imperial') ? ((float)$cm / GROWTH_CM_PER_INCH) : (float)$cm;
}

/** A stored weight in kg to the active display unit (kg or lb), unrounded. */
function growth_display_weight($kg)
{
    if ($kg === null) {
        return null;
    }
    return (growth_units() === 'imperial') ? ((float)$kg / GROWTH_KG_PER_LB) : (float)$kg;
}

function growth_length_unit()
{
    return (growth_units() === 'imperial') ? 'in' : 'cm';
}

function growth_weight_unit()
{
    return (growth_units() === 'imperial') ? 'lb' : 'kg';
}

/** cm/year under metric, in/year under imperial. */
function growth_velocity_unit()
{
    return growth_length_unit() . '/' . t('unit_year_short');
}

/** A stored velocity in cm/year to the active display unit, unrounded. */
function growth_display_velocity($cmPerYear)
{
    return growth_display_length($cmPerYear);
}

/** How many decimals a displayed weight gets - kg needs two for an infant's
 *  weight to look right, but the equivalent precision in pounds is one. */
function growth_weight_decimals()
{
    return (growth_units() === 'imperial') ? 1 : 2;
}

/**
 * Converts a reference table's LMS rows to the active display unit, for
 * charting. Only the index (column 0) and M (column 2) can change; L and S
 * never do. The Cole LMS transform (X/M)^L is invariant under scaling both X
 * and M by the same constant, so converting M by a fixed factor leaves L and
 * S exactly as published - this is a change of units, not a re-fit, and
 * introduces no error.
 *
 * $indexKind/$valueKind are 'length', 'weight', or null (no conversion - age
 * as an index, or bmi as a value, since kg/m² is used in the US too).
 * Every metric except weight-for-height indexes by age, so
 * $indexKind is null for those; weight-for-height's own index is a height,
 * which is why this takes the index and the value separately rather than
 * assuming one unit for the whole row.
 */
function growth_display_chart_rows($rows, $indexKind, $valueKind)
{
    if (growth_units() !== 'imperial' || !$rows) {
        return $rows;
    }
    $out = array();
    foreach ($rows as $row) {
        $out[] = array(
            ($indexKind === 'length') ? growth_display_length($row[0]) : $row[0],
            $row[1],
            growth_display_chart_scalar($row[2], $valueKind),
            $row[3],
        );
    }
    return $out;
}

/** Converts one child measurement point the same way growth_display_chart_rows()
 *  converts a reference row, so the two stay aligned on the same chart. */
function growth_display_chart_points($points, $indexKind, $valueKind)
{
    if (growth_units() !== 'imperial' || !$points) {
        return $points;
    }
    $out = array();
    foreach ($points as $point) {
        if ($indexKind === 'length') {
            $point['age'] = growth_display_length($point['age']);
        }
        $point['value'] = growth_display_chart_scalar($point['value'], $valueKind);
        if (isset($point['suspect'])) {
            $point['suspect'] = growth_display_chart_scalar($point['suspect'], $valueKind);
        }
        $out[] = $point;
    }
    return $out;
}

function growth_display_chart_scalar($value, $kind)
{
    if ($kind === 'length') {
        return growth_display_length($value);
    }
    if ($kind === 'weight') {
        return growth_display_weight($value);
    }
    return $value;
}

/**
 * Per-growth_metrics()-id conversion, for child.php and chart.inc: which physical
 * quantity a metric's value (or, for weight-for-height, its index) is, so
 * the right conversion applies without every call site special-casing 'wfh'
 * itself. bmi is never converted - see growth_display_rows()'s docblock.
 */
function growth_metric_value_kind($metricId)
{
    if ($metricId === 'height') {
        return 'length';
    }
    if ($metricId === 'weight' || $metricId === 'wfh') {
        return 'weight';
    }
    return null;
}

/** weight-for-height's index is a height; every other metric indexes by age,
 *  which is not a unit-converted quantity. */
function growth_metric_index_kind($metricId)
{
    return ($metricId === 'wfh') ? 'length' : null;
}

function growth_display_metric_value($metricId, $value)
{
    $kind = growth_metric_value_kind($metricId);
    if ($kind === 'length') {
        return growth_display_length($value);
    }
    if ($kind === 'weight') {
        return growth_display_weight($value);
    }
    return $value;
}

/** null means "use $meta['unit'] unchanged" - true only for bmi. */
function growth_display_metric_unit($metricId)
{
    $kind = growth_metric_value_kind($metricId);
    if ($kind === 'length') {
        return growth_length_unit();
    }
    if ($kind === 'weight') {
        return growth_weight_unit();
    }
    return null;
}

function growth_display_metric_index_value($metricId, $value)
{
    return (growth_metric_index_kind($metricId) === 'length') ? growth_display_length($value) : $value;
}

/** null means the index is age, printed in years regardless of unit system. */
function growth_display_metric_index_unit($metricId)
{
    return (growth_metric_index_kind($metricId) === 'length') ? growth_length_unit() : null;
}

/* ----------------------------------------------------------------- input */

/**
 * Parses a height field into centimetres, honouring the active unit.
 *
 * Under metric this is exactly growth_input_number(). Under imperial it also
 * accepts feet-and-inches: 4'3", 4' 3", 4'3, 4ft3in, 4 ft 3 in, or feet
 * alone (4' / 4 ft). A bare number with no feet marker is read as inches -
 * the input parsing this project's plan called the fiddly part, budgeted
 * more time than the display side, because none of these forms are
 * optional: a US parent will type whichever one is natural to them.
 */
function growth_parse_length_input($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (growth_units() !== 'imperial') {
        return growth_input_number($value);
    }

    if (preg_match('~^(\d+)\s*(?:\'|ft)\s*(?:(\d+(?:[.,]\d+)?)\s*(?:"|in)?)?$~i', $value, $m)) {
        $feet = (float)$m[1];
        $inches = isset($m[2]) && $m[2] !== '' ? (float)str_replace(',', '.', $m[2]) : 0.0;
        $totalInches = $feet * 12.0 + $inches;
    } else {
        $totalInches = growth_input_number(str_ireplace('in', '', $value));
        if ($totalInches === null) {
            return null;
        }
    }
    if ($totalInches <= 0) {
        return null;
    }
    return $totalInches * GROWTH_CM_PER_INCH;
}

/**
 * Parses a weight field into kilograms, honouring the active unit. Under
 * imperial a bare number is read as pounds - there is no feet-and-inches
 * equivalent complication here, just a different scale.
 */
function growth_parse_weight_input($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (growth_units() !== 'imperial') {
        return growth_input_number($value);
    }
    $pounds = growth_input_number(str_ireplace('lb', '', $value));
    if ($pounds === null) {
        return null;
    }
    return $pounds * GROWTH_KG_PER_LB;
}
