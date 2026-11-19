-- migrate_to_ccs.sql
-- Backfills existing certificate records into otrs_ccs_certificates.
-- Safe to re-run: INSERT IGNORE prevents duplicates (UNIQUE on certificate_number).
-- Run AFTER add_ccs_module.sql.
-- Does NOT alter or delete rows in existing tables.

-- ── 1. Standard OTRS certs → CCS ─────────────────────────────────────────────
INSERT IGNORE INTO `otrs_ccs_certificates`
    (`certificate_number`, `source`, `category`,
     `participant_id`, `participant_name`,
     `training_id`,   `training_title`,
     `venue`,         `start_date`,  `end_date`,
     `training_mode`,
     `certificate_file`, `qr_code`, `qr_code_data`, `verification_token`,
     `status`, `email_status`,
     `issued_at`, `issued_by`,
     `generated_at`, `generated_by`, `batch_id`)
SELECT
    c.`certificate_number`,
    'public_offered'              AS `source`,
    LOWER(IFNULL(c.`cert_type`, 'completion')) AS `category`,
    c.`participant_id`,
    CONCAT_WS(' ',
        p.`fname`,
        NULLIF(CONCAT(LEFT(TRIM(p.`mname`),1),'.'), '.'),
        p.`lname`
    )                             AS `participant_name`,
    c.`training_id`,
    t.`name`                      AS `training_title`,
    t.`location`                  AS `venue`,
    t.`start_date`,
    t.`end_date`,
    t.`mode`                      AS `training_mode`,
    c.`certificate_file`,
    c.`qr_code`,
    c.`qr_code_data`,
    NULL                          AS `verification_token`,
    CASE c.`status`
        WHEN 'revoked'  THEN 'voided'
        WHEN 'archived' THEN 'archived'
        ELSE 'active'
    END                           AS `status`,
    c.`email_status`,
    c.`issued_at`,
    'OTRS System'                 AS `issued_by`,
    c.`issued_at`                 AS `generated_at`,
    NULL                          AS `generated_by`,
    NULL                          AS `batch_id`
FROM `otrs_certificates` c
LEFT JOIN `otrs_participants` p ON p.`id` = c.`participant_id`
LEFT JOIN `otrs_trainings`    t ON t.`id` = c.`training_id`;

-- ── 2. Agency cert records → CCS ─────────────────────────────────────────────
INSERT IGNORE INTO `otrs_ccs_certificates`
    (`certificate_number`, `source`, `category`,
     `participant_id`, `participant_name`,
     `training_id`,   `training_title`,
     `venue`,         `start_date`,  `end_date`,
     `no_hrs`,        `training_type`,
     `certificate_file`, `qr_code`, `qr_code_data`,
     `status`,
     `issued_at`, `issued_by`,
     `generated_at`, `batch_id`)
SELECT
    r.`certificate_number`,
    'agency'                      AS `source`,
    LOWER(IFNULL(r.`cert_type`, 'completion')) AS `category`,
    NULL                          AS `participant_id`,
    r.`participant_name`,
    NULL                          AS `training_id`,
    b.`training_title`,
    b.`venue`,
    b.`start_date`,
    b.`end_date`,
    b.`no_hrs`,
    b.`training_type`,
    r.`certificate_file`,
    r.`qr_code`,
    r.`qr_code_data`,
    CASE r.`status`
        WHEN 'revoked'  THEN 'voided'
        WHEN 'archived' THEN 'archived'
        ELSE 'active'
    END                           AS `status`,
    r.`issued_at`,
    'OTRS System'                 AS `issued_by`,
    r.`issued_at`                 AS `generated_at`,
    r.`batch_id`
FROM `otrs_agency_cert_records` r
JOIN `otrs_agency_batches`       b ON b.`id` = r.`batch_id`;

-- ── 3. Write a 'migrated' audit log entry for each newly inserted row ─────────
INSERT INTO `otrs_ccs_issuance_log`
    (`certificate_id`, `action`, `actor`, `ip_address`, `notes`, `created_at`)
SELECT
    id, 'migrated', 'system', '', 'Backfill migration from legacy tables.', NOW()
FROM `otrs_ccs_certificates`
WHERE id NOT IN (SELECT certificate_id FROM `otrs_ccs_issuance_log` WHERE action = 'migrated');
