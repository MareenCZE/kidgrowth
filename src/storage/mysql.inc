<?php

/**
 * MySQL storage backend.
 *
 * Every query that takes a value uses a prepared statement with bound
 * parameters - no string interpolation into SQL anywhere. Load db/schema.sql
 * before first use.
 */

function growth_mysql_link()
{
    static $link = null;

    if ($link === null) {
        global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT;

        /* Calling code here is written against the classic mysqli contract -
         * false and an error string on failure - not the exceptions mysqli
         * throws by default since PHP 8.1. */
        mysqli_report(MYSQLI_REPORT_OFF);

        $link = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
        if (!$link) {
            die("DB Error(0)");
        }
        if (!mysqli_set_charset($link, 'utf8')) {
            die("DB Error(0a)");
        }
    }

    return $link;
}

/* Connect on include: a page that cannot reach the database should fail here
 * rather than half-render and fail later. */
growth_mysql_link();

/* ---------------------------------------------------------------- children */

function growth_storage_children()
{
    $link = growth_mysql_link();
    $result = mysqli_query($link,
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, poradi, kojeno
         FROM growth_deti WHERE smazano IS NULL ORDER BY poradi, datum_narozeni');
    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    return $rows;
}

function growth_storage_children_deleted()
{
    $link = growth_mysql_link();
    $result = mysqli_query($link,
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, poradi, kojeno, smazano
         FROM growth_deti WHERE smazano IS NOT NULL ORDER BY smazano DESC');
    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    return $rows;
}

function growth_storage_child($id)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'SELECT id, jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, poradi, kojeno
         FROM growth_deti WHERE id = ? AND smazano IS NULL');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function growth_storage_child_deleted_at($id)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'SELECT smazano FROM growth_deti WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return ($row && $row['smazano'] !== null) ? $row['smazano'] : null;
}

function growth_storage_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'SELECT id FROM growth_deti WHERE jmeno = ? AND smazano IS NULL');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = mysqli_prepare($link,
        'INSERT INTO growth_deti (jmeno, pohlavi, datum_narozeni, vyska_otce_cm, vyska_matky_cm, kojeno)
         VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'sssddi', $name, $sex, $born, $fatherCm, $motherCm, $breastfed);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($link);
    mysqli_stmt_close($stmt);
    return (int)$id;
}

function growth_storage_child_update($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'UPDATE growth_deti SET jmeno = ?, pohlavi = ?, datum_narozeni = ?,
         vyska_otce_cm = ?, vyska_matky_cm = ?, kojeno = ? WHERE id = ? AND smazano IS NULL');
    mysqli_stmt_bind_param($stmt, 'sssddii', $name, $sex, $born, $fatherCm, $motherCm, $breastfed, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_child_delete($id, $when)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'UPDATE growth_deti SET smazano = ? WHERE id = ? AND smazano IS NULL');
    mysqli_stmt_bind_param($stmt, 'si', $when, $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

function growth_storage_child_restore($id)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'UPDATE growth_deti SET smazano = NULL WHERE id = ? AND smazano IS NOT NULL');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

/* ------------------------------------------------------------ measurements */

function growth_storage_measurements($childId)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'SELECT id, datum, vyska_cm, hmotnost_kg, poznamka
         FROM growth_mereni WHERE dite_id = ? AND smazano IS NULL ORDER BY datum');
    mysqli_stmt_bind_param($stmt, 'i', $childId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        /* Keep NULL as NULL rather than letting it become 0.0 through a cast.
           A missing height is not a height of zero, and every chart, velocity
           and projection downstream depends on being able to tell them apart. */
        $row['vyska_cm'] = ($row['vyska_cm'] === null) ? null : (float)$row['vyska_cm'];
        $row['hmotnost_kg'] = ($row['hmotnost_kg'] === null) ? null : (float)$row['hmotnost_kg'];
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function growth_storage_measurements_deleted($childId)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'SELECT id, datum, vyska_cm, hmotnost_kg, poznamka, smazano
         FROM growth_mereni WHERE dite_id = ? AND smazano IS NOT NULL ORDER BY smazano DESC');
    mysqli_stmt_bind_param($stmt, 'i', $childId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['vyska_cm'] = ($row['vyska_cm'] === null) ? null : (float)$row['vyska_cm'];
        $row['hmotnost_kg'] = ($row['hmotnost_kg'] === null) ? null : (float)$row['hmotnost_kg'];
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Inserts, updates, or revives the measurement for one child on one date -
 * regardless of the existing row's soft-delete state.
 */
function growth_storage_measurement_save($childId, $date, $heightCm, $weightKg, $note)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'INSERT INTO growth_mereni (dite_id, datum, vyska_cm, hmotnost_kg, poznamka, smazano)
         VALUES (?, ?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE vyska_cm = VALUES(vyska_cm),
                                 hmotnost_kg = VALUES(hmotnost_kg),
                                 poznamka = VALUES(poznamka),
                                 smazano = NULL');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isdds', $childId, $date, $heightCm, $weightKg, $note);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_measurement_delete($childId, $id, $when)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'UPDATE growth_mereni SET smazano = ? WHERE id = ? AND dite_id = ? AND smazano IS NULL');
    mysqli_stmt_bind_param($stmt, 'sii', $when, $id, $childId);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

function growth_storage_measurement_restore($childId, $id)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'UPDATE growth_mereni SET smazano = NULL WHERE id = ? AND dite_id = ? AND smazano IS NOT NULL');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $childId);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

function growth_storage_measurements_delete_for_child($childId, $when)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'UPDATE growth_mereni SET smazano = ? WHERE dite_id = ? AND smazano IS NULL');
    mysqli_stmt_bind_param($stmt, 'si', $when, $childId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_measurements_restore_for_child($childId, $when)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'UPDATE growth_mereni SET smazano = NULL WHERE dite_id = ? AND smazano = ?');
    mysqli_stmt_bind_param($stmt, 'is', $childId, $when);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
