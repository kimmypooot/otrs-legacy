-- Migration: training suspended days log
-- Run once. Safe to re-run: IF NOT EXISTS guard.

CREATE TABLE IF NOT EXISTS `otrs_training_suspensions` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `training_id`    INT(12)      NOT NULL,
    `suspended_date` DATE         NOT NULL,
    `reason`         VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`     DATETIME     NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_training_date` (`training_id`, `suspended_date`),
    CONSTRAINT `fk_susp_training` FOREIGN KEY (`training_id`)
        REFERENCES `otrs_trainings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Suspended training days (holidays, weather, etc.) per training.';
