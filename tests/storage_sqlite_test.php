<?php

/**
 * The SQLite backend's own round-trip.
 *
 * @standalone - run by tests/run.php as its own process. It loads
 * src/storage/sqlite.inc, and storage_test.php loads src/storage/json.inc:
 * both declare the same growth_storage_*() functions, so two backends in one
 * process is a fatal redeclaration rather than a test failure.
 *
 * storage_test.php covers the JSON backend, which every install has. This
 * covers the one place the SQL backends are not a transcription of it -
 * growth_storage_measurement_update()'s collision check leans on the unique
 * index rather than on a scan. Skipped where pdo_sqlite is missing; CI
 * installs it, so it runs there regardless of what a developer's PHP has.
 */

if (!extension_loaded('pdo_sqlite')) {
    echo "storage_sqlite_test: skipped, pdo_sqlite not loaded\n";
    exit(0);
}

require_once __DIR__ . '/asserts.php';
require_once __DIR__ . '/../src/storage/sqlite.inc';

function growth_test_sqlite_fixture()
{
    global $SQLITE_STORAGE_PATH;
    $SQLITE_STORAGE_PATH = tempnam(sys_get_temp_dir(), 'growth-test-') . '.sqlite';
    return $SQLITE_STORAGE_PATH;
}

function test_sqlite_measurement_update_can_correct_the_date()
{
    $path = growth_test_sqlite_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    $rowId = growth_storage_measurements($id)[0]['id'];

    $ok = growth_storage_measurement_update($id, $rowId, '2018-06-08', 65.5, 7.1, 'after sickness');
    assert_equals(true, $ok, 'the update was accepted');

    $rows = growth_storage_measurements($id);
    assert_equals(1, count($rows), 'still exactly one measurement');
    assert_equals('2018-06-08', $rows[0]['date'], 'the date moved');
    assert_equals('after sickness', $rows[0]['note'], 'the note came with it');
    @unlink($path);
}

function test_sqlite_measurement_update_refuses_to_collide()
{
    $path = growth_test_sqlite_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    growth_storage_measurement_save($id, '2018-12-01', 70.0, 8.0, null);
    $rows = growth_storage_measurements($id);

    $ok = growth_storage_measurement_update($id, $rows[0]['id'], '2018-12-01', 65.0, 7.0, null);
    assert_equals(false, $ok, 'the collision was refused');
    assert_equals(2, count(growth_storage_measurements($id)), 'both measurements survive');
    @unlink($path);
}

function test_sqlite_measurement_update_ignores_a_deleted_row()
{
    $path = growth_test_sqlite_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    $rowId = growth_storage_measurements($id)[0]['id'];
    growth_storage_measurement_delete($id, $rowId, date('Y-m-d H:i:s'));

    $ok = growth_storage_measurement_update($id, $rowId, '2018-06-02', 66.0, 7.2, null);
    assert_equals(false, $ok, 'a deleted row is not editable');
    assert_equals(1, count(growth_storage_measurements_deleted($id)), 'it is still in the trash');
    @unlink($path);
}

/* Its own miniature runner, since run.php only collects test_*() functions
   from files it can require into its own process. */
$passed = 0;
$failed = 0;
foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_sqlite_') !== 0) {
        continue;
    }
    growth_test_reset_failures();
    $function();
    $failures = growth_test_failures();
    if ($failures) {
        $failed++;
        echo "FAIL $function\n";
        foreach ($failures as $failure) {
            echo "  $failure\n";
        }
    } else {
        $passed++;
    }
}
echo "storage_sqlite_test: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
