<?php

/**
 * Locale selection and locale-aware formatting. growth_locale() memoises its
 * result per request in a static variable, so each test that needs a
 * specific locale sets $_GET['lang'] and then forces a fresh process via a
 * subprocess - cheap enough here, and the only way to test three different
 * locale resolutions without the memoisation from an earlier test leaking
 * into a later one.
 */

require_once __DIR__ . '/../src/data.inc';

/** Runs a snippet of PHP in a fresh CLI process, returning trimmed stdout. */
function growth_test_run_locale_snippet($lang, $code)
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
        . escapeshellarg('require ' . escapeshellarg(dirname(__DIR__) . '/src/data.inc') . '; echo growth_locale();'));
    assert_equals('en', trim($out));
}

function test_locale_lang_param_selects_czech()
{
    assert_equals('cs', growth_test_run_locale_snippet('cs', 'echo growth_locale();'));
}

function test_num_uses_locale_decimal_separator()
{
    assert_equals('122.5', growth_test_run_locale_snippet('en', 'echo growth_num(122.5);'));
    assert_equals('122,5', growth_test_run_locale_snippet('cs', 'echo growth_num(122.5);'));
}

function test_num_trims_trailing_zeros_in_both_locales()
{
    assert_equals('20', growth_test_run_locale_snippet('en', 'echo growth_num(20.0);'));
    assert_equals('20', growth_test_run_locale_snippet('cs', 'echo growth_num(20.0);'));
}

function test_date_format_differs_by_locale()
{
    assert_equals('19 Jan 2026', growth_test_run_locale_snippet('en', "echo growth_format_date('2026-01-19');"));
    assert_equals('19. 1. 2026', growth_test_run_locale_snippet('cs', "echo growth_format_date('2026-01-19');"));
}

function test_years_plural_english_vs_czech_declension()
{
    assert_equals('1 year', growth_test_run_locale_snippet('en', 'echo growth_format_years(1.0);'));
    assert_equals('5 years', growth_test_run_locale_snippet('en', 'echo growth_format_years(5.0);'));
    assert_equals('1 roku', growth_test_run_locale_snippet('cs', 'echo growth_format_years(1.0);'));
    assert_equals('5 let', growth_test_run_locale_snippet('cs', 'echo growth_format_years(5.0);'));
}

function test_age_format_english_vs_czech()
{
    assert_equals('8 years 4 months', growth_test_run_locale_snippet('en', 'echo growth_format_age(8 + 4 / 12.0);'));
    assert_equals('8 let 4 měsíce', growth_test_run_locale_snippet('cs', 'echo growth_format_age(8 + 4 / 12.0);'));
}

function test_median_diff_sign_and_unit()
{
    /* growth_num() trims trailing zeros (its own documented behaviour), so a
       whole-number difference like this one prints as "+3", not "+3.0". */
    assert_equals('+3 cm from median', growth_test_run_locale_snippet('en', 'echo growth_format_median_diff(103, 100, 1, "cm");'));
    assert_equals('+3 cm oproti mediánu', growth_test_run_locale_snippet('cs', 'echo growth_format_median_diff(103, 100, 1, "cm");'));
    assert_equals('+2.3 cm from median', growth_test_run_locale_snippet('en', 'echo growth_format_median_diff(102.3, 100, 1, "cm");'));
}

function test_t_falls_back_to_english_then_to_key_itself()
{
    assert_equals('Back', growth_test_run_locale_snippet('en', "echo t('nav_back');"));
    assert_equals('Zpět', growth_test_run_locale_snippet('cs', "echo t('nav_back');"));
    /* A key that exists nowhere degrades to the key itself, visibly wrong
       rather than silently blank. */
    assert_equals('no_such_key_at_all', growth_test_run_locale_snippet('en', "echo t('no_such_key_at_all');"));
}

function test_t_substitutes_named_placeholders()
{
    assert_equals('Imported 5 measurements.',
        growth_test_run_locale_snippet('en', "echo t('import_summary', array('n' => 5));"));
}

function test_both_languages_say_the_same_things()
{
    /* A key added to one file and forgotten in the other shows up as the raw
       key on the page, in the language of whoever was not thinking about it
       at the time. Cheap to check, and easy to get wrong every time a feature
       adds a dozen strings at once. */
    $en = require __DIR__ . '/../src/lang/en.php';
    $cs = require __DIR__ . '/../src/lang/cs.php';
    assert_equals('', implode(', ', array_diff(array_keys($en), array_keys($cs))),
        'keys in English but not in Czech');
    assert_equals('', implode(', ', array_diff(array_keys($cs), array_keys($en))),
        'keys in Czech but not in English');
    foreach ($en as $key => $value) {
        assert_true(trim((string)$cs[$key]) !== '', "the Czech $key is not empty");
    }
}
