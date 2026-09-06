<?php

/**
 * The SQLite backend's own round-trip, run only where pdo_sqlite exists.
 *
 * storage_test.php covers the JSON backend, which every install has; this
 * covers the one place the SQL backends are not a transcription of it -
 * growth_storage_measurement_update()'s collision check leans on the unique
 * index rather than on a scan. CI installs pdo_sqlite, so this runs there
 * even when a developer's local PHP has no SQLite at all.
 *
 * Everything below sits inside the extension check on purpose. A top-level
 * function declaration is defined when the file is compiled, so an early
 * `return` would skip the require and still leave the runner a set of test_*
 * functions to call - against whichever backend happened to be loaded first.
 * Declarations inside a conditional block only exist if the block runs.
 */

if (!extension_loaded('pdo_sqlite')) {
    echo "storage_sqlite_test: skipped, pdo_sqlite not loaded\n";
} else {
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
}
