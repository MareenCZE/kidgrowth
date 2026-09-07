<?php

/**
 * SQLite storage backend - one file, no server process, preferred over MySQL
 * where the pdo_sqlite extension is available. Creates its own schema on
 * first connection, so there is nothing to run by hand.
 */

function growth_sqlite_pdo()
{
    static $pdo = null;

    if ($pdo === null) {
        global $SQLITE_STORAGE_PATH;
        $dir = dirname($SQLITE_STORAGE_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $pdo = new PDO('sqlite:' . $SQLITE_STORAGE_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec('CREATE TABLE IF NOT EXISTS growth_children (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            sex TEXT NOT NULL CHECK (sex IN (\'m\', \'f\')),
            birth_date TEXT NOT NULL,
            father_height_cm REAL,
            mother_height_cm REAL,
            breastfed INTEGER NOT NULL DEFAULT 0,
            deleted_at TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS growth_measurements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            child_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            height_cm REAL,
            weight_kg REAL,
            note TEXT,
            deleted_at TEXT
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS child_date ON growth_measurements (child_id, date)');
    }

    return $pdo;
}

/* ---------------------------------------------------------------- children */

function growth_storage_children()
{
    $stmt = growth_sqlite_pdo()->query(
        'SELECT id, name, sex, birth_date, father_height_cm, mother_height_cm, breastfed
         FROM growth_children WHERE deleted_at IS NULL ORDER BY birth_date, id');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function growth_storage_children_deleted()
{
    $stmt = growth_sqlite_pdo()->query(
        'SELECT id, name, sex, birth_date, father_height_cm, mother_height_cm, breastfed, deleted_at
         FROM growth_children WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function growth_storage_child($id)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'SELECT id, name, sex, birth_date, father_height_cm, mother_height_cm, breastfed
         FROM growth_children WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function growth_storage_child_deleted_at($id)
{
    $stmt = growth_sqlite_pdo()->prepare('SELECT deleted_at FROM growth_children WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && $row['deleted_at'] !== null) ? $row['deleted_at'] : null;
}

function growth_storage_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $pdo = growth_sqlite_pdo();
    $stmt = $pdo->prepare('SELECT id FROM growth_children WHERE name = ? AND deleted_at IS NULL');
    $stmt->execute([$name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO growth_children (name, sex, birth_date, father_height_cm, mother_height_cm, breastfed)
         VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $sex, $born, $fatherCm, $motherCm, $breastfed]);
    return (int)$pdo->lastInsertId();
}

function growth_storage_child_update($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_children SET name = ?, sex = ?, birth_date = ?,
         father_height_cm = ?, mother_height_cm = ?, breastfed = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$name, $sex, $born, $fatherCm, $motherCm, $breastfed, $id]);
    return $stmt->rowCount() > 0;
}

function growth_storage_child_delete($id, $when)
{
    $stmt = growth_sqlite_pdo()->prepare('UPDATE growth_children SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$when, $id]);
    return $stmt->rowCount() > 0;
}

function growth_storage_child_restore($id)
{
    $stmt = growth_sqlite_pdo()->prepare('UPDATE growth_children SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/* ------------------------------------------------------------ measurements */

function growth_storage_measurement_row($row)
{
    $row['height_cm'] = ($row['height_cm'] === null) ? null : (float)$row['height_cm'];
    $row['weight_kg'] = ($row['weight_kg'] === null) ? null : (float)$row['weight_kg'];
    return $row;
}

function growth_storage_measurements($childId)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'SELECT id, date, height_cm, weight_kg, note
         FROM growth_measurements WHERE child_id = ? AND deleted_at IS NULL ORDER BY date');
    $stmt->execute([$childId]);
    return array_map('growth_storage_measurement_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function growth_storage_measurements_deleted($childId)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'SELECT id, date, height_cm, weight_kg, note, deleted_at
         FROM growth_measurements WHERE child_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC');
    $stmt->execute([$childId]);
    return array_map('growth_storage_measurement_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function growth_storage_measurement_save($childId, $date, $heightCm, $weightKg, $note)
{
    $pdo = growth_sqlite_pdo();
    $stmt = $pdo->prepare('SELECT id FROM growth_measurements WHERE child_id = ? AND date = ?');
    $stmt->execute([$childId, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE growth_measurements SET height_cm = ?, weight_kg = ?, note = ?, deleted_at = NULL WHERE id = ?');
        return $stmt->execute([$heightCm, $weightKg, $note, $existing['id']]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO growth_measurements (child_id, date, height_cm, weight_kg, note, deleted_at)
         VALUES (?, ?, ?, ?, ?, NULL)');
    return $stmt->execute([$childId, $date, $heightCm, $weightKg, $note]);
}

/** See growth_storage_measurement_update() in storage/json.inc for the
 *  contract; the unique index on (child_id, date) is what the collision check
 *  here is protecting, so the caller gets false instead of a PDO exception. */
function growth_storage_measurement_update($childId, $id, $date, $heightCm, $weightKg, $note)
{
    $pdo = growth_sqlite_pdo();
    $stmt = $pdo->prepare(
        'SELECT id FROM growth_measurements WHERE child_id = ? AND date = ? AND id <> ?');
    $stmt->execute([$childId, $date, $id]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE growth_measurements SET date = ?, height_cm = ?, weight_kg = ?, note = ?
         WHERE id = ? AND child_id = ? AND deleted_at IS NULL');
    $stmt->execute([$date, $heightCm, $weightKg, $note, $id, $childId]);
    return $stmt->rowCount() > 0;
}

function growth_storage_measurement_delete($childId, $id, $when)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_measurements SET deleted_at = ? WHERE id = ? AND child_id = ? AND deleted_at IS NULL');
    $stmt->execute([$when, $id, $childId]);
    return $stmt->rowCount() > 0;
}

function growth_storage_measurement_restore($childId, $id)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_measurements SET deleted_at = NULL WHERE id = ? AND child_id = ? AND deleted_at IS NOT NULL');
    $stmt->execute([$id, $childId]);
    return $stmt->rowCount() > 0;
}

function growth_storage_measurements_delete_for_child($childId, $when)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_measurements SET deleted_at = ? WHERE child_id = ? AND deleted_at IS NULL');
    return $stmt->execute([$when, $childId]);
}

function growth_storage_measurements_restore_for_child($childId, $when)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_measurements SET deleted_at = NULL WHERE child_id = ? AND deleted_at = ?');
    return $stmt->execute([$childId, $when]);
}
