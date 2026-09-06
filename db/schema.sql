-- Schema for the growth tracker's MySQL backend.
--
-- Both parent heights are nullable: they feed the target-height projection
-- (growth_target_height() in src/growth.inc) but are not required to record a
-- measurement, and not every user will know them or want to enter them.
--
-- Height and weight on growth_measurements are both nullable on purpose: a visit
-- often produces only one of the two, and storing a missing measurement as 0
-- would corrupt every chart and trend that reads it - see the NULL handling
-- in growth_measurements() (src/data.inc).
--
-- The unique key on (child_id, date) makes saving the same date twice an
-- update - or, for a soft-deleted row, a revival - rather than a collision;
-- see growth_storage_measurement_save() in src/storage/mysql.inc.
--
-- `deleted_at` is soft delete: a timestamp instead of NULL means deleted, never
-- actually removed. Every read in src/storage/mysql.inc filters on it - see
-- src/storage.inc for why the interface, not each backend, owns the cascade
-- between a deleted child and its measurements.

CREATE TABLE `growth_children` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `sex` enum('m','f') NOT NULL,
  `birth_date` date NOT NULL,
  `father_height_cm` decimal(4,1) DEFAULT NULL,
  `mother_height_cm` decimal(4,1) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `breastfed` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `growth_measurements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `child_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `height_cm` decimal(4,1) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `child_date` (`child_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
