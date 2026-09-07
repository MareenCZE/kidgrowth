<?php

/**
 * Reading a CSV of measurements into storage, as a library.
 *
 * Separated from src/import.php - the upload page - for the same reason
 * reference_build.inc is separate from install.php: the work and the form
 * around it are different things, only one of them can be tested without a
 * web server, and a second front end onto the same work is a matter of time
 * rather than of principle.
 *
 * Nothing here echoes, redirects, or touches $_FILES. It takes an open handle
 * and returns a report; whoever called it decides how to show it.
 *
 * Storage is the caller's to arrange, deliberately: requiring storage.inc
 * here would read src/config.php and tie a test run to whichever backend the
 * developer's own machine happens to be configured for.
 * tests/storage_test.php keeps its distance for the same reason.
 */

require_once __DIR__ . '/i18n.inc';

const GROWTH_IMPORT_MAX_BYTES = 2 * 1024 * 1024; /* generous for a file this narrow - even 10 000 rows is a fraction of this */
const GROWTH_IMPORT_MAX_ROWS = 10000;

/**
 * A cheap, dependency-free "is this actually text" check: a genuine text
 * file - CSV included - has no null bytes anywhere in it, which every binary
 * format (images, the old .rcz format, a mistakenly attached spreadsheet
 * file) does within the first few bytes almost without exception.
 */
function growth_looks_like_text($path)
{
    $sample = @file_get_contents($path, false, null, 0, 8192);
    return $sample !== false && strpos($sample, "\0") === false;
}

/**
 * Reads the CSV and writes it into the database.
 *
 * Deliberately tolerant about what it accepts and strict about what it stores:
 * an empty height cell becomes NULL rather than zero, because a stored zero
 * would be read back as a real measurement of 0 cm and would wreck every chart
 * and trend built on it.
 */
function growth_import_csv($handle)
{
    $report = array('children' => array(), 'rows' => 0, 'skipped' => 0, 'errors' => array());

    $header = fgetcsv($handle);
    if (!$header) {
        $report['errors'][] = t('import_error_empty_file');
        return $report;
    }
    /* strip a UTF-8 BOM off the first column name if the file has one */
    $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', $header[0]);
    $header = array_map(function ($name) {
        return strtolower(trim((string)$name));
    }, $header);

    $required = array('child', 'sex', 'birth_date', 'date');
    foreach ($required as $column) {
        if (!in_array($column, $header, true)) {
            $report['errors'][] = t('import_error_missing_column', array('column' => $column));
            return $report;
        }
    }

    $childIds = array();
    $childFacts = array();   /* the child columns as the first row for that child stated them */
    $conflicts = array();    /* which (child, column) disagreements have already been reported */
    $line = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if ($line - 1 > GROWTH_IMPORT_MAX_ROWS) {
            $report['errors'][] = t('import_error_too_many_rows', array('max' => GROWTH_IMPORT_MAX_ROWS));
            break;
        }
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $data = @array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row, 0, count($header))
        );
        if (!$data) {
            $report['errors'][] = t('import_error_column_count', array('line' => $line));
            continue;
        }

        $name = trim((string)$data['child']);
        $date = trim((string)$data['date']);
        if ($name === '' || !growth_valid_date($date)) {
            $report['skipped']++;
            continue;
        }

        /* Checked on every row that carries it, not only on the row that
           creates the child: a file that was never converted from an older
           export has the wrong spelling throughout, and catching it only when
           the first child happens to be female would be luck rather than a
           check. The value is still taken from the first row for a given
           child, as everything else about that child is. */
        $sex = growth_input_sex($data['sex']);
        if ($sex === null) {
            $report['errors'][] = t('import_error_invalid_sex', array('line' => $line));
            continue;
        }

        /* An absent or empty cell means "this row does not say", which is a
           perfectly ordinary shape for a hand-made file that fills the child
           columns on the first row only. A cell that says something
           unreadable is a different matter and is refused, the way an
           unreadable sex is. */
        $breastfed = null;
        if (isset($data['breastfed']) && trim((string)$data['breastfed']) !== '') {
            $breastfed = growth_input_flag($data['breastfed']);
            if ($breastfed === null) {
                $report['errors'][] = t('import_error_invalid_flag',
                    array('line' => $line, 'column' => 'breastfed'));
                continue;
            }
        }
        $stated = array(
            'sex' => $sex,
            'birth_date' => growth_valid_date(trim((string)$data['birth_date']))
                ? trim((string)$data['birth_date']) : null,
            'father_cm' => growth_input_number(isset($data['father_cm']) ? $data['father_cm'] : ''),
            'mother_cm' => growth_input_number(isset($data['mother_cm']) ? $data['mother_cm'] : ''),
            'breastfed' => $breastfed,
        );

        if (!isset($childIds[$name])) {
            if ($stated['birth_date'] === null) {
                $report['errors'][] = t('import_error_invalid_birth', array('line' => $line));
                continue;
            }
            $childIds[$name] = growth_child_upsert(
                $name, $sex, $stated['birth_date'],
                $stated['father_cm'], $stated['mother_cm'],
                $stated['breastfed'] ? 1 : 0
            );
            $childFacts[$name] = $stated;
            $report['children'][$name] = 0;
        } else {
            /* The child columns repeat on every row of a flat export, so every
               row after the first is a second statement of the same facts. The
               first row still wins - a name is all this format has to identify
               a child by, and there is nothing to reconcile two answers
               against - but a row that disagrees is now said out loud instead
               of being dropped in silence. Reported once per child and column,
               because a file that disagrees once usually disagrees on every
               remaining row and a hundred identical messages would bury the
               one that matters. */
            foreach ($stated as $column => $value) {
                if ($value === null || $value === $childFacts[$name][$column]) {
                    continue;
                }
                if (isset($conflicts[$name][$column])) {
                    continue;
                }
                $conflicts[$name][$column] = true;
                $report['errors'][] = t('import_error_child_conflict', array(
                    'line' => $line,
                    'column' => $column,
                    'child' => $name,
                ));
            }
        }

        $height = growth_input_number(isset($data['height_cm']) ? $data['height_cm'] : '');
        $weight = growth_input_number(isset($data['weight_kg']) ? $data['weight_kg'] : '');
        if ($height === null && $weight === null) {
            $report['skipped']++;
            continue;
        }

        $note = isset($data['note']) ? trim((string)$data['note']) : '';
        growth_measurement_save($childIds[$name], $date, $height, $weight, $note !== '' ? $note : null);
        $report['rows']++;
        $report['children'][$name]++;
    }
    return $report;
}
