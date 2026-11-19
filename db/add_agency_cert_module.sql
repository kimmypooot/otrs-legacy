-- Agency-Requested Trainings Certificate Module
-- Run once on production. Safe to re-run (all DDL uses IF NOT EXISTS / IF EXISTS).

CREATE TABLE IF NOT EXISTS `otrs_agency_batches` (
  `id`             int(12)      NOT NULL AUTO_INCREMENT,
  `training_title` varchar(255) NOT NULL,
  `venue`          varchar(255) NOT NULL DEFAULT '',
  `start_date`     date         NOT NULL,
  `end_date`       date         NOT NULL,
  `cert_type`      enum('Completion','Certification') NOT NULL DEFAULT 'Completion',
  `created_by`     int(12)      DEFAULT NULL COMMENT 'imis user id',
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='One row per agency-requested certificate generation batch.';

CREATE TABLE IF NOT EXISTS `otrs_agency_cert_records` (
  `id`                 int(12)       NOT NULL AUTO_INCREMENT,
  `batch_id`           int(12)       NOT NULL,
  `participant_name`   varchar(255)  NOT NULL,
  `certificate_number` varchar(64)   NOT NULL,
  `certificate_file`   varchar(512)  DEFAULT NULL,
  `qr_code`            varchar(255)  DEFAULT NULL,
  `qr_code_data`       varchar(512)  DEFAULT NULL,
  `cert_type`          enum('Completion','Certification') NOT NULL DEFAULT 'Completion',
  `issued_at`          datetime      DEFAULT NULL,
  `issued_by`          int(12)       DEFAULT NULL,
  `status`             enum('active','revoked') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE  KEY `uq_cert_number` (`certificate_number`),
  KEY     `idx_batch`  (`batch_id`),
  CONSTRAINT `fk_agency_cert_batch`
      FOREIGN KEY (`batch_id`) REFERENCES `otrs_agency_batches` (`id`)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='One row per generated certificate in an agency batch.';
