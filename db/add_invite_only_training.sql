-- Migration: invite-only training registration
-- Run once. Safe to re-run: IF NOT EXISTS guards.

ALTER TABLE `otrs_trainings`
    ADD COLUMN IF NOT EXISTS `is_invite_only` TINYINT(1)  NOT NULL DEFAULT 0
        COMMENT 'If 1, training is hidden from all public listings and only reachable via invite_token',
    ADD COLUMN IF NOT EXISTS `invite_token`   VARCHAR(64) DEFAULT NULL
        COMMENT '64-char hex token gating public discovery/registration for invite-only trainings';

ALTER TABLE `otrs_trainings`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_invite_token` (`invite_token`);
