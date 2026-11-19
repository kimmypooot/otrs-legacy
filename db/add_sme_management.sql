-- ============================================================
-- OTRS SME Management — Schema Migration
-- Run once on the production database.
-- Generated: 2026-06-01
-- ============================================================

-- ── 1. Global SME profile registry ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otrs_smes` (
  `id`          int(12)       NOT NULL AUTO_INCREMENT,
  `name`        varchar(128)  NOT NULL,
  `designation` varchar(128)  NOT NULL DEFAULT '',
  `email`       varchar(128)  NOT NULL DEFAULT '',
  `mobile_no`   varchar(20)   NOT NULL DEFAULT '',
  `agency`      varchar(255)  NOT NULL DEFAULT '',
  `expertise`   varchar(512)  NOT NULL DEFAULT '',
  `bio`         text          NOT NULL DEFAULT '',
  `status`      enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  datetime      NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime      DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_name`   (`name`(100)),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Global SME/facilitator profile registry.';

-- ── 2. Day-based SME assignment per training ─────────────────────────────
--    Links an otrs_training_smes row to specific training day numbers.
--    A row with no entries here = SME applies to all days (backward-compat).
CREATE TABLE IF NOT EXISTS `otrs_sme_training_days` (
  `id`               int(12)            NOT NULL AUTO_INCREMENT,
  `training_sme_id`  int(12)            NOT NULL COMMENT 'FK → otrs_training_smes.id',
  `day_number`       tinyint(3) UNSIGNED NOT NULL COMMENT '1..N',
  `training_date`    date               DEFAULT NULL COMMENT 'Actual calendar date for that day',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sme_day`          (`training_sme_id`, `day_number`),
  KEY       `idx_training_sme_id`  (`training_sme_id`),
  CONSTRAINT `fk_sme_days_tsme`
    FOREIGN KEY (`training_sme_id`) REFERENCES `otrs_training_smes`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Day-level SME assignment. No rows = assigned to all days.';

-- ── 3. Per-participant, per-day attendance records ───────────────────────
CREATE TABLE IF NOT EXISTS `otrs_attendance` (
  `id`               int(12)            NOT NULL AUTO_INCREMENT,
  `registration_id`  int(12)            NOT NULL COMMENT 'FK → otrs_registrations.id',
  `participant_id`   int(12)            NOT NULL COMMENT 'denorm for quick lookup',
  `training_id`      int(12)            NOT NULL COMMENT 'denorm',
  `day_number`       tinyint(3) UNSIGNED NOT NULL COMMENT '1..N',
  `attendance_date`  date               DEFAULT NULL,
  `status`           enum('present','absent','excused') NOT NULL DEFAULT 'present',
  `marked_by`        int(12)            DEFAULT NULL COMMENT 'IMIS user ID',
  `marked_at`        datetime           DEFAULT NULL,
  `notes`            varchar(255)       DEFAULT NULL,
  `created_at`       datetime           NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime           DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reg_day`       (`registration_id`, `day_number`),
  KEY `idx_training_id`         (`training_id`),
  KEY `idx_participant_id`      (`participant_id`),
  KEY `idx_status`              (`status`),
  CONSTRAINT `fk_attend_reg`
    FOREIGN KEY (`registration_id`) REFERENCES `otrs_registrations`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Participant attendance per training day.';

-- ── 4. Link otrs_training_smes → master profile (nullable) ───────────────
--    Existing rows keep sme_id = NULL (ad-hoc entries).
ALTER TABLE `otrs_training_smes`
  ADD COLUMN IF NOT EXISTS `sme_id` int(12) DEFAULT NULL
    COMMENT 'FK → otrs_smes.id (NULL = ad-hoc entry, not from master registry)';

ALTER TABLE `otrs_training_smes`
  ADD KEY IF NOT EXISTS `idx_sme_id` (`sme_id`);

-- Add FK only if not already present
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'otrs_training_smes'
    AND CONSTRAINT_NAME = 'fk_training_sme_profile'
);

SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `otrs_training_smes`
     ADD CONSTRAINT `fk_training_sme_profile`
       FOREIGN KEY (`sme_id`) REFERENCES `otrs_smes`(`id`)
       ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
