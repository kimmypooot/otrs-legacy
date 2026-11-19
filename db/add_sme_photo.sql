-- Add photo column to otrs_smes for external SME profile pictures.
-- Run once on production. Safe to re-run (IF NOT EXISTS guard via SHOW COLUMNS).
ALTER TABLE `otrs_smes`
    ADD COLUMN IF NOT EXISTS `photo` varchar(128) NOT NULL DEFAULT ''
    AFTER `bio`;
