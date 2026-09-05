<?php

/**
 * The zero-dependency runner's assertion helpers.
 *
 * No framework, no exceptions-as-control-flow: a failed assertion records a
 * message and lets the test function keep running, so one test can report
 * more than one problem in a single run. tests/run.php reads the recorded
 * failures back out after calling each test_*() function.
 */

$GLOBALS['__rust_test_failures'] = array();

function rust_test_reset_failures()
{
    $GLOBALS['__rust_test_failures'] = array();
}

function rust_test_failures()
{
    return $GLOBALS['__rust_test_failures'];
}

function rust_test_fail($message)
{
    $GLOBALS['__rust_test_failures'][] = $message;
}

function assert_true($condition, $message = '')
{
    if (!$condition) {
        rust_test_fail(($message !== '' ? "$message: " : '') . 'expected true, got false');
    }
}

function assert_null($value, $message = '')
{
    if ($value !== null) {
        rust_test_fail(($message !== '' ? "$message: " : '') . 'expected null, got ' . var_export($value, true));
    }
}

function assert_equals($expected, $actual, $message = '')
{
    if ($expected !== $actual) {
        rust_test_fail(($message !== '' ? "$message: " : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** For floating-point results, where exact equality is the wrong question. */
function assert_close($expected, $actual, $tolerance, $message = '')
{
    if ($actual === null || abs($expected - $actual) > $tolerance) {
        rust_test_fail(($message !== '' ? "$message: " : '')
            . "expected $expected +/- $tolerance, got " . var_export($actual, true));
    }
}
