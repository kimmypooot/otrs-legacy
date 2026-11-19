-- Migration: training reschedule tracking
-- Run once. Safe to re-run: IF NOT EXISTS guards.

ALTER TABLE `otrs_trainings`
    ADD COLUMN IF NOT EXISTS `reschedule_reason`  VARCHAR(255) DEFAULT NULL
        COMMENT 'Why this training was rescheduled',
    ADD COLUMN IF NOT EXISTS `rescheduled_to_id`  INT(12)      DEFAULT NULL
        COMMENT 'FK → otrs_trainings.id of the replacement training';

CREATE TABLE IF NOT EXISTS `otrs_participant_transfers` (
    `id`               INT(11)  NOT NULL AUTO_INCREMENT,
    `registration_id`  INT(12)  NOT NULL COMMENT 'FK → otrs_registrations.id',
    `from_training_id` INT(12)  NOT NULL,
    `to_training_id`   INT(12)  NOT NULL,
    `transferred_at`   DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_from_training` (`from_training_id`),
    KEY `idx_to_training`   (`to_training_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log of participant transfers between trainings.';
