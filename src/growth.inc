<?php

/**
 * Growth-tracker maths: decimal age, LMS lookup, z-scores, percentiles and the
 * adult-height projections.
 *
 * Every reference (Czech CAV, WHO, CDC) is stored as LMS parameters, so there
 * is exactly one code path here regardless of which curves the user picked.
 * The Czech tables are published as seven percentiles rather than LMS and are
 * converted at build time - see tools/build_reference_data.php.
 */

require_once __DIR__ . '/i18n.inc';
require_once __DIR__ . '/units.inc';

/* ---------------------------------------------------------------- metrics */

/**
 * The four things this application charts.
 *
 * Three are indexed by age; weight-for-height is indexed by height instead,
 * which is the whole point of it. SZU's own guidance prefers judging weight
 * against height rather than against age, because a child who is simply small
 * for their age is not thereby underweight - weight-for-age cannot tell those
 * apart and weight-for-height can.
 */
function growth_metrics()
{
    return array(
        /* 'noise' is the smallest disagreement worth treating as real, in the
           metric's own unit. Repeat measurement of a child's height differs by
           a few millimetres between observers and by up to a centimetre over a
           single day, because the spine compresses; weight moves with clothing
           and with which scale was used. Without this floor, a child measured
           only a handful of times gets a smoothed line that passes almost
           exactly through every point, and the residual scatter collapses to
           near zero - at which point anything at all looks like an outlier. */
        'height' => array(
            'label' => t('metric_height'), 'unit' => 'cm', 'decimals' => 1,
            'index' => 'age', 'index_label' => t('index_age'), 'noise' => 0.5,
        ),
        'weight' => array(
            'label' => t('metric_weight'), 'unit' => 'kg', 'decimals' => 2,
            'index' => 'age', 'index_label' => t('index_age'), 'noise' => 0.3,
        ),
        'bmi' => array(
            'label' => t('metric_bmi'), 'unit' => 'kg/m²', 'decimals' => 1,
            'index' => 'age', 'index_label' => t('index_age'), 'noise' => 0.4,
        ),
        'wfh' => array(
            'label' => t('metric_wfh'), 'unit' => 'kg', 'decimals' => 2,
            'index' => 'height', 'index_label' => t('index_height', array('unit' => growth_length_unit())), 'noise' => 0.3,
        ),
    );
}

function growth_metric($id)
{
    $metrics = growth_metrics();
    return isset($metrics[$id]) ? $metrics[$id] : null;
}

/** BMI in kg/m² from a height in centimetres and a weight in kilograms. */
function growth_bmi($heightCm, $weightKg)
{
    if (!$heightCm || !$weightKg || $heightCm <= 0) {
        return null;
    }
    $metres = $heightCm / 100.0;
    return $weightKg / ($metres * $metres);
}

/* ------------------------------------------------------------- references */

/**
 * The references offered in the picker, in the order they should appear.
 *
 * Czech first because it is the one that actually applies here, then the Czech
 * breastfed-infant curves, then Poland as the nearest comparable population,
 * then the two international sets.
 *
 * The breastfed reference only covers the first year, so it is offered only for
 * a child marked as breastfed - see growth_reference_ids_for().
 */
function growth_reference_ids()
{
    return array('cav', 'koj', 'pol', 'who', 'cdc');
}

/**
 * Which references actually have a data file on this installation.
 *
 * Only `data/cdc.php` ships in the repository - it is public domain. Every
 * other reference is fetched and generated locally by
 * `tools/build_reference_data.php` (see DATA-LICENCES.md for why), so a fresh
 * clone that has not run the build step has only CDC. Everything that decides
 * what to default to or offer in the picker goes through this rather than the
 * full catalogue in growth_reference_ids(), so the app degrades to "just CDC"
 * instead of pointing at references that are not there.
 */
function growth_installed_reference_ids()
{
    return array_values(array_filter(growth_reference_ids(), function ($id) {
        return growth_reference($id) !== null;
    }));
}

/**
 * The references worth offering for one particular child.
 *
 * Hides the breastfed curves unless the child is marked as breastfed, since for
 * everyone else they are an option that can only ever show an empty chart.
 */
function growth_reference_ids_for($child)
{
    $ids = growth_installed_reference_ids();
    $breastfed = !empty($child['breastfed']);
    if ($breastfed) {
        return $ids;
    }
    return array_values(array_filter($ids, function ($id) {
        return $id !== 'koj';
    }));
}

/**
 * Loads one reference file. Cached per request; these files are read-only.
 *
 * The generated file (tools/build_reference_data.php) carries only 'id' and
 * 'metrics' - the LMS rows, which do not vary by locale. 'label', 'note' and
 * 'source' are display text, so they are not baked into the generated file
 * at all: they are looked up from the language files here, on every load,
 * which is what lets the same generated data file serve every locale.
 */
function growth_reference($id)
{
    static $cache = array();
    if (!in_array($id, growth_reference_ids(), true)) {
        return null;
    }
    if (!isset($cache[$id])) {
        $path = __DIR__ . '/data/' . $id . '.php';
        if (!is_file($path)) {
            return null;
        }
        $data = require $path;
        $data['label'] = t('ref_' . $id . '_label');
        $data['note'] = t('ref_' . $id . '_note');
        $data['source'] = t('ref_' . $id . '_source');
        $cache[$id] = $data;
    }
    return $cache[$id];
}

/**
 * Returns the LMS rows for one reference/metric/sex as an array of
 * array(age, L, M, S), ascending by age, or an empty array when that
 * combination is not published.
 *
 * The data files hold each table as one space-separated string of "age,L,M,S"
 * rows; parsing that is far cheaper than having PHP build a few thousand
 * nested arrays on every request. The parsed form is memoised because a single
 * page draws several curves from the same table.
 */
function growth_reference_rows($id, $metric, $sex)
{
    static $cache = array();
    $key = $id . '/' . $metric . '/' . $sex;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $reference = growth_reference($id);
    if (!$reference || !isset($reference['metrics'][$metric][$sex])) {
        return $cache[$key] = array();
    }

    $rows = array();
    foreach (explode(' ', $reference['metrics'][$metric][$sex]) as $chunk) {
        if ($chunk === '') {
            continue;
        }
        $parts = explode(',', $chunk);
        if (count($parts) !== 4) {
            continue;
        }
        $rows[] = array((float)$parts[0], (float)$parts[1], (float)$parts[2], (float)$parts[3]);
    }
    return $cache[$key] = $rows;
}

/** The age span a reference covers for a metric, as array(min, max) or null. */
function growth_reference_span($id, $metric, $sex)
{
    $rows = growth_reference_rows($id, $metric, $sex);
    if (!$rows) {
        return null;
    }
    return array($rows[0][0], $rows[count($rows) - 1][0]);
}

/* -------------------------------------------------------------------- age */

/**
 * Decimal age in years between two Y-m-d dates.
 *
 * Uses the true elapsed days over the mean Julian year rather than counting
 * calendar birthdays, which is what the reference tables themselves are built
 * on (SZU documents the same decimal-age convention, after Weiner and Lourie).
 */
function growth_decimal_age($birthDate, $onDate)
{
    $born = date_create_immutable($birthDate);
    $when = date_create_immutable($onDate);
    if (!$born || !$when) {
        return null;
    }
    $days = ($when->getTimestamp() - $born->getTimestamp()) / 86400.0;
    return $days / 365.25;
}

/* -------------------------------------------------------------------- LMS */

/**
 * Interpolates L, M and S at an exact age.
 *
 * Uses monotone cubic (Fritsch-Carlson) interpolation rather than straight
 * lines. This is not gold-plating: the Czech table steps in 2.4-month jumps
 * through the first year, exactly where growth is fastest and the curve bends
 * hardest, and a chord across such a segment sits noticeably below a concave
 * curve. Underestimating M that way inflates every z-score computed in infancy,
 * which is when measurements are taken most often. Validating against RustCZ's
 * own output over a decade of real measurements, this change cut the worst
 * disagreement from 0.25 SDS to well inside the noise.
 *
 * The Fritsch-Carlson slope limiter is what makes it safe: it cannot overshoot
 * or introduce wiggles between points, so an interpolated median stays between
 * its neighbours the way a growth curve must.
 *
 * Returns null outside the published range. Growth references must never be
 * extrapolated, and a caller receiving null is expected to draw nothing rather
 * than invent a curve.
 */
function growth_lms_at($rows, $age)
{
    $count = count($rows);
    if ($count === 0 || $age < $rows[0][0] || $age > $rows[$count - 1][0]) {
        return null;
    }
    if ($count === 1) {
        return array('l' => $rows[0][1], 'm' => $rows[0][2], 's' => $rows[0][3]);
    }

    /* binary search for the last row at or before $age */
    $lo = 0;
    $hi = $count - 1;
    while ($lo < $hi) {
        $mid = (int)(($lo + $hi + 1) / 2);
        if ($rows[$mid][0] <= $age) {
            $lo = $mid;
        } else {
            $hi = $mid - 1;
        }
    }

    if ($rows[$lo][0] == $age) {
        return array('l' => $rows[$lo][1], 'm' => $rows[$lo][2], 's' => $rows[$lo][3]);
    }
    if ($lo >= $count - 1) {
        $last = $rows[$count - 1];
        return array('l' => $last[1], 'm' => $last[2], 's' => $last[3]);
    }

    return array(
        'l' => growth_pchip($rows, $lo, $age, 1),
        'm' => growth_pchip($rows, $lo, $age, 2),
        's' => growth_pchip($rows, $lo, $age, 3),
    );
}

/**
 * One monotone cubic Hermite step on column $col of $rows, over the interval
 * starting at index $i.
 */
function growth_pchip($rows, $i, $x, $col)
{
    $n = count($rows);
    $x0 = $rows[$i][0];
    $x1 = $rows[$i + 1][0];
    $y0 = $rows[$i][$col];
    $y1 = $rows[$i + 1][$col];

    $h = $x1 - $x0;
    if ($h <= 0) {
        return $y0;
    }
    $delta = ($y1 - $y0) / $h;

    $slope0 = growth_pchip_slope($rows, $i, $col, $delta, $h);
    $slope1 = growth_pchip_slope($rows, $i + 1, $col, $delta, $h);

    $t = ($x - $x0) / $h;
    $t2 = $t * $t;
    $t3 = $t2 * $t;

    return (2 * $t3 - 3 * $t2 + 1) * $y0
         + ($t3 - 2 * $t2 + $t) * $h * $slope0
         + (-2 * $t3 + 3 * $t2) * $y1
         + ($t3 - $t2) * $h * $slope1;
}

/**
 * The Fritsch-Carlson tangent at node $k: a weighted harmonic mean of the
 * neighbouring secant slopes, forced to zero at a local extremum so the curve
 * cannot overshoot.
 */
function growth_pchip_slope($rows, $k, $col, $fallback, $fallbackH)
{
    $n = count($rows);

    $hPrev = ($k > 0) ? ($rows[$k][0] - $rows[$k - 1][0]) : 0.0;
    $hNext = ($k < $n - 1) ? ($rows[$k + 1][0] - $rows[$k][0]) : 0.0;
    $dPrev = ($hPrev > 0) ? ($rows[$k][$col] - $rows[$k - 1][$col]) / $hPrev : null;
    $dNext = ($hNext > 0) ? ($rows[$k + 1][$col] - $rows[$k][$col]) / $hNext : null;

    /* At the ends there is only one secant to go on. */
    if ($dPrev === null && $dNext === null) {
        return $fallback;
    }
    if ($dPrev === null) {
        return $dNext;
    }
    if ($dNext === null) {
        return $dPrev;
    }
    /* A sign change means a local extremum; a zero tangent keeps it monotone. */
    if ($dPrev * $dNext <= 0) {
        return 0.0;
    }
    $w1 = 2 * $hNext + $hPrev;
    $w2 = $hNext + 2 * $hPrev;
    return ($w1 + $w2) / ($w1 / $dPrev + $w2 / $dNext);
}

/** The z-score (SDS) of a measurement under an LMS triplet. */
function growth_zscore($value, $lms)
{
    if (!$lms || $value <= 0 || $lms['m'] <= 0 || $lms['s'] <= 0) {
        return null;
    }
    if (abs($lms['l']) < 1e-9) {
        return log($value / $lms['m']) / $lms['s'];
    }
    return (pow($value / $lms['m'], $lms['l']) - 1.0) / ($lms['l'] * $lms['s']);
}

/** The measurement at a given z under an LMS triplet. Inverse of growth_zscore. */
function growth_value_at_z($lms, $z)
{
    if (!$lms) {
        return null;
    }
    if (abs($lms['l']) < 1e-9) {
        return $lms['m'] * exp($lms['s'] * $z);
    }
    $base = 1.0 + $lms['l'] * $lms['s'] * $z;
    if ($base <= 0) {
        return null;
    }
    return $lms['m'] * pow($base, 1.0 / $lms['l']);
}

/* ------------------------------------------------------ normal distribution */

/**
 * Standard normal CDF, as a percentage.
 *
 * Built on erf via a series/continued-fraction incomplete gamma, because PHP
 * has no erf() outside ext/stats and this needs to work on stock hosting.
 * Accuracy is far better than the single decimal place we ever display.
 */
function growth_percentile($z)
{
    if ($z === null) {
        return null;
    }
    return 100.0 * 0.5 * (1.0 + growth_erf($z / M_SQRT2));
}

function growth_erf($x)
{
    $sign = $x < 0 ? -1.0 : 1.0;
    $x = abs($x);
    if ($x < 1e-12) {
        return 0.0;
    }
    /* Abramowitz & Stegun 7.1.26 - max absolute error 1.5e-7, which is orders
       of magnitude below anything we round to for display. */
    $t = 1.0 / (1.0 + 0.3275911 * $x);
    $y = 1.0 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t
        - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);
    return $sign * $y;
}

/** Inverse standard normal CDF, for turning a percentile back into a z. */
function growth_z_from_percentile($percent)
{
    $p = $percent / 100.0;
    if ($p <= 0.0 || $p >= 1.0) {
        return null;
    }
    /* Acklam's rational approximation, refined once by Halley's method. */
    $a = array(-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
               1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00);
    $b = array(-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
               6.680131188771972e+01, -1.328068155288572e+01);
    $c = array(-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
               -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00);
    $d = array(7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
               3.754408661907416e+00);
    $pLow = 0.02425;

    if ($p < $pLow) {
        $q = sqrt(-2 * log($p));
        $x = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
           / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
    } elseif ($p <= 1 - $pLow) {
        $q = $p - 0.5;
        $r = $q * $q;
        $x = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
           / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    } else {
        $q = sqrt(-2 * log(1 - $p));
        $x = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
           / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
    }
    return $x;
}

/* --------------------------------------------------- measurement evaluation */

/**
 * Evaluates one measurement against a reference: decimal age, z-score and
 * percentile. Returns nulls for the statistics when the age falls outside the
 * reference's published range, which is normal rather than exceptional (WHO
 * publishes no weight beyond 10 years, for instance).
 */
function growth_evaluate($birthDate, $sex, $date, $metric, $value, $referenceId)
{
    $age = growth_decimal_age($birthDate, $date);
    $result = array('age' => $age, 'value' => $value, 'z' => null, 'percentile' => null);
    if ($age === null || $value === null || $value <= 0) {
        return $result;
    }
    $lms = growth_lms_at(growth_reference_rows($referenceId, $metric, $sex), $age);
    if (!$lms) {
        return $result;
    }
    $result['z'] = growth_zscore($value, $lms);
    $result['percentile'] = growth_percentile($result['z']);
    return $result;
}

/* -------------------------------------------------------------- predictions */

/**
 * Mid-parental (genetic target) height in cm, with the conventional band.
 *
 * Boys inherit the mid-parental height plus half the average adult sex
 * difference, girls minus the same, the 13 cm being that average difference.
 * The +/- 8.5 cm band is the target range used in Czech practice; roughly 95%
 * of children finish inside it, which is wide enough that it is a sanity check
 * on a prediction rather than a prediction in itself.
 */
function growth_target_height($fatherCm, $motherCm, $sex)
{
    if (!$fatherCm || !$motherCm) {
        return null;
    }
    $shift = ($sex === 'm') ? 13.0 : -13.0;
    $mid = ($fatherCm + $motherCm + $shift) / 2.0;
    return array('mid' => $mid, 'low' => $mid - 8.5, 'high' => $mid + 8.5);
}

/**
 * Projects adult height by holding the child's current height SDS constant to
 * age 18 - the "stays in their channel" assumption a paediatrician makes by
 * eye.
 *
 * The current SDS is averaged over the most recent measurements rather than
 * taken from the last one alone, so a single mis-measurement (and with
 * children there are always a few) cannot swing the projection. The band comes
 * from the spread of those recent SDS values, with a floor, because a child
 * whose recent points happen to agree closely has not thereby become
 * predictable.
 *
 * Returns null before there is enough history to say anything.
 */
function growth_channel_projection($birthDate, $sex, $measurements, $referenceId, $atAge = 18.0)
{
    $rows = growth_reference_rows($referenceId, 'height', $sex);
    if (!$rows) {
        return null;
    }

    /* A reference that stops before adulthood cannot answer this question.
       Refusing is the only honest option: clamping to the end of the table
       instead - which this used to do - turned "predicted adult height" into
       "predicted height at the last age we have", and against the breastfed
       reference, which ends at one year, that produced a confident 70 cm. */
    if ($rows[count($rows) - 1][0] < $atAge - 0.5) {
        return null;
    }

    /* newest first, keep those with a height, take up to four */
    $recent = array();
    foreach (array_reverse($measurements) as $m) {
        if ($m['height_cm'] === null) {
            continue;
        }
        $age = growth_decimal_age($birthDate, $m['date']);
        $lms = growth_lms_at($rows, $age);
        if (!$lms) {
            continue;
        }
        $z = growth_zscore((float)$m['height_cm'], $lms);
        if ($z === null) {
            continue;
        }
        $recent[] = array('age' => $age, 'z' => $z);
        if (count($recent) >= 4) {
            break;
        }
    }
    if (count($recent) < 2) {
        return null;
    }

    $zs = array();
    foreach ($recent as $r) {
        $zs[] = $r['z'];
    }
    $meanZ = array_sum($zs) / count($zs);

    $variance = 0.0;
    foreach ($zs as $z) {
        $variance += ($z - $meanZ) * ($z - $meanZ);
    }
    $sd = sqrt($variance / count($zs));
    /* a 0.25 SDS floor: even a perfectly consistent child is not this knowable */
    $spread = max($sd, 0.25);

    $adultLms = growth_lms_at($rows, $atAge);
    if (!$adultLms) {
        return null;
    }

    return array(
        'z' => $meanZ,
        'mid' => growth_value_at_z($adultLms, $meanZ),
        'low' => growth_value_at_z($adultLms, $meanZ - $spread),
        'high' => growth_value_at_z($adultLms, $meanZ + $spread),
        'based_on' => count($recent),
        'youngest_age' => $recent[count($recent) - 1]['age'],
    );
}

/* ------------------------------------------------------------- smoothing */

/**
 * Smooths a child's measurements to show the underlying trajectory.
 *
 * Measurements taken by different people, on different equipment, at different
 * times of day carry real noise: half a centimetre of reading error is routine,
 * and a person is measurably taller in the morning than in the evening because
 * the spine compresses over the day. None of that is growth, but all of it
 * shows up as jitter in the line.
 *
 * Two decisions matter here.
 *
 * **The smoothing happens in SDS space, not in centimetres.** A child's SDS is
 * roughly constant - that is what "staying in their channel" means - so in that
 * space the underlying signal is nearly flat and the noise sits on top of it,
 * which is a far easier thing to separate than a steeply rising curve. The
 * smoothed SDS is then converted back through the LMS at each age, so the
 * growth itself is carried by the reference's own shape. Smoothing raw
 * centimetres would instead average together heights that legitimately differ
 * because the child grew in between.
 *
 * **It is a local linear fit, not a moving average.** Averaging assumes the
 * values in the window are repeated measures of one quantity; over any window
 * wide enough to be useful, they are not - the child is growing throughout it.
 * A local line follows that trend, which is also why it behaves at the ends of
 * the series where a moving average flattens out.
 *
 * The bandwidth adapts by nearest neighbours rather than by time, because
 * measurement density is wildly uneven - many in the first year, a couple a
 * year later - and a fixed time window would smooth infancy to mush while
 * barely touching the sparse years.
 *
 * Returns array('curve' => list of array(age, value), 'scatter' => typical
 * residual in the metric's own unit), or null when there is too little data to
 * say anything.
 */
function growth_smooth_series($points, $rows, $options = array())
{
    $span = isset($options['span']) ? $options['span'] : 0.4;
    $steps = isset($options['steps']) ? $options['steps'] : 120;
    $noiseFloor = isset($options['noise']) ? (float)$options['noise'] : 0.0;
    $n = count($points);
    if ($n < 4 || !$rows) {
        return null;
    }

    /* to SDS, dropping anything the reference cannot evaluate; the original
       index is kept so residuals can be reported against the caller's points */
    $xs = array();
    $zs = array();
    $origin = array();
    foreach ($points as $index => $point) {
        $lms = growth_lms_at($rows, $point['age']);
        if (!$lms) {
            continue;
        }
        $z = growth_zscore($point['value'], $lms);
        if ($z === null) {
            continue;
        }
        $xs[] = $point['age'];
        $zs[] = $z;
        $origin[] = $index;
    }
    $n = count($xs);
    if ($n < 4) {
        return null;
    }

    $k = max(3, (int)ceil($span * $n));

    /* Robustness weights, all 1 until the iterations below lower them. */
    $robust = array_fill(0, $n, 1.0);

    /* Local linear regression of SDS on age, tricube weighted by distance and
       bisquare weighted by how badly each point fitted last time round. */
    $fit = function ($x0) use ($xs, $zs, $n, $k, &$robust) {
        $distances = array();
        foreach ($xs as $x) {
            $distances[] = abs($x - $x0);
        }
        $sorted = $distances;
        sort($sorted);
        $h = $sorted[min($k, $n) - 1];
        if ($h <= 0) {
            $h = 1e-6;
        }

        $sw = $swx = $swy = $swxx = $swxy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $u = $distances[$i] / $h;
            if ($u >= 1.0) {
                continue;
            }
            $w = (1.0 - $u * $u * $u);
            $w = $w * $w * $w * $robust[$i];
            if ($w <= 0) {
                continue;
            }
            $dx = $xs[$i] - $x0;
            $sw += $w;
            $swx += $w * $dx;
            $swy += $w * $zs[$i];
            $swxx += $w * $dx * $dx;
            $swxy += $w * $dx * $zs[$i];
        }
        if ($sw <= 0) {
            return null;
        }
        $denom = $sw * $swxx - $swx * $swx;
        if (abs($denom) < 1e-12) {
            return $swy / $sw;   /* degenerate: fall back to the weighted mean */
        }
        /* intercept of the local line at x0, which is the smoothed value */
        return ($swxx * $swy - $swx * $swxy) / $denom;
    };

    /* Two robustness passes, after Cleveland's lowess.
     *
     * This matters for real data rather than being statistical polish. These
     * records contain at least one physically impossible pair - 84.0 cm at
     * 1.51 years followed by 79.5 cm at 1.67 - so one of them is simply wrong,
     * and without this a single bad entry drags the line several centimetres
     * across a whole year. Downweighting by residual lets the other
     * measurements outvote it instead of averaging it in. */
    for ($pass = 0; $pass < 2; $pass++) {
        $residuals = array();
        for ($i = 0; $i < $n; $i++) {
            $fitted = $fit($xs[$i]);
            $residuals[$i] = ($fitted === null) ? 0.0 : ($zs[$i] - $fitted);
        }
        $absolute = array_map('abs', $residuals);
        sort($absolute);
        $median = $absolute[(int)floor($n / 2)];
        if ($median <= 1e-9) {
            break;   /* an exact fit: nothing to downweight */
        }
        for ($i = 0; $i < $n; $i++) {
            $u = $residuals[$i] / (6.0 * $median);
            $robust[$i] = (abs($u) >= 1.0) ? 0.0 : (1.0 - $u * $u) * (1.0 - $u * $u);
        }
    }

    $first = $xs[0];
    $last = $xs[$n - 1];
    if ($last <= $first) {
        return null;
    }

    /* Both forms of the same curve: in the metric's own unit for the growth
       charts, and in SDS for the trend chart, which plots that directly. */
    $curve = array();
    $curveZ = array();
    for ($i = 0; $i <= $steps; $i++) {
        $age = $first + ($last - $first) * $i / $steps;
        $z = $fit($age);
        if ($z === null) {
            continue;
        }
        $curveZ[] = array($age, $z);
        $lms = growth_lms_at($rows, $age);
        if (!$lms) {
            continue;
        }
        $value = growth_value_at_z($lms, $z);
        if ($value !== null) {
            $curve[] = array($age, $value);
        }
    }
    if (count($curve) < 2) {
        return null;
    }

    /* How far each measurement sits from the smoothed line, in the metric's own
       unit. The summary answers "how precise are my measurements, really?" from
       the data rather than from a guess, and the per-point values let the chart
       point at the ones that look mis-recorded. */
    $deviation = array();
    foreach ($origin as $i => $index) {
        $lms = growth_lms_at($rows, $xs[$i]);
        $z = $fit($xs[$i]);
        if (!$lms || $z === null) {
            continue;
        }
        $expected = growth_value_at_z($lms, $z);
        if ($expected !== null) {
            $deviation[$index] = $points[$index]['value'] - $expected;
        }
    }

    $scatter = null;
    if ($deviation) {
        $absolute = array_map('abs', array_values($deviation));
        sort($absolute);
        /* the median absolute deviation, scaled to a standard-deviation-like
           number; the median resists the odd badly mis-recorded value in a way
           a plain RMS would not */
        $scatter = 1.4826 * $absolute[(int)floor(count($absolute) / 2)];
        $scatter = max($scatter, $noiseFloor);
    }

    /* Points far enough off the line to be worth a second look. Four times the
       typical scatter is deliberately conservative: the aim is to catch a digit
       typed wrong or a reading taken with shoes on, not to quibble with normal
       measurement noise. */
    $suspect = array();
    /* Below a handful of measurements there is not enough evidence to call any
       one of them wrong, however far from the line it falls. */
    if ($scatter !== null && $scatter > 0 && $n >= 6) {
        foreach ($deviation as $index => $d) {
            if (abs($d) > 4.0 * $scatter) {
                $suspect[$index] = $d;
            }
        }
    }

    return array(
        'curve' => $curve,
        'curve_z' => $curveZ,
        'scatter' => $scatter,
        'deviation' => $deviation,
        'suspect' => $suspect,
        'points' => $n,
    );
}

/**
 * Growth velocity in cm per year, measured over roughly a year.
 *
 * Not against the previous measurement. Annualising a short gap multiplies the
 * measurement error along with the growth: over four months, half a centimetre
 * of reading error becomes 1.5 cm/year of apparent velocity, and a genuinely
 * steady child appears to speed up and slow down at random. That is how 3.2 cm
 * gained between September and January came out as "8.4 cm/year" when the same
 * child had grown 5.7 cm across the surrounding twelve months.
 *
 * So each measurement is compared against an earlier one at least
 * MIN_VELOCITY_SPAN back, choosing whichever is closest to a full year. That is
 * also the convention in paediatric practice, where a height velocity is not
 * considered meaningful over less than six months and is normally quoted over
 * twelve.
 *
 * Every result carries the interval it was actually measured over, because a
 * velocity without its window is not interpretable.
 */
function growth_velocities($birthDate, $measurements)
{
    $minSpan = 0.7;   /* years; below this the noise dominates the signal */
    $target = 1.0;    /* the window we would like, when the data allows */

    $points = array();
    foreach ($measurements as $m) {
        if ($m['height_cm'] === null) {
            continue;
        }
        $points[] = array(
            'age' => growth_decimal_age($birthDate, $m['date']),
            'date' => $m['date'],
            'height' => (float)$m['height_cm'],
        );
    }

    $out = array();
    for ($i = 1; $i < count($points); $i++) {
        $best = null;
        $bestDistance = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            $dt = $points[$i]['age'] - $points[$j]['age'];
            if ($dt < $minSpan) {
                continue;
            }
            $distance = abs($dt - $target);
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $j;
            } else {
                /* intervals only grow as j decreases, so once we start moving
                   away from a year we will not come back */
                break;
            }
        }
        if ($best === null) {
            continue;
        }
        $dt = $points[$i]['age'] - $points[$best]['age'];
        $out[] = array(
            'date' => $points[$i]['date'],
            'age' => $points[$i]['age'],
            'years' => $dt,
            'from_date' => $points[$best]['date'],
            'cm_per_year' => ($points[$i]['height'] - $points[$best]['height']) / $dt,
        );
    }
    return $out;
}
