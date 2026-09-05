<?php

/**
 * Locale selection and locale-aware formatting. rust_locale() memoises its
 * result per request in a static variable, so each test that needs a
 * specific locale sets $_GET['lang'] and then forces a fresh process via a
 * subprocess - cheap enough here, and the only way to test three different
 * locale resolutions without the memoisation from an earlier test leaking
 * into a later one.
 */

require_once __DIR__ . '/../src/data.inc';

/** Runs a snippet of PHP in a fresh CLI process, returning trimmed stdout. */
function rust_test_run_locale_snippet($lang, $code)
{
    $php = escapeshellarg(PHP_BINARY);
    $root = escapeshellarg(dirname(__DIR__) . '/src/data.inc');
    $script = "\$_GET['lang'] = '$lang'; require $root; $code";
    $cmd = $php . ' -r ' . escapeshellarg($script) . ' 2>&1';
    return trim(shell_exec($cmd));
}

function test_locale_defaults_to_english_without_any_signal()
{
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r '
        . escapeshellarg('require ' . escapeshellarg(dirname(__DIR__) . '/src/data.inc') . '; echo rust_locale();'));
    assert_equals('en', trim($out));
}

function test_locale_lang_param_selects_czech()
{
    assert_equals('cs', rust_test_run_locale_snippet('cs', 'echo rust_locale();'));
}

function test_num_uses_locale_decimal_separator()
{
    assert_equals('122.5', rust_test_run_locale_snippet('en', 'echo rust_num(122.5);'));
    assert_equals('122,5', rust_test_run_locale_snippet('cs', 'echo rust_num(122.5);'));
}

function test_num_trims_trailing_zeros_in_both_locales()
{
    assert_equals('20', rust_test_run_locale_snippet('en', 'echo rust_num(20.0);'));
    assert_equals('20', rust_test_run_locale_snippet('cs', 'echo rust_num(20.0);'));
}

function test_date_format_differs_by_locale()
{
    assert_equals('19 Jan 2026', rust_test_run_locale_snippet('en', "echo rust_date_cz('2026-01-19');"));
    assert_equals('19. 1. 2026', rust_test_run_locale_snippet('cs', "echo rust_date_cz('2026-01-19');"));
}

function test_years_plural_english_vs_czech_declension()
{
    assert_equals('1 year', rust_test_run_locale_snippet('en', 'echo rust_years_cz(1.0);'));
    assert_equals('5 years', rust_test_run_locale_snippet('en', 'echo rust_years_cz(5.0);'));
    assert_equals('1 roku', rust_test_run_locale_snippet('cs', 'echo rust_years_cz(1.0);'));
    assert_equals('5 let', rust_test_run_locale_snippet('cs', 'echo rust_years_cz(5.0);'));
}

function test_age_format_english_vs_czech()
{
    assert_equals('8 years 4 months', rust_test_run_locale_snippet('en', 'echo rust_age_cz(8 + 4 / 12.0);'));
    assert_equals('8 let 4 měsíce', rust_test_run_locale_snippet('cs', 'echo rust_age_cz(8 + 4 / 12.0);'));
}

function test_median_diff_sign_and_unit()
{
    /* rust_num() trims trailing zeros (its own documented behaviour), so a
       whole-number difference like this one prints as "+3", not "+3.0". */
    assert_equals('+3 cm from median', rust_test_run_locale_snippet('en', 'echo rust_median_diff_cz(103, 100, 1, "cm");'));
    assert_equals('+3 cm oproti mediánu', rust_test_run_locale_snippet('cs', 'echo rust_median_diff_cz(103, 100, 1, "cm");'));
    assert_equals('+2.3 cm from median', rust_test_run_locale_snippet('en', 'echo rust_median_diff_cz(102.3, 100, 1, "cm");'));
}

function test_t_falls_back_to_english_then_to_key_itself()
{
    assert_equals('Back', rust_test_run_locale_snippet('en', "echo t('nav_back');"));
    assert_equals('Zpět', rust_test_run_locale_snippet('cs', "echo t('nav_back');"));
    /* A key that exists nowhere degrades to the key itself, visibly wrong
       rather than silently blank. */
    assert_equals('no_such_key_at_all', rust_test_run_locale_snippet('en', "echo t('no_such_key_at_all');"));
}

function test_t_substitutes_named_placeholders()
{
    assert_equals('Imported 5 measurements.',
        rust_test_run_locale_snippet('en', "echo t('import_summary', array('n' => 5));"));
}
