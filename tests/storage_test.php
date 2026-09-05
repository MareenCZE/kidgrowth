<?php

/**
 * Storage round-trip and soft-delete behaviour, against the JSON backend -
 * the default, and the one every install has without extra setup.
 *
 * Requires src/storage/json.inc directly rather than going through
 * storage.inc/config.php, and points $JSON_STORAGE_PATH at a fresh temp file
 * per test so tests cannot see each other's data.
 */

require_once __DIR__ . '/../src/storage/json.inc';

/** Points the backend at a fresh, empty temp file and returns its path. */
function rust_test_json_fixture()
{
    global $JSON_STORAGE_PATH;
    $JSON_STORAGE_PATH = tempnam(sys_get_temp_dir(), 'rust-test-') . '.json';
    return $JSON_STORAGE_PATH;
}

function rust_test_json_cleanup($path)
{
    @unlink($path);
}

function test_json_child_upsert_is_idempotent_by_name()
{
    $path = rust_test_json_fixture();
    $id1 = rust_storage_child_upsert('Jana Novakova', 'z', '2018-01-01', null, null, 0);
    $id2 = rust_storage_child_upsert('Jana Novakova', 'z', '2018-01-01', null, null, 0);
    assert_equals($id1, $id2, 'same name upserts to the same id');
    assert_equals(1, count(rust_storage_children()), 'only one child was actually created');
    rust_test_json_cleanup($path);
}

function test_json_measurement_null_vs_zero()
{
    $path = rust_test_json_fixture();
    $id = rust_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    rust_storage_measurement_save($id, '2018-06-01', 65.0, null, null);

    $rows = rust_storage_measurements($id);
    assert_equals(1, count($rows));
    assert_close(65.0, $rows[0]['vyska_cm'], 1e-9, 'height stored');
    assert_null($rows[0]['hmotnost_kg'], 'a missing weight must stay null, not become 0.0');
    rust_test_json_cleanup($path);
}

function test_json_measurement_save_upserts_on_date()
{
    $path = rust_test_json_fixture();
    $id = rust_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    rust_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    rust_storage_measurement_save($id, '2018-06-01', 66.0, 7.2, 'corrected');

    $rows = rust_storage_measurements($id);
    assert_equals(1, count($rows), 'saving the same date again updates, does not duplicate');
    assert_close(66.0, $rows[0]['vyska_cm'], 1e-9);
    assert_equals('corrected', $rows[0]['poznamka']);
    rust_test_json_cleanup($path);
}

function test_json_child_delete_cascades_to_measurements()
{
    $path = rust_test_json_fixture();
    $id = rust_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    rust_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    rust_storage_measurement_save($id, '2018-12-01', 70.0, 8.0, null);

    $when = date('Y-m-d H:i:s');
    rust_storage_child_delete($id, $when);
    rust_storage_measurements_delete_for_child($id, $when);

    assert_equals(0, count(rust_storage_children()), 'child no longer active');
    assert_equals(0, count(rust_storage_measurements($id)), 'measurements no longer active');
    assert_equals(2, count(rust_storage_measurements_deleted($id)), 'both measurements moved to the trash together');
    rust_test_json_cleanup($path);
}

function test_json_restore_only_undoes_the_cascade_it_caused()
{
    $path = rust_test_json_fixture();
    $id = rust_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    rust_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    rust_storage_measurement_save($id, '2018-12-01', 70.0, 8.0, null);
    $rows = rust_storage_measurements($id);
    $independentlyDeletedId = $rows[0]['id'];

    /* One measurement was already deleted on its own, before the child ever was. */
    $earlier = date('Y-m-d H:i:s', time() - 3600);
    rust_storage_measurement_delete($id, $independentlyDeletedId, $earlier);

    /* Now the child is deleted, cascading only to what was still active. */
    $childDeletedAt = date('Y-m-d H:i:s');
    rust_storage_child_delete($id, $childDeletedAt);
    rust_storage_measurements_delete_for_child($id, $childDeletedAt);
    assert_equals(2, count(rust_storage_measurements_deleted($id)), 'both are in the trash now');

    /* Restoring the child should only revive the one the cascade took, not
       the one that was already gone independently and earlier. */
    rust_storage_child_restore($id);
    rust_storage_measurements_restore_for_child($id, $childDeletedAt);

    assert_equals(1, count(rust_storage_children()), 'child restored');
    $active = rust_storage_measurements($id);
    assert_equals(1, count($active), 'only the cascade-deleted measurement came back');
    assert_equals(1, count(rust_storage_measurements_deleted($id)), 'the independently-deleted one stays deleted');
    rust_test_json_cleanup($path);
}

function test_json_measurement_delete_revival_via_save()
{
    $path = rust_test_json_fixture();
    $id = rust_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    rust_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    rust_storage_measurement_delete($id, rust_storage_measurements($id)[0]['id'], date('Y-m-d H:i:s'));
    assert_equals(0, count(rust_storage_measurements($id)), 'deleted');

    /* Saving the same date again must revive the deleted row rather than
       collide with it or create a second one. */
    rust_storage_measurement_save($id, '2018-06-01', 67.0, 7.5, null);
    $active = rust_storage_measurements($id);
    assert_equals(1, count($active), 'exactly one active row for that date');
    assert_close(67.0, $active[0]['vyska_cm'], 1e-9);
    assert_equals(0, count(rust_storage_measurements_deleted($id)), 'nothing left in the trash for this child');
    rust_test_json_cleanup($path);
}
