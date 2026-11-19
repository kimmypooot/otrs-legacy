-- Migration: per-SME-session evaluation day tracking
-- Safe to re-run (IF NOT EXISTS guards).
--
-- Background:
--   An "evaluation day" is the last day any SME presents. A participant submits
--   one evaluation form per evaluation day, rating the SME(s) who finished that day.
--   This migration adds day_number to otrs_evaluations and a unique constraint so
--   each participant can only submit once per evaluation day.

ALTER TABLE `otrs_evaluations`
    ADD COLUMN IF NOT EXISTS `day_number` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Eval day this form covers — last day the rated SME(s) present (from otrs_sme_training_days)';

ALTER TABLE `otrs_evaluations`
    ADD UNIQUE IF NOT EXISTS `uq_eval_participant_day` (`training_id`, `participant_id`, `day_number`);
