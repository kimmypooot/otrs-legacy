-- add_merged_certificate.sql
-- Adds the "merged" (Completion + Appearance, A4 landscape) certificate
-- category, drops the dead participation/recognition categories, and adds
-- the training_mode column agency batches need to gate Merged eligibility.
-- Safe to re-run.

-- Defensively remap any stray legacy values before narrowing the enum, so
-- the ALTER below can never silently truncate data even if an old row still
-- carries one of the removed category values.
UPDATE otrs_ccs_certificates SET category = 'completion' WHERE category IN ('participation', 'recognition');

ALTER TABLE otrs_ccs_certificates
    MODIFY category ENUM('completion', 'certification', 'appearance', 'merged') NOT NULL;

ALTER TABLE otrs_agency_batches
    ADD COLUMN IF NOT EXISTS training_mode varchar(32) DEFAULT NULL
    COMMENT 'face-to-face|online|hybrid|blended; gates Merged-format eligibility' AFTER cert_type;
