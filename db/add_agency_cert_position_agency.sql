-- add_agency_cert_position_agency.sql
-- Adds position and agency columns to otrs_agency_cert_records.
-- Safe to re-run (IF NOT EXISTS checks).

ALTER TABLE `otrs_agency_cert_records`
    ADD COLUMN IF NOT EXISTS `position` varchar(255) DEFAULT NULL AFTER `participant_name`,
    ADD COLUMN IF NOT EXISTS `agency`   varchar(255) DEFAULT NULL AFTER `position`;
