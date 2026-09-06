<?php

/**
 * Input handling - the injection surface. Requires
 * data.inc rather than growth.inc alone, since growth_input_number(),
 * growth_valid_date() and growth_safe_return() live there; data.inc is safe to
 * require from the CLI test runner (see the CLI check in auth.inc and the
 * optional config.php in storage.inc).
 */

require_once __DIR__ . '/../src/data.inc';

function test_input_number_accepts_czech_and_plain_decimals()
{
    assert_close(122.5, growth_input_number('122,5'), 1e-9, 'comma decimal');
    assert_close(122.5, growth_input_number('122.5'), 1e-9, 'dot decimal');
    assert_close(1225.5, growth_input_number('1 225,5'), 1e-9, 'space thousands separator');
}

function test_input_number_rejects_garbage()
{
    assert_null(growth_input_number('12abc'), 'trailing letters');
    assert_null(growth_input_number('abc'), 'no digits at all');
    assert_null(growth_input_number(''), 'empty string');
    assert_null(growth_input_number('   '), 'whitespace only');
}

function test_input_number_zero_and_negative_are_not_a_measurement()
{
    /* A height or weight of exactly zero, or negative, is not a real
       measurement - growth_input_number() folds both into "not measured"
       (null), the same as an empty field, rather than storing a value no
       chart could sensibly plot. */
    assert_null(growth_input_number('0'), 'zero');
    assert_null(growth_input_number('-5'), 'negative');
}

function test_input_number_scientific_notation()
{
    /* is_numeric() accepts "1e5" - PHP's own definition of numeric, not a
       format anyone would type here, but worth knowing what happens: it is
       taken at face value rather than rejected. */
    assert_close(100000.0, growth_input_number('1e5'), 1e-9);
}

function test_input_number_very_long_string()
{
    assert_null(growth_input_number(str_repeat('9', 1000) . 'x'), 'long garbage does not crash or pass');
}

function test_valid_date_accepts_real_calendar_dates()
{
    assert_true(growth_valid_date('2026-01-19'));
    assert_true(growth_valid_date('2000-02-29'), 'leap day in a leap year');
}

function test_valid_date_rejects_wrong_shape()
{
    assert_true(!growth_valid_date('19-1-2026'), 'wrong field order/width');
    assert_true(!growth_valid_date('2026/01/19'), 'wrong separator');
    assert_true(!growth_valid_date(''), 'empty string');
    assert_true(!growth_valid_date('not a date'), 'not a date at all');
}

function test_valid_date_rejects_shaped_but_impossible_dates()
{
    /* These match the old regex (\d{4}-\d{2}-\d{2}) but are not real dates -
       exactly the gap growth_valid_date() (checkdate()-backed) closes. */
    assert_true(!growth_valid_date('2026-13-45'), 'month 13, day 45');
    assert_true(!growth_valid_date('0000-00-00'), 'the all-zero placeholder');
    assert_true(!growth_valid_date('2025-02-29'), '2025 is not a leap year');
}

function test_safe_return_rejects_open_redirect()
{
    assert_equals('/child.php', growth_safe_return('/child.php', '/'), 'an ordinary internal path passes through');
    assert_equals('/', growth_safe_return('https://evil.example/', '/'), 'absolute URL rejected');
    assert_equals('/', growth_safe_return('//evil.example/', '/'), 'protocol-relative URL rejected');
    assert_equals('/', growth_safe_return('', '/'), 'empty value falls back');
}
