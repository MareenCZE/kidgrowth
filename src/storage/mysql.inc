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

/**
 * Prepares a statement, or stops with an explanation.
 *
 * mysqli_prepare() returns false when a statement cannot be prepared, and
 * every caller below immediately passes the result to
 * mysqli_stmt_bind_param(). Handing false to that is a TypeError, and a
 * TypeError with display_errors off -- which is every sane production server
 * -- is a blank white page.
 *
 * That is exactly what a fresh install with no tables produced: the import
 * page returned nothing at all and said nothing about why. The cause was one
 * sentence long, and MySQL had been saying it the whole time: Table
 * 'x.growth_children' doesn't exist.
 */
function growth_mysql_prepare($link, $sql)
{
    $stmt = mysqli_prepare($link, $sql);
    if ($stmt === false) {
        growth_mysql_fail(mysqli_error($link), $sql);
    }
    return $stmt;
}

/**
 * The same guarantee for reads that do not use a prepared statement.
 *
 * These were worse than the prepares, not better: mysqli_query() returning
 * false made growth_storage_children() skip its while loop and return an
 * empty array, so a database with no tables at all rendered as an application
 * with no children in it -- indistinguishable from a correct empty install.
 */
function growth_mysql_query_checked($link, $sql)
{
    $result = mysqli_query($link, $sql);
    if ($result === false) {
        growth_mysql_fail(mysqli_error($link), $sql);
    }
    return $result;
}

/**
 * Stops the request with something a person can act on.
 *
 * A missing table is named as what it actually is -- an installation that was
 * never finished -- because that is the only way it happens, and the page that
 * finishes it is one link away.
 */
function growth_mysql_fail($error, $sql)
{
    $missingTable = stripos($error, "doesn't exist") !== false
        || stripos($error, 'does not exist') !== false;

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database error: $error\n  while running: $sql\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Database error</title></head><body style="font:16px/1.6 system-ui,sans-serif;'
        . 'max-width:40em;margin:3em auto;padding:0 1em">';

    if ($missingTable) {
        echo '<h1>The database has no tables yet</h1>'
            . '<p>KidGrowth is configured to use MySQL and can reach the database, but the '
            . 'tables it needs are not there, so this installation was never finished.</p>'
            . '<p>Open <a href="install.php">install.php</a> and use the storage section to '
            . 'create them, or load <code>db/schema.sql</code> by hand.</p>';
    } else {
        echo '<h1>Database error</h1><p>The database refused a query this page needed.</p>';
    }

    echo '<p style="color:#666"><strong>MySQL said:</strong> '
        . htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

/* ---------------------------------------------------------------- children */

function growth_storage_children()
{
    $link = growth_mysql_link();
    $result = growth_mysql_query_checked($link,
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
    $result = growth_mysql_query_checked($link,
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
    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link, 'SELECT deleted_at FROM growth_children WHERE id = ?');
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
    $stmt = growth_mysql_prepare($link, 'SELECT id FROM growth_children WHERE name = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link, 'UPDATE growth_children SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 'si', $when, $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

function growth_storage_child_restore($id)
{
    $link = growth_mysql_link();
    $stmt = growth_mysql_prepare($link, 'UPDATE growth_children SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
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
    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link,
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

/** See growth_storage_measurement_update() in storage/json.inc for the
 *  contract. The row is looked up before the UPDATE rather than trusting
 *  affected_rows, which MySQL reports as 0 when someone saves a row without
 *  actually changing any of its values. */
function growth_storage_measurement_update($childId, $id, $date, $heightCm, $weightKg, $note)
{
    $link = growth_mysql_link();

    $stmt = growth_mysql_prepare($link,
        'SELECT id FROM growth_measurements
         WHERE child_id = ? AND (id = ? AND deleted_at IS NULL OR date = ? AND id <> ?)');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'iisi', $childId, $id, $date, $id);
    mysqli_stmt_execute($stmt);
    $found = array();
    mysqli_stmt_bind_result($stmt, $foundId);
    while (mysqli_stmt_fetch($stmt)) {
        $found[] = (int)$foundId;
    }
    mysqli_stmt_close($stmt);
    if (!in_array($id, $found, true) || count($found) > 1) {
        return false;
    }

    $stmt = growth_mysql_prepare($link,
        'UPDATE growth_measurements SET date = ?, height_cm = ?, weight_kg = ?, note = ?
         WHERE id = ? AND child_id = ? AND deleted_at IS NULL');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'sddsii', $date, $heightCm, $weightKg, $note, $id, $childId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_measurement_delete($childId, $id, $when)
{
    $link = growth_mysql_link();
    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link,
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
    $stmt = growth_mysql_prepare($link,
        'UPDATE growth_measurements SET deleted_at = ? WHERE child_id = ? AND deleted_at IS NULL');
    mysqli_stmt_bind_param($stmt, 'si', $when, $childId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function growth_storage_measurements_restore_for_child($childId, $when)
{
    $link = growth_mysql_link();
    $stmt = growth_mysql_prepare($link,
        'UPDATE growth_measurements SET deleted_at = NULL WHERE child_id = ? AND deleted_at = ?');
    mysqli_stmt_bind_param($stmt, 'is', $childId, $when);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
