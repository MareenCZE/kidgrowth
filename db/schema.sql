-- Schema for the growth tracker's MySQL backend.
--
-- Both parent heights are nullable: they feed the target-height projection
-- (growth_target_height() in src/growth.inc) but are not required to record a
-- measurement, and not every user will know them or want to enter them.
--
-- Height and weight on growth_mereni are both nullable on purpose: a visit
-- often produces only one of the two, and storing a missing measurement as 0
-- would corrupt every chart and trend that reads it - see the NULL handling
-- in growth_measurements() (src/data.inc).
--
-- The unique key on (dite_id, datum) makes saving the same date twice an
-- update - or, for a soft-deleted row, a revival - rather than a collision;
-- see growth_storage_measurement_save() in src/storage/mysql.inc.
--
-- `smazano` is soft delete: a timestamp instead of NULL means deleted, never
-- actually removed. Every read in src/storage/mysql.inc filters on it - see
-- src/storage.inc for why the interface, not each backend, owns the cascade
-- between a deleted child and its measurements.

CREATE TABLE `growth_deti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `jmeno` varchar(60) NOT NULL,
  `pohlavi` enum('m','z') NOT NULL,
  `datum_narozeni` date NOT NULL,
  `vyska_otce_cm` decimal(4,1) DEFAULT NULL,
  `vyska_matky_cm` decimal(4,1) DEFAULT NULL,
  `poradi` int(11) NOT NULL DEFAULT 0,
  `kojeno` tinyint(1) NOT NULL DEFAULT 0,
  `smazano` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `growth_mereni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dite_id` int(11) NOT NULL,
  `datum` date NOT NULL,
  `vyska_cm` decimal(4,1) DEFAULT NULL,
  `hmotnost_kg` decimal(5,2) DEFAULT NULL,
  `poznamka` varchar(255) DEFAULT NULL,
  `smazano` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dite_datum` (`dite_id`,`datum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
