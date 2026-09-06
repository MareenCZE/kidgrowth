<?php

/**
 * Storage interface.
 *
 * Every backend (storage/json.inc, storage/sqlite.inc, storage/mysql.inc)
 * implements the same set of rust_storage_*() primitives; everything above
 * this line - data.inc and every page - calls only the rust_*() wrappers
 * below, which are backend-agnostic. Adding a backend means writing one more
 * file under storage/ against the contract documented there; nothing else in
 * the application changes.
 *
 * Soft delete's cascade rules live here, once, instead of duplicated per
 * backend: deleting a child stamps the same instant onto every measurement of
 * theirs that was not already gone, and restoring the child restores only the
 * measurements stamped with that exact instant - a measurement the user had
 * already deleted earlier, independently, stays deleted. That distinction is
 * why the primitives take and report a timestamp rather than a bare flag.
 */

/* config.php is optional, not required: with none present, the JSON backend
   and its default path (below) apply, which is what makes "clone it and
   open it" true without a config-file-copying step first. Only switching
   backend, or pointing a path elsewhere, needs one. */
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (!isset($STORAGE_BACKEND)) {
    $STORAGE_BACKEND = 'json';
}
if (!isset($JSON_STORAGE_PATH)) {
    $JSON_STORAGE_PATH = __DIR__ . '/../storage/growth.json';
}
if (!isset($SQLITE_STORAGE_PATH)) {
    $SQLITE_STORAGE_PATH = __DIR__ . '/../storage/growth.sqlite';
}
if (!isset($DEMO_MODE)) {
    $DEMO_MODE = false;
}

$GLOBALS['__rust_storage_backend'] = $STORAGE_BACKEND;
$GLOBALS['__rust_demo_mode'] = (bool)$DEMO_MODE;

/**
 * True for a throwaway instance (a Codespace, most likely) seeded with
 * invented data by tools/seed_demo_data.php - never for a real installation.
 * Disables export (a demo visitor's session should leave no artefact once
 * it ends) and puts a banner on every page saying so.
 */
function rust_demo_mode()
{
    return $GLOBALS['__rust_demo_mode'];
}

switch ($GLOBALS['__rust_storage_backend']) {
    case 'mysql':
        require_once __DIR__ . '/storage/mysql.inc';
        break;
    case 'sqlite':
        require_once __DIR__ . '/storage/sqlite.inc';
        break;
    case 'json':
    default:
        require_once __DIR__ . '/storage/json.inc';
        break;
}

/** The timestamp soft-deletes are stamped with - one clock, not per backend. */
function rust_storage_now()
{
    return date('Y-m-d H:i:s');
}

/* ---------------------------------------------------------------- children */

function rust_children()
{
    return rust_storage_children();
}

/** Deleted children, most recently deleted first - for the "Koš" view. */
function rust_children_deleted()
{
    return rust_storage_children_deleted();
}

function rust_child($id)
{
    return rust_storage_child((int)$id);
}

/** Creates the child if the name is new among active children; returns the id either way. */
function rust_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed = 0)
{
    return rust_storage_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed ? 1 : 0);
}

function rust_child_update($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed = 0)
{
    return rust_storage_child_update((int)$id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed ? 1 : 0);
}

/**
 * Soft-deletes a child and, in the same instant, every measurement of theirs
 * that was not already deleted.
 */
function rust_child_delete($id)
{
    $id = (int)$id;
    $when = rust_storage_now();
    $ok = rust_storage_child_delete($id, $when);
    if ($ok) {
        rust_storage_measurements_delete_for_child($id, $when);
    }
    return $ok;
}

/**
 * Restores a child, and only the measurements the cascade above deleted at
 * the same instant.
 */
function rust_child_restore($id)
{
    $id = (int)$id;
    $when = rust_storage_child_deleted_at($id);
    $restored = rust_storage_child_restore($id);
    if ($restored && $when !== null) {
        rust_storage_measurements_restore_for_child($id, $when);
    }
    return $restored;
}

/* ------------------------------------------------------------ measurements */

function rust_measurements($childId)
{
    return rust_storage_measurements((int)$childId);
}

/** Deleted measurements for one child, most recently deleted first. */
function rust_measurements_deleted($childId)
{
    return rust_storage_measurements_deleted((int)$childId);
}

/**
 * Inserts, updates, or revives the measurement for one child on one date.
 *
 * Keyed on (dite_id, datum) regardless of that row's soft-delete state: saving
 * a date that was previously deleted revives it rather than colliding with it.
 */
function rust_measurement_save($childId, $date, $heightCm, $weightKg, $note)
{
    return rust_storage_measurement_save((int)$childId, $date, $heightCm, $weightKg, $note);
}

function rust_measurement_delete($childId, $id)
{
    return rust_storage_measurement_delete((int)$childId, (int)$id, rust_storage_now());
}

function rust_measurement_restore($childId, $id)
{
    return rust_storage_measurement_restore((int)$childId, (int)$id);
}
