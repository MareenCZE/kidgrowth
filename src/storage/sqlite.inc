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

        $pdo->exec('CREATE TABLE IF NOT EXISTS growth_deti (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            jmeno TEXT NOT NULL,
            pohlavi TEXT NOT NULL CHECK (pohlavi IN (\'m\', \'z\')),
            datum_narozeni TEXT NOT NULL,
            vyska_otce_cm REAL,
            vyska_matky_cm REAL,
            poradi INTEGER NOT NULL DEFAULT 0,
            kojeno INTEGER NOT NULL DEFAULT 0,
            smazano TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS growth_mereni (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dite_id INTEGER NOT NULL,
            datum TEXT NOT NULL,
            vyska_cm REAL,
            hmotnost_kg REAL,
            poznamka TEXT,
            smazano TEXT
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS dite_datum ON growth_mereni (dite_id, datum)');
    }

    return $pdo;
}

/* ---------------------------------------------------------------- children */

function growth_storage_children()
{
    $stmt = growth_sqlite_pdo()->query(
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, poradi, kojeno
         FROM growth_deti WHERE smazano IS NULL ORDER BY poradi, datum_narozeni');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function growth_storage_children_deleted()
{
    $stmt = growth_sqlite_pdo()->query(
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, poradi, kojeno, smazano
         FROM growth_deti WHERE smazano IS NOT NULL ORDER BY smazano DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function growth_storage_child($id)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, poradi, kojeno
         FROM growth_deti WHERE id = ? AND smazano IS NULL');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function growth_storage_child_deleted_at($id)
{
    $stmt = growth_sqlite_pdo()->prepare('SELECT smazano FROM growth_deti WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && $row['smazano'] !== null) ? $row['smazano'] : null;
}

function growth_storage_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $pdo = growth_sqlite_pdo();
    $stmt = $pdo->prepare('SELECT id FROM growth_deti WHERE jmeno = ? AND smazano IS NULL');
    $stmt->execute([$name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO growth_deti (jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, kojeno)
         VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $sex, $born, $fatherCm, $motherCm, $breastfed]);
    return (int)$pdo->lastInsertId();
}

function growth_storage_child_update($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_deti SET jmeno = ?, pohlavi = ?, datum_narozeni = ?,
         vyska_otce_cm = ?, vyska_matky_cm = ?, kojeno = ? WHERE id = ? AND smazano IS NULL');
    $stmt->execute([$name, $sex, $born, $fatherCm, $motherCm, $breastfed, $id]);
    return $stmt->rowCount() > 0;
}

function growth_storage_child_delete($id, $when)
{
    $stmt = growth_sqlite_pdo()->prepare('UPDATE growth_deti SET smazano = ? WHERE id = ? AND smazano IS NULL');
    $stmt->execute([$when, $id]);
    return $stmt->rowCount() > 0;
}

function growth_storage_child_restore($id)
{
    $stmt = growth_sqlite_pdo()->prepare('UPDATE growth_deti SET smazano = NULL WHERE id = ? AND smazano IS NOT NULL');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/* ------------------------------------------------------------ measurements */

function growth_storage_measurement_row($row)
{
    $row['vyska_cm'] = ($row['vyska_cm'] === null) ? null : (float)$row['vyska_cm'];
    $row['hmotnost_kg'] = ($row['hmotnost_kg'] === null) ? null : (float)$row['hmotnost_kg'];
    return $row;
}

function growth_storage_measurements($childId)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'SELECT id, datum, vyska_cm, hmotnost_kg, poznamka
         FROM growth_mereni WHERE dite_id = ? AND smazano IS NULL ORDER BY datum');
    $stmt->execute([$childId]);
    return array_map('growth_storage_measurement_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function growth_storage_measurements_deleted($childId)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'SELECT id, datum, vyska_cm, hmotnost_kg, poznamka, smazano
         FROM growth_mereni WHERE dite_id = ? AND smazano IS NOT NULL ORDER BY smazano DESC');
    $stmt->execute([$childId]);
    return array_map('growth_storage_measurement_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function growth_storage_measurement_save($childId, $date, $heightCm, $weightKg, $note)
{
    $pdo = growth_sqlite_pdo();
    $stmt = $pdo->prepare('SELECT id FROM growth_mereni WHERE dite_id = ? AND datum = ?');
    $stmt->execute([$childId, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE growth_mereni SET vyska_cm = ?, hmotnost_kg = ?, poznamka = ?, smazano = NULL WHERE id = ?');
        return $stmt->execute([$heightCm, $weightKg, $note, $existing['id']]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO growth_mereni (dite_id, datum, vyska_cm, hmotnost_kg, poznamka, smazano)
         VALUES (?, ?, ?, ?, ?, NULL)');
    return $stmt->execute([$childId, $date, $heightCm, $weightKg, $note]);
}

function growth_storage_measurement_delete($childId, $id, $when)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_mereni SET smazano = ? WHERE id = ? AND dite_id = ? AND smazano IS NULL');
    $stmt->execute([$when, $id, $childId]);
    return $stmt->rowCount() > 0;
}

function growth_storage_measurement_restore($childId, $id)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_mereni SET smazano = NULL WHERE id = ? AND dite_id = ? AND smazano IS NOT NULL');
    $stmt->execute([$id, $childId]);
    return $stmt->rowCount() > 0;
}

function growth_storage_measurements_delete_for_child($childId, $when)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_mereni SET smazano = ? WHERE dite_id = ? AND smazano IS NULL');
    return $stmt->execute([$when, $childId]);
}

function growth_storage_measurements_restore_for_child($childId, $when)
{
    $stmt = growth_sqlite_pdo()->prepare(
        'UPDATE growth_mereni SET smazano = NULL WHERE dite_id = ? AND smazano = ?');
    return $stmt->execute([$childId, $when]);
}
