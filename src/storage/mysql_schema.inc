<?php

/**
 * The MySQL schema, as statements rather than as a file.
 *
 * It lives here, and separately from mysql.inc, for two reasons.
 *
 * Separately from mysql.inc, because that file opens a database connection
 * the moment it is included -- deliberately, so a page that cannot reach the
 * database fails at the top rather than half-rendered. The installer needs the
 * schema *before* there is a connection to open, so it cannot include the
 * backend to get it.
 *
 * Under src/, because db/schema.sql is not under src/, and somebody who
 * installed by uploading src/ over FTP does not have the db/ directory at all.
 * That is the same reason the reference build moved here: code and data the
 * installation needs cannot live where the installation never goes.
 *
 * db/schema.sql still exists, and still says exactly this, for people who
 * would rather paste SQL into phpMyAdmin than click through a page.
 * tests/schema_test.php holds the two to each other so they cannot drift.
 */

function growth_mysql_schema_statements(): array
{
    return [
        "CREATE TABLE `growth_children` (\n"
        . "  `id` int(11) NOT NULL AUTO_INCREMENT,\n"
        . "  `name` varchar(60) NOT NULL,\n"
        . "  `sex` enum('m','f') NOT NULL,\n"
        . "  `birth_date` date NOT NULL,\n"
        . "  `father_height_cm` decimal(4,1) DEFAULT NULL,\n"
        . "  `mother_height_cm` decimal(4,1) DEFAULT NULL,\n"
        . "  `position` int(11) NOT NULL DEFAULT 0,\n"
        . "  `breastfed` tinyint(1) NOT NULL DEFAULT 0,\n"
        . "  `deleted_at` datetime DEFAULT NULL,\n"
        . "  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

        "CREATE TABLE `growth_measurements` (\n"
        . "  `id` int(11) NOT NULL AUTO_INCREMENT,\n"
        . "  `child_id` int(11) NOT NULL,\n"
        . "  `date` date NOT NULL,\n"
        . "  `height_cm` decimal(4,1) DEFAULT NULL,\n"
        . "  `weight_kg` decimal(5,2) DEFAULT NULL,\n"
        . "  `note` varchar(255) DEFAULT NULL,\n"
        . "  `deleted_at` datetime DEFAULT NULL,\n"
        . "  PRIMARY KEY (`id`),\n"
        . "  UNIQUE KEY `child_date` (`child_id`,`date`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",
    ];
}

/** The tables this application owns, for "is it installed yet?" checks. */
function growth_mysql_tables(): array
{
    return ['growth_children', 'growth_measurements'];
}
