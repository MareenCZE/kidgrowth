<?php

/**
 * Holds db/schema.sql and src/storage/mysql_schema.inc to each other.
 *
 * There are two copies of the schema on purpose: one that the installer can
 * reach after somebody uploaded only src/, and one that a person can paste
 * into phpMyAdmin. Two copies of anything drift, so this is the thing that
 * stops them -- a schema change made in one place and not the other fails
 * here rather than at somebody's first INSERT.
 */

require_once __DIR__ . '/../src/storage/mysql_schema.inc';

/** Whitespace and trailing semicolons are formatting, not schema. */
function schema_test_normalise(string $sql): string
{
    $sql = preg_replace('~/\*.*?\*/~s', '', $sql);          /* block comments */
    $sql = preg_replace('~^\s*--.*$~m', '', $sql);          /* line comments */
    $sql = preg_replace('~\s+~', ' ', $sql);
    return trim(str_replace(' ;', ';', $sql));
}

function test_schema_file_matches_the_installer_statements()
{
    $path = __DIR__ . '/../db/schema.sql';
    assert_true(is_file($path), 'db/schema.sql exists');
    if (!is_file($path)) {
        return;
    }

    $fromFile = schema_test_normalise((string)file_get_contents($path));
    $fromCode = schema_test_normalise(implode(";\n", growth_mysql_schema_statements()) . ';');

    assert_equals($fromCode, $fromFile,
        'db/schema.sql and growth_mysql_schema_statements() must say the same thing');
}

function test_schema_statements_cover_every_table_the_backend_uses()
{
    $sql = implode("\n", growth_mysql_schema_statements());
    foreach (growth_mysql_tables() as $table) {
        assert_true(
            strpos($sql, '`' . $table . '`') !== false,
            "the schema creates $table"
        );
    }
}

function test_schema_statements_carry_no_trailing_semicolon()
{
    /* They are fed to mysqli_query() one at a time by the installer, which
       takes a single statement and no terminator. */
    foreach (growth_mysql_schema_statements() as $i => $statement) {
        assert_true(
            substr(rtrim($statement), -1) !== ';',
            "statement $i ends without a semicolon"
        );
    }
}
