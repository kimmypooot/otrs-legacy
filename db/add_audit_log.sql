-- otrs_audit_log: general-purpose HRD activity audit trail.
-- Run once. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `otrs_audit_log` (
    `id`               INT           NOT NULL AUTO_INCREMENT,
    `action`           VARCHAR(60)   NOT NULL,
    `entity`           VARCHAR(50)   NOT NULL,
    `entity_id`        INT           DEFAULT NULL,
    `training_id`      INT           DEFAULT NULL,
    `participant_name` VARCHAR(255)  DEFAULT NULL,
    `details`          TEXT          DEFAULT NULL,
    `performed_by`     VARCHAR(100)  DEFAULT NULL,
    `performed_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address`       VARCHAR(45)   DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_performed_at` (`performed_at`),
    INDEX `idx_training_id`  (`training_id`),
    INDEX `idx_action`       (`action`),
    INDEX `idx_entity`       (`entity`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
