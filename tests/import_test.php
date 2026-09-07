<?php

/**
 * CSV import, through the real storage wrappers.
 *
 * Chooses the JSON backend before requiring storage.inc, which is what stops
 * a developer's own src/config.php from pointing these fixtures at a real
 * database - see the note beside the config load there.
 *
 * What is worth testing here is not that a well-formed file imports; it is
 * what happens to a file that is wrong in the ways a hand-edited spreadsheet
 * is wrong. Every case below was a silent one before.
 */

$STORAGE_BACKEND = 'json';
$JSON_STORAGE_PATH = tempnam(sys_get_temp_dir(), 'growth-import-test-') . '.json';

require_once __DIR__ . '/asserts.php';
require_once __DIR__ . '/../src/storage.inc';
require_once __DIR__ . '/../src/import.inc';

/** Runs one CSV through the importer against an empty store. */
function growth_test_import($csv)
{
    global $JSON_STORAGE_PATH;
    @unlink($JSON_STORAGE_PATH);
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $report = growth_import_csv($handle);
    fclose($handle);
    return $report;
}

/** True when some reported error mentions the given line number. */
function growth_test_error_mentions($report, $needle)
{
    foreach ($report['errors'] as $message) {
        if (strpos($message, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function test_import_keeps_the_breastfed_flag()
{
    /* Item 12: the flag decides whether the Czech breastfed-infant reference
       is offered at all, and it used to be dropped on the way through a CSV. */
    $report = growth_test_import(
        "child,sex,birth_date,father_cm,mother_cm,breastfed,date,height_cm,weight_kg,note\n" .
        "Kojenec,f,2024-01-10,180,168,1,2024-03-10,58,4.9,\n"
    );
    assert_equals(0, count($report['errors']), 'no errors');
    assert_equals(1, $report['rows'], 'the measurement went in');
    $children = growth_children();
    assert_equals(1, count($children), 'one child');
    assert_equals(1, (int)$children[0]['breastfed'], 'the child is marked breastfed');
}

function test_import_without_a_breastfed_column_defaults_to_not_breastfed()
{
    /* The column is optional, and a file written before it existed must still
       import rather than be refused for lacking it. */
    $report = growth_test_import(
        "child,sex,birth_date,date,height_cm\n" .
        "Bez Sloupce,m,2024-01-10,2024-03-10,58\n"
    );
    assert_equals(0, count($report['errors']), 'no errors');
    assert_equals(0, (int)growth_children()[0]['breastfed'], 'not breastfed');
}

function test_import_refuses_an_unreadable_breastfed_value()
{
    $report = growth_test_import(
        "child,sex,birth_date,breastfed,date,height_cm\n" .
        "Divny,m,2024-01-10,maybe,2024-03-10,58\n"
    );
    assert_equals(0, $report['rows'], 'nothing was imported');
    assert_true(growth_test_error_mentions($report, '2'), 'the line number is named');
    assert_equals(0, count(growth_children()), 'and no child was created');
}

function test_import_accepts_the_spellings_a_spreadsheet_produces()
{
    foreach (array('1', 'yes', 'TRUE', 'ano', ' Ano ') as $written) {
        assert_equals(true, growth_input_flag($written), "'$written' means yes");
    }
    foreach (array('0', 'no', 'FALSE', 'ne', ' N ') as $written) {
        assert_equals(false, growth_input_flag($written), "'$written' means no");
    }
    assert_null(growth_input_flag('maybe'), 'anything else is refused');
    assert_null(growth_input_flag(''), 'an empty cell is not a value');
}

function test_import_reports_a_child_row_that_disagrees_with_itself()
{
    /* Item 13: the child columns are taken from the first row that names a
       child. They used to be dropped from every later row without a word, so
       a file carrying two different birth dates for one child imported as if
       it had carried one. */
    $report = growth_test_import(
        "child,sex,birth_date,father_cm,mother_cm,date,height_cm\n" .
        "Jana,f,2018-03-14,180,165,2019-01-10,80\n" .
        "Jana,f,2018-04-14,180,165,2019-02-10,81\n"
    );
    assert_equals(2, $report['rows'], 'both measurements were still imported');
    assert_true(growth_test_error_mentions($report, 'birth_date'), 'the column is named');
    assert_true(growth_test_error_mentions($report, '3'), 'the line is named');
    assert_equals('2018-03-14', growth_children()[0]['birth_date'], 'the first row won');
}

function test_import_reports_each_disagreeing_column_once()
{
    /* A file that disagrees once usually disagrees on every remaining row.
       One message per column, not one per row, or the message that matters is
       buried under ninety-nine identical ones. */
    $csv = "child,sex,birth_date,father_cm,date,height_cm\n";
    $csv .= "Petr,m,2018-03-14,180,2019-01-10,80\n";
    for ($i = 2; $i <= 20; $i++) {
        $csv .= "Petr,m,2018-03-14,181,2019-01-" . sprintf('%02d', $i) . ",80\n";
    }
    $report = growth_test_import($csv);
    assert_equals(20, $report['rows'], 'every measurement was imported');
    assert_equals(1, count($report['errors']), 'exactly one message about father_cm');
}

function test_import_treats_a_blank_child_cell_as_saying_nothing()
{
    /* The realistic hand-made file fills the child columns on the first row
       only. That is not a disagreement and must not be reported as one. */
    $report = growth_test_import(
        "child,sex,birth_date,father_cm,mother_cm,breastfed,date,height_cm\n" .
        "Tereza,f,2018-03-14,180,165,1,2019-01-10,80\n" .
        "Tereza,f,,,,,2019-02-10,81\n"
    );
    assert_equals(0, count($report['errors']), 'nothing to report');
    assert_equals(2, $report['rows'], 'both measurements imported');
    assert_equals(1, (int)growth_children()[0]['breastfed'], 'the first row still stands');
}

function test_import_still_refuses_a_czech_sex_value()
{
    /* Item 10, guarded here as well now that the surrounding code moved. */
    $report = growth_test_import(
        "child,sex,birth_date,date,height_cm\n" .
        "Holka,z,2018-03-14,2019-01-10,80\n"
    );
    assert_equals(0, $report['rows'], 'nothing imported');
    assert_equals(0, count(growth_children()), 'no child created');
}

register_shutdown_function(function () {
    global $JSON_STORAGE_PATH;
    @unlink($JSON_STORAGE_PATH);
});
