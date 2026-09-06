<?php

/**
 * The core maths: LMS lookup, z-scores, percentiles, decimal age, BMI and
 * mid-parental target height. Pure functions, no I/O, which is why these
 * come first: highest value, easiest to test.
 */

require_once __DIR__ . '/../src/growth.inc';

function test_zscore_value_at_z_round_trip()
{
    $lms = array('l' => 1.2, 'm' => 100.0, 's' => 0.08);
    foreach (array(-2.5, -1.0, 0.0, 0.5, 1.881, 3.0) as $z) {
        $value = rust_value_at_z($lms, $z);
        $back = rust_zscore($value, $lms);
        assert_close($z, $back, 4.5e-13, "round-trip at z=$z");
    }
}

function test_zscore_value_at_z_round_trip_l_near_zero()
{
    /* L close to 0 takes the log-normal branch in both functions - a
       different code path that needs its own coverage. */
    $lms = array('l' => 0.0, 'm' => 50.0, 's' => 0.1);
    foreach (array(-2.0, 0.0, 2.0) as $z) {
        $value = rust_value_at_z($lms, $z);
        $back = rust_zscore($value, $lms);
        assert_close($z, $back, 4.5e-13, "round-trip (L=0) at z=$z");
    }
}

function test_percentile_known_quantiles()
{
    /* The exact tail percentiles SZU publishes, and the point everything is
       anchored to. */
    assert_close(3.0, rust_percentile(-1.8807936081512509), 0.001, 'z=-1.8808 -> P3');
    assert_close(50.0, rust_percentile(0.0), 1e-9, 'z=0 -> P50');
    assert_close(97.0, rust_percentile(1.8807936081512509), 0.001, 'z=+1.8808 -> P97');
}

function test_percentile_null_propagates()
{
    assert_null(rust_percentile(null));
}

function test_lms_at_monotone_and_bounded()
{
    $rows = array(
        array(0.0, 1.0, 50.0, 0.05),
        array(1.0, 1.0, 75.0, 0.045),
        array(2.0, 1.0, 87.0, 0.04),
    );
    /* Interpolated M must never overshoot its neighbours. */
    for ($age = 0.0; $age <= 2.0; $age += 0.1) {
        $lms = rust_lms_at($rows, $age);
        assert_true($lms !== null, "age $age should be in range");
        assert_true($lms['m'] >= 50.0 - 1e-9 && $lms['m'] <= 87.0 + 1e-9,
            "M at age $age within [50, 87], got {$lms['m']}");
    }
    /* Outside the published range, refuse rather than extrapolate. */
    assert_null(rust_lms_at($rows, -0.5), 'below range');
    assert_null(rust_lms_at($rows, 2.5), 'above range');
    /* Exactly on a published row returns that row's own values, untouched. */
    $exact = rust_lms_at($rows, 1.0);
    assert_equals(75.0, $exact['m'], 'exact row returns its own M');
}

function test_decimal_age_basic()
{
    assert_close(1.0, rust_decimal_age('2019-01-19', '2020-01-19'), 0.01, 'non-leap year span');
    /* 2000 was a leap year, so this span is 366 days, not 365. */
    assert_close(366 / 365.25, rust_decimal_age('2000-01-01', '2001-01-01'), 1e-9, 'leap year span');
    assert_close(0.0, rust_decimal_age('2020-06-15', '2020-06-15'), 1e-9, 'same day');
}

function test_bmi()
{
    /* 20 kg at 100 cm -> BMI 20.0 exactly, a round number worth pinning. */
    assert_close(20.0, rust_bmi(100.0, 20.0), 1e-9);
    assert_null(rust_bmi(0, 20.0), 'zero height');
    assert_null(rust_bmi(100.0, 0), 'zero weight');
    assert_null(rust_bmi(null, 20.0), 'null height');
}

function test_target_height_both_sexes()
{
    $boy = rust_target_height(180.0, 165.0, 'm');
    $girl = rust_target_height(180.0, 165.0, 'z');
    /* Same parents, but the boy's target sits exactly 13 cm above the girl's -
       that shift is the whole content of the sex difference in this formula. */
    assert_close(13.0, $boy['mid'] - $girl['mid'], 1e-9, 'sex shift');
    assert_close(17.0, $boy['high'] - $boy['low'], 1e-9, 'band width');
    assert_null(rust_target_height(null, 165.0, 'm'), 'missing father height');
}

/**
 * The "70 cm bug": rust_channel_projection() used to clamp
 * to the reference table's last age instead of refusing when asked to
 * project past where the table ends, which against the breastfed reference
 * (which stops at one year) silently turned "adult height" into "height at
 * one year" - a confident-looking 70 cm. Tested here against CDC (which
 * ships by default) asked to project to age 30, past its own 20-year
 * ceiling, rather than against the breastfed reference specifically: the
 * guard this pins is the general one, not particular to any one table.
 */
function test_channel_projection_refuses_past_reference_ceiling()
{
    $measurements = array(
        array('datum' => '2020-01-01', 'vyska_cm' => 90.0, 'hmotnost_kg' => 13.0),
        array('datum' => '2021-01-01', 'vyska_cm' => 96.0, 'hmotnost_kg' => 14.0),
    );
    $result = rust_channel_projection('2018-01-01', 'm', $measurements, 'cdc', 30.0);
    assert_null($result, 'must refuse rather than extrapolate past the reference ceiling');
}

function test_channel_projection_succeeds_within_reference_range()
{
    $measurements = array(
        array('datum' => '2020-01-01', 'vyska_cm' => 90.0, 'hmotnost_kg' => 13.0),
        array('datum' => '2021-01-01', 'vyska_cm' => 96.0, 'hmotnost_kg' => 14.0),
        array('datum' => '2022-01-01', 'vyska_cm' => 102.0, 'hmotnost_kg' => 16.0),
    );
    $result = rust_channel_projection('2018-01-01', 'm', $measurements, 'cdc', 18.0);
    assert_true($result !== null, 'a projection within the reference range should succeed');
    assert_true($result['mid'] > 100.0 && $result['mid'] < 220.0, 'projected adult height should be a plausible number');
}
