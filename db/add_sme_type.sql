-- Add sme_type and user_id columns to otrs_smes
ALTER TABLE `otrs_smes`
  ADD COLUMN IF NOT EXISTS `sme_type` ENUM('internal','external') NOT NULL DEFAULT 'external'
    COMMENT 'internal = CSC RO VIII employee; external = outside speaker' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `user_id` INT DEFAULT NULL
    COMMENT 'FK → users_cscro8.id; NULL for external SMEs' AFTER `sme_type`;

ALTER TABLE `otrs_smes`
  ADD INDEX IF NOT EXISTS `idx_sme_type` (`sme_type`);
