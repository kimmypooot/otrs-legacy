-- otrs_waitlist: holds participants queued when a training has no available slots.
-- Run once. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `otrs_waitlist` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `participant_id` INT          NOT NULL,
    `training_id`    INT          NOT NULL,
    `position`       INT          NOT NULL DEFAULT 1,
    `status`         ENUM('waiting','promoted','withdrawn') NOT NULL DEFAULT 'waiting',
    `added_by`       VARCHAR(100) DEFAULT NULL,
    `added_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `promoted_at`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_participant_training` (`participant_id`, `training_id`),
    INDEX `idx_training_status` (`training_id`, `status`),
    INDEX `idx_position`        (`training_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
