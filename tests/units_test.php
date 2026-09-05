<?php

/**
 * Imperial/metric conversion and the feet-and-inches input parser - the
 * fiddly, easy-to-get-wrong half of unit support, which is exactly why this
 * file exists.
 *
 * Runs each case in a fresh CLI process (rust_units() memoises per request)
 * with $_GET['units'] set before requiring data.inc, the same pattern
 * i18n_test.php uses for locale.
 */

require_once __DIR__ . '/../src/data.inc';

function rust_test_run_units_snippet($units, $code)
{
    $php = escapeshellarg(PHP_BINARY);
    $root = escapeshellarg(dirname(__DIR__) . '/src/data.inc');
    $script = "\$_GET['units'] = '$units'; require $root; $code";
    $cmd = $php . ' -r ' . escapeshellarg($script) . ' 2>&1';
    return trim(shell_exec($cmd));
}

function test_units_defaults_to_metric()
{
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r '
        . escapeshellarg('require ' . escapeshellarg(dirname(__DIR__) . '/src/data.inc') . '; echo rust_units();'));
    assert_equals('metric', trim($out));
}

function test_display_length_round_trip()
{
    /* 100 cm is roughly 39.37 in; converting back should land within
       floating-point noise of the original. */
    $out = rust_test_run_units_snippet('imperial', 'echo rust_display_length(100.0);');
    assert_close(39.3700787, (float)$out, 1e-5);
}

function test_display_length_metric_is_unchanged()
{
    $out = rust_test_run_units_snippet('metric', 'echo rust_display_length(100.0);');
    assert_equals('100', $out);
}

function test_length_unit_label()
{
    assert_equals('cm', rust_test_run_units_snippet('metric', 'echo rust_length_unit();'));
    assert_equals('in', rust_test_run_units_snippet('imperial', 'echo rust_length_unit();'));
}

function test_display_weight_round_trip()
{
    /* 1 kg is about 2.2046 lb. */
    $out = rust_test_run_units_snippet('imperial', 'echo rust_display_weight(1.0);');
    assert_close(2.2046226, (float)$out, 1e-5);
}

function test_parse_length_metric_is_plain_cm()
{
    $out = rust_test_run_units_snippet('metric', "echo rust_parse_length_input('122,5');");
    assert_close(122.5, (float)$out, 1e-9);
}

function test_parse_length_imperial_feet_and_inches()
{
    /* 4'3" is 51 inches, i.e. 51 * 2.54 cm. */
    foreach (array("4'3\"", "4' 3\"", "4'3", "4ft3in", "4 ft 3 in") as $input) {
        $out = rust_test_run_units_snippet('imperial', "echo rust_parse_length_input(" . var_export($input, true) . ");");
        assert_close(51 * 2.54, (float)$out, 1e-6, "input \"$input\"");
    }
}

function test_parse_length_imperial_feet_only()
{
    $out = rust_test_run_units_snippet('imperial', "echo rust_parse_length_input(\"4'\");");
    assert_close(48 * 2.54, (float)$out, 1e-6);
}

function test_parse_length_imperial_decimal_inches_with_fraction()
{
    /* A decimal inches part in the feet-and-inches form. */
    $out = rust_test_run_units_snippet('imperial', "echo rust_parse_length_input(\"4'10.5\\\"\");");
    assert_close((4 * 12 + 10.5) * 2.54, (float)$out, 1e-6);
}

function test_parse_length_imperial_bare_number_is_inches()
{
    /* No feet marker at all - read as a plain number of inches. */
    $out = rust_test_run_units_snippet('imperial', "echo rust_parse_length_input('48.2');");
    assert_close(48.2 * 2.54, (float)$out, 1e-6);
}

function test_parse_length_rejects_garbage()
{
    $out = rust_test_run_units_snippet('imperial', "var_export(rust_parse_length_input('not a height'));");
    assert_equals('NULL', $out);
}

function test_parse_weight_metric_is_plain_kg()
{
    $out = rust_test_run_units_snippet('metric', "echo rust_parse_weight_input('20,5');");
    assert_close(20.5, (float)$out, 1e-9);
}

function test_parse_weight_imperial_is_pounds()
{
    /* 45 lb -> kg */
    $out = rust_test_run_units_snippet('imperial', "echo rust_parse_weight_input('45');");
    assert_close(45 * 0.45359237, (float)$out, 1e-9);
}

function test_lms_conversion_is_scale_invariant()
{
    /* The whole justification for converting M alone (units.inc's
       rust_display_chart_rows() docblock): z-score computed against a
       converted M and a converted value must equal the z-score computed in
       metric, since (X/M)^L is invariant under scaling both by the same
       factor. */
    require_once __DIR__ . '/../src/rust.inc';
    $lms = array('l' => 1.1, 'm' => 100.0, 's' => 0.08);
    $metricZ = rust_zscore(103.0, $lms);

    $factor = 1.0 / RUST_CM_PER_INCH;
    $lmsInches = array('l' => $lms['l'], 'm' => $lms['m'] * $factor, 's' => $lms['s']);
    $inchesZ = rust_zscore(103.0 * $factor, $lmsInches);

    assert_close($metricZ, $inchesZ, 1e-9);
}
