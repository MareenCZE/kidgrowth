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
        'SELECT id, name, sex, birth_date, father_height_cm, mother_height_cm, position, breastfed
         FROM growth_children WHERE deleted_at IS NULL ORDER BY position, birth_date');
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
        'SELECT id, name, sex, birth_date, father_height_cm, mother_height_cm, position, breastfed, deleted_at
         FROM growth_children WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC');
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
        'SELECT id, name, sex, birth_date, father_height_cm, mother_height_cm, position, breastfed
         FROM growth_children WHERE id = ? AND deleted_at IS NULL');
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
    $stmt = mysqli_prepare($link, 'SELECT deleted_at FROM growth_children WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return ($row && $row['deleted_at'] !== null) ? $row['deleted_at'] : null;
}

function growth_storage_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'SELECT id FROM growth_children WHERE name = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = mysqli_prepare($link,
        'INSERT INTO growth_children (name, sex, birth_date, father_height_cm, mother_height_cm, breastfed)
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
        'UPDATE growth_children SET name = ?, sex = ?, birth_date = ?,
         father_height_cm = ?, mother_height_cm = ?, breastfed = ? WHERE id = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 'sssddii', $name, $sex, $born, $fatherCm, $motherCm, $breastfed, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_child_delete($id, $when)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'UPDATE growth_children SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 'si', $when, $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

function growth_storage_child_restore($id)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link, 'UPDATE growth_children SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
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
        'SELECT id, date, height_cm, weight_kg, note
         FROM growth_measurements WHERE child_id = ? AND deleted_at IS NULL ORDER BY date');
    mysqli_stmt_bind_param($stmt, 'i', $childId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        /* Keep NULL as NULL rather than letting it become 0.0 through a cast.
           A missing height is not a height of zero, and every chart, velocity
           and projection downstream depends on being able to tell them apart. */
        $row['height_cm'] = ($row['height_cm'] === null) ? null : (float)$row['height_cm'];
        $row['weight_kg'] = ($row['weight_kg'] === null) ? null : (float)$row['weight_kg'];
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function growth_storage_measurements_deleted($childId)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'SELECT id, date, height_cm, weight_kg, note, deleted_at
         FROM growth_measurements WHERE child_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC');
    mysqli_stmt_bind_param($stmt, 'i', $childId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = array();
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['height_cm'] = ($row['height_cm'] === null) ? null : (float)$row['height_cm'];
        $row['weight_kg'] = ($row['weight_kg'] === null) ? null : (float)$row['weight_kg'];
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
        'INSERT INTO growth_measurements (child_id, date, height_cm, weight_kg, note, deleted_at)
         VALUES (?, ?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE height_cm = VALUES(height_cm),
                                 weight_kg = VALUES(weight_kg),
                                 note = VALUES(note),
                                 deleted_at = NULL');
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
        'UPDATE growth_measurements SET deleted_at = ? WHERE id = ? AND child_id = ? AND deleted_at IS NULL');
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
        'UPDATE growth_measurements SET deleted_at = NULL WHERE id = ? AND child_id = ? AND deleted_at IS NOT NULL');
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
        'UPDATE growth_measurements SET deleted_at = ? WHERE child_id = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 'si', $when, $childId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_measurements_restore_for_child($childId, $when)
{
    $link = growth_mysql_link();
    $stmt = mysqli_prepare($link,
        'UPDATE growth_measurements SET deleted_at = NULL WHERE child_id = ? AND deleted_at = ?');
    mysqli_stmt_bind_param($stmt, 'is', $childId, $when);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
