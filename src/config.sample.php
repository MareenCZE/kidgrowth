<?php
/*
 * Application configuration. Copy this file to config.php (which is
 * gitignored) and adjust it - config.php is never committed.
 */

/**
 * Which storage backend holds children and measurements: 'json', 'sqlite' or
 * 'mysql'. See README.md's "Install" section and src/storage.inc for what
 * each one needs.
 *
 * 'json' is the default: no database of any kind, just a writable directory.
 * Fine for a family's own data - a handful of writes a month, comfortably
 * under a megabyte even after years of measurements.
 */
$STORAGE_BACKEND = 'json';

/* Used only when $STORAGE_BACKEND is 'json'. Keep this outside your web
 * server's document root - the default here (storage/, a sibling of src/)
 * already is, as long as your document root points at src/ as the README
 * describes. storage/.htaccess denies access to it too, as a second layer for
 * a document root that is not set up that way. */
$JSON_STORAGE_PATH = __DIR__ . '/../storage/growth.json';

/* Used only when $STORAGE_BACKEND is 'sqlite'. Same placement reasoning as
 * above. Requires the pdo_sqlite extension. */
$SQLITE_STORAGE_PATH = __DIR__ . '/../storage/growth.sqlite';

/* Used only when $STORAGE_BACKEND is 'mysql'. Load db/schema.sql into this
 * database before first use. */
$DB_HOST = 'localhost';
$DB_USER = 'rust';
$DB_PASS = 'change-me';
$DB_NAME = 'rust';
$DB_PORT = 3306;
