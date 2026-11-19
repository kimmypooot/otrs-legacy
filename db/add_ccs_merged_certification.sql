-- add_ccs_merged_certification.sql
-- Adds 'merged_certification' to the category ENUM so agency-batch certs that
-- combine a Certification with a Certificate of Appearance can be stored and
-- edited without silently truncating to empty string.
-- Safe to re-run (MODIFY is idempotent when the value is already present).

ALTER TABLE otrs_ccs_certificates
    MODIFY category ENUM(
        'completion',
        'certification',
        'appearance',
        'merged',
        'merged_certification'
    ) NOT NULL;
