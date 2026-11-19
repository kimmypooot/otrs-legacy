-- Migration: add template fields to otrs_training_names
-- Run once. Safe to re-run: IF NOT EXISTS guards prevent duplicates.

ALTER TABLE `otrs_training_names`
    ADD COLUMN IF NOT EXISTS `default_target`      TEXT          DEFAULT NULL COMMENT 'Pre-filled Target Participants',
    ADD COLUMN IF NOT EXISTS `default_level`       VARCHAR(64)   DEFAULT NULL COMMENT 'Pre-filled Curriculum Area',
    ADD COLUMN IF NOT EXISTS `default_description` TEXT          DEFAULT NULL COMMENT 'Pre-filled Description';
