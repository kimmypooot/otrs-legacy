-- add_agency_cert_hrs_type.sql
-- Adds no_hrs and training_type columns to otrs_agency_batches.
-- Safe to re-run (IF NOT EXISTS checks).

ALTER TABLE `otrs_agency_batches`
    ADD COLUMN IF NOT EXISTS `no_hrs`       int          DEFAULT NULL
        COMMENT 'Number of training credit hours' AFTER `cert_type`,
    ADD COLUMN IF NOT EXISTS `training_type` enum('Technical','Foundation','Leadership and Management')
        DEFAULT NULL AFTER `no_hrs`;
