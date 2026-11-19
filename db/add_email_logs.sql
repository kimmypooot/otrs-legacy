-- otrs_email_logs: tracks when each bulk email type was last sent per registration.
-- Run once. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `otrs_email_logs` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `registration_id`  INT          NOT NULL,
    `training_id`      INT          NOT NULL,
    `email_type`       ENUM('ack','advisory','zoom_link') NOT NULL,
    `sent_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_by`          VARCHAR(100) DEFAULT NULL,
    `recipient_email`  VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_reg_id`      (`registration_id`),
    INDEX `idx_training_id` (`training_id`),
    INDEX `idx_type_reg`    (`email_type`, `registration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
