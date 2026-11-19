-- Migration: explicit non-consecutive training dates
-- Run once. Safe to re-run: IF NOT EXISTS guard.
-- Zero rows for a training_id = legacy/simple start_date..end_date range mode (unchanged behavior).
-- One or more rows = custom (non-consecutive) mode is authoritative for that training's days.

CREATE TABLE IF NOT EXISTS `otrs_training_dates` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `training_id`    INT(12)      NOT NULL,
    `training_date`  DATE         NOT NULL,
    `day_number`     TINYINT(4)   NOT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_training_date` (`training_id`, `training_date`),
    KEY `idx_training_id` (`training_id`),
    CONSTRAINT `fk_training_dates_training` FOREIGN KEY (`training_id`)
        REFERENCES `otrs_trainings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicit non-consecutive training dates. Empty for a training = use start_date..end_date range.';
