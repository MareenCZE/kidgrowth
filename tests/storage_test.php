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
function growth_test_json_fixture()
{
    global $JSON_STORAGE_PATH;
    $JSON_STORAGE_PATH = tempnam(sys_get_temp_dir(), 'growth-test-') . '.json';
    return $JSON_STORAGE_PATH;
}

function growth_test_json_cleanup($path)
{
    @unlink($path);
}

function test_json_child_upsert_is_idempotent_by_name()
{
    $path = growth_test_json_fixture();
    $id1 = growth_storage_child_upsert('Jana Novakova', 'f', '2018-01-01', null, null, 0);
    $id2 = growth_storage_child_upsert('Jana Novakova', 'f', '2018-01-01', null, null, 0);
    assert_equals($id1, $id2, 'same name upserts to the same id');
    assert_equals(1, count(growth_storage_children()), 'only one child was actually created');
    growth_test_json_cleanup($path);
}

function test_json_measurement_null_vs_zero()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, null, null);

    $rows = growth_storage_measurements($id);
    assert_equals(1, count($rows));
    assert_close(65.0, $rows[0]['height_cm'], 1e-9, 'height stored');
    assert_null($rows[0]['weight_kg'], 'a missing weight must stay null, not become 0.0');
    growth_test_json_cleanup($path);
}

function test_json_measurement_save_upserts_on_date()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    growth_storage_measurement_save($id, '2018-06-01', 66.0, 7.2, 'corrected');

    $rows = growth_storage_measurements($id);
    assert_equals(1, count($rows), 'saving the same date again updates, does not duplicate');
    assert_close(66.0, $rows[0]['height_cm'], 1e-9);
    assert_equals('corrected', $rows[0]['note']);
    growth_test_json_cleanup($path);
}

function test_json_child_delete_cascades_to_measurements()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    growth_storage_measurement_save($id, '2018-12-01', 70.0, 8.0, null);

    $when = date('Y-m-d H:i:s');
    growth_storage_child_delete($id, $when);
    growth_storage_measurements_delete_for_child($id, $when);

    assert_equals(0, count(growth_storage_children()), 'child no longer active');
    assert_equals(0, count(growth_storage_measurements($id)), 'measurements no longer active');
    assert_equals(2, count(growth_storage_measurements_deleted($id)), 'both measurements moved to the trash together');
    growth_test_json_cleanup($path);
}

function test_json_restore_only_undoes_the_cascade_it_caused()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    growth_storage_measurement_save($id, '2018-12-01', 70.0, 8.0, null);
    $rows = growth_storage_measurements($id);
    $independentlyDeletedId = $rows[0]['id'];

    /* One measurement was already deleted on its own, before the child ever was. */
    $earlier = date('Y-m-d H:i:s', time() - 3600);
    growth_storage_measurement_delete($id, $independentlyDeletedId, $earlier);

    /* Now the child is deleted, cascading only to what was still active. */
    $childDeletedAt = date('Y-m-d H:i:s');
    growth_storage_child_delete($id, $childDeletedAt);
    growth_storage_measurements_delete_for_child($id, $childDeletedAt);
    assert_equals(2, count(growth_storage_measurements_deleted($id)), 'both are in the trash now');

    /* Restoring the child should only revive the one the cascade took, not
       the one that was already gone independently and earlier. */
    growth_storage_child_restore($id);
    growth_storage_measurements_restore_for_child($id, $childDeletedAt);

    assert_equals(1, count(growth_storage_children()), 'child restored');
    $active = growth_storage_measurements($id);
    assert_equals(1, count($active), 'only the cascade-deleted measurement came back');
    assert_equals(1, count(growth_storage_measurements_deleted($id)), 'the independently-deleted one stays deleted');
    growth_test_json_cleanup($path);
}

function test_json_measurement_delete_revival_via_save()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    growth_storage_measurement_delete($id, growth_storage_measurements($id)[0]['id'], date('Y-m-d H:i:s'));
    assert_equals(0, count(growth_storage_measurements($id)), 'deleted');

    /* Saving the same date again must revive the deleted row rather than
       collide with it or create a second one. */
    growth_storage_measurement_save($id, '2018-06-01', 67.0, 7.5, null);
    $active = growth_storage_measurements($id);
    assert_equals(1, count($active), 'exactly one active row for that date');
    assert_close(67.0, $active[0]['height_cm'], 1e-9);
    assert_equals(0, count(growth_storage_measurements_deleted($id)), 'nothing left in the trash for this child');
    growth_test_json_cleanup($path);
}

function test_json_measurement_update_can_correct_the_date()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    $rowId = growth_storage_measurements($id)[0]['id'];

    /* The date typed wrong is the case save() cannot fix: it would leave the
       original row behind and add a second one. */
    $ok = growth_storage_measurement_update($id, $rowId, '2018-06-08', 65.5, 7.1, 'after sickness');
    assert_equals(true, $ok, 'the update was accepted');

    $rows = growth_storage_measurements($id);
    assert_equals(1, count($rows), 'still exactly one measurement');
    assert_equals('2018-06-08', $rows[0]['date'], 'the date moved');
    assert_close(65.5, $rows[0]['height_cm'], 1e-9);
    assert_equals('after sickness', $rows[0]['note'], 'the note came with it');
    growth_test_json_cleanup($path);
}

function test_json_measurement_update_refuses_to_collide()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    growth_storage_measurement_save($id, '2018-12-01', 70.0, 8.0, null);
    $rows = growth_storage_measurements($id);

    /* Moving one row onto the other's date would silently merge two visits
       into one. Refused, and nothing is written. */
    $ok = growth_storage_measurement_update($id, $rows[0]['id'], '2018-12-01', 65.0, 7.0, null);
    assert_equals(false, $ok, 'the collision was refused');

    $after = growth_storage_measurements($id);
    assert_equals(2, count($after), 'both measurements survive');
    assert_equals('2018-06-01', $after[0]['date'], 'the first row kept its date');
    growth_test_json_cleanup($path);
}

function test_json_measurement_update_ignores_a_deleted_row()
{
    $path = growth_test_json_fixture();
    $id = growth_storage_child_upsert('Petr Svoboda', 'm', '2018-01-01', null, null, 0);
    growth_storage_measurement_save($id, '2018-06-01', 65.0, 7.0, null);
    $rowId = growth_storage_measurements($id)[0]['id'];
    growth_storage_measurement_delete($id, $rowId, date('Y-m-d H:i:s'));

    /* Editing something that is in the trash is not an edit - it is a
       restore, and that has its own operation. */
    $ok = growth_storage_measurement_update($id, $rowId, '2018-06-02', 66.0, 7.2, null);
    assert_equals(false, $ok, 'a deleted row is not editable');
    assert_equals(1, count(growth_storage_measurements_deleted($id)), 'it is still in the trash');
    growth_test_json_cleanup($path);
}

function test_json_children_are_ordered_oldest_first()
{
    /* growth_children.position was read by every ordering query and written
       by nothing, so it was always 0 and this is what the order had in fact
       always been. Now it is what the code says as well, in all three
       backends - see the matching SQLite test. */
    $path = growth_test_json_fixture();
    growth_storage_child_upsert('Younger', 'm', '2020-05-05', null, null, 0);
    growth_storage_child_upsert('Older', 'f', '2016-02-02', null, null, 0);
    $names = array_map(function ($c) {
        return $c['name'];
    }, growth_storage_children());
    assert_equals(array('Older', 'Younger'), $names, 'oldest first');
    growth_test_json_cleanup($path);
}
