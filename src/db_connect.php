<?php
/*
 * Database connection (MySQL). There is no storage interface yet - data.inc
 * calls db() directly - so this is required for every install for now; a
 * zero-setup JSON-file backend is planned (see the README).
 *
 * Credentials live in db_config.php, which is not in git - copy
 * db_config.sample.php to db_config.php and fill in your own.
 */
require_once __DIR__ . '/db_config.php';

function db()
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
db();
