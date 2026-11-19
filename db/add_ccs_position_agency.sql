-- add_ccs_position_agency.sql
-- Adds position and agency columns to otrs_ccs_certificates so Certification
-- certs (from any source: OTRS Standard, Agency, Internal) can carry the
-- participant's position/designation and agency/office.
-- Safe to re-run (IF NOT EXISTS checks).

ALTER TABLE `otrs_ccs_certificates`
    ADD COLUMN IF NOT EXISTS `position` varchar(255) DEFAULT NULL AFTER `training_level`,
    ADD COLUMN IF NOT EXISTS `agency`   varchar(255) DEFAULT NULL AFTER `position`;
