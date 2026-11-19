-- ccs_appearance_no_cert_number.sql
-- Certificates of Appearance no longer receive a certificate number.
-- Makes otrs_ccs_certificates.certificate_number nullable so appearance
-- rows can store NULL. The UNIQUE key is retained — InnoDB allows any
-- number of NULLs under a unique index, so numbered certs stay protected.
-- Safe to re-run.

ALTER TABLE `otrs_ccs_certificates`
  MODIFY `certificate_number` varchar(64) DEFAULT NULL;

-- OPTIONAL (run manually if desired): strip numbers already assigned to
-- previously generated Certificates of Appearance. Not run automatically
-- because it rewrites historical records.
-- UPDATE `otrs_ccs_certificates`
--    SET `certificate_number` = NULL
--  WHERE `source` = 'appearance';
