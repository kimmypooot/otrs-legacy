-- add_ccs_module.sql
-- Centralized Certificate System (CCS) — schema tables.
-- All DDL is IF NOT EXISTS — safe to run on a live database.
-- Run this file FIRST, then migrate_to_ccs.sql.

-- ── 1. Unified certificate registry ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otrs_ccs_certificates` (
  `id`                 int(12)       NOT NULL AUTO_INCREMENT,
  `certificate_number` varchar(64)   NOT NULL,

  -- Classification
  `source`             enum('public_offered','agency','internal','appearance')
                       NOT NULL COMMENT 'Workflow that produced this cert',
  `category`           enum('completion','certification','participation','recognition','appearance')
                       NOT NULL COMMENT 'Certificate category',

  -- Participant (either FK or free-text; never both null)
  `participant_id`     int(12)       DEFAULT NULL
                       COMMENT 'FK → otrs_participants.id; NULL for agency/appearance certs',
  `participant_name`   varchar(255)  NOT NULL DEFAULT ''
                       COMMENT 'Denormalized; populated from participant record when available',

  -- Training linkage (FK or stand-alone)
  `training_id`        int(12)       DEFAULT NULL
                       COMMENT 'FK → otrs_trainings.id; NULL for standalone certs',
  `training_title`     varchar(512)  NOT NULL DEFAULT ''
                       COMMENT 'Denormalized from otrs_trainings.name or supplied directly',
  `venue`              varchar(255)  NOT NULL DEFAULT '',
  `start_date`         date          DEFAULT NULL,
  `end_date`           date          DEFAULT NULL,

  -- Training meta (denormalized for cert text)
  `no_hrs`             int(5)        DEFAULT NULL,
  `training_type`      varchar(64)   DEFAULT NULL
                       COMMENT 'Technical|Foundation|Leadership and Management',
  `training_mode`      varchar(32)   DEFAULT NULL
                       COMMENT 'face-to-face|online|hybrid|blended',
  `training_level`     varchar(64)   DEFAULT NULL
                       COMMENT 'Regular|Technical|etc.',

  -- Batch linkage
  `batch_id`           int(12)       DEFAULT NULL
                       COMMENT 'FK → otrs_agency_batches OR otrs_ccs_appearance_batches; NULL for registered certs',

  -- File assets
  `certificate_file`   varchar(1024) NOT NULL DEFAULT '',
  `qr_code`            varchar(255)  NOT NULL DEFAULT '',
  `qr_code_data`       varchar(512)  NOT NULL DEFAULT ''
                       COMMENT 'Verification URL stored in QR',
  `verification_token` char(64)      DEFAULT NULL
                       COMMENT '32-byte hex token; separate from cert number',

  -- Lifecycle
  `status`             enum('active','reissued','voided','archived')
                       NOT NULL DEFAULT 'active',

  -- Download / claim tracking
  `is_claimed`         tinyint(1)    NOT NULL DEFAULT 0,
  `claim_source`       varchar(32)   NOT NULL DEFAULT 'admin'
                       COMMENT 'admin|public',
  `download_count`     int(4)        NOT NULL DEFAULT 0,
  `downloaded_at`      datetime      DEFAULT NULL,
  `emailed_at`         datetime      DEFAULT NULL,
  `email_status`       varchar(16)   DEFAULT NULL
                       COMMENT 'sent|failed',

  -- Audit
  `issued_at`          datetime      NOT NULL DEFAULT current_timestamp(),
  `issued_by`          varchar(128)  NOT NULL DEFAULT 'OTRS System',
  `generated_at`       datetime      DEFAULT NULL,
  `generated_by`       int(12)       DEFAULT NULL
                       COMMENT 'FK → users_cscro8.id',

  `notes`              text          DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ccs_cert_number` (`certificate_number`),
  KEY `idx_ccs_source`         (`source`),
  KEY `idx_ccs_category`       (`category`),
  KEY `idx_ccs_participant_id` (`participant_id`),
  KEY `idx_ccs_training_id`    (`training_id`),
  KEY `idx_ccs_batch_id`       (`batch_id`),
  KEY `idx_ccs_status`         (`status`),
  KEY `idx_ccs_issued_at`      (`issued_at`),
  KEY `idx_ccs_verification`   (`verification_token`),
  KEY `idx_ccs_search`         (`participant_name`(64), `training_title`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Unified certificate registry — all cert types from all sources.';

-- ── 2. Issuance audit log ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otrs_ccs_issuance_log` (
  `id`             bigint(20)    NOT NULL AUTO_INCREMENT,
  `certificate_id` int(12)       NOT NULL
                   COMMENT 'FK → otrs_ccs_certificates.id',
  `action`         enum('issued','reissued','voided','archived','emailed',
                        'downloaded','verified','migrated')
                   NOT NULL,
  `old_file`       varchar(1024) DEFAULT NULL
                   COMMENT 'Previous PDF path before re-issue',
  `new_file`       varchar(1024) DEFAULT NULL
                   COMMENT 'New PDF path after re-issue',
  `actor`          varchar(128)  NOT NULL DEFAULT 'system'
                   COMMENT 'Session username or "system"',
  `actor_id`       int(12)       DEFAULT NULL
                   COMMENT 'FK → users_cscro8.id; NULL for public/system actions',
  `ip_address`     varchar(45)   NOT NULL DEFAULT '',
  `notes`          varchar(512)  DEFAULT NULL,
  `created_at`     datetime      NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_ccs_log_cert`    (`certificate_id`),
  KEY `idx_ccs_log_action`  (`action`),
  KEY `idx_ccs_log_created` (`created_at`),
  CONSTRAINT `fk_ccs_log_cert`
    FOREIGN KEY (`certificate_id`)
    REFERENCES `otrs_ccs_certificates` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Complete audit trail for every lifecycle event on a CCS certificate.';

-- ── 3. Appearance batch header ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otrs_ccs_appearance_batches` (
  `id`             int(12)      NOT NULL AUTO_INCREMENT,
  `activity_title` varchar(255) NOT NULL,
  `activity_type`  varchar(64)  NOT NULL DEFAULT ''
                   COMMENT 'seminar|workshop|meeting|orientation|consultation|other',
  `venue`          varchar(255) NOT NULL DEFAULT '',
  `start_date`     date         NOT NULL,
  `end_date`       date         NOT NULL,
  `reference_no`   varchar(64)  NOT NULL DEFAULT ''
                   COMMENT 'Optional office reference number (memo no., etc.)',
  `created_by`     int(12)      DEFAULT NULL
                   COMMENT 'FK → users_cscro8.id',
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_app_batch_date` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='One batch per Certificate of Appearance generation run.';
