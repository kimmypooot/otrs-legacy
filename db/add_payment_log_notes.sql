-- Adds the optional `notes` column to otrs_payment_logs.
-- Used by profile-change log entries ('Participant Info Updated') to record
-- which fields were changed (e.g. "Updated: First Name, Agency").
-- Safe to run more than once — the IF NOT EXISTS guard is implicit via the
-- column check below; run via phpMyAdmin on the live database.

ALTER TABLE `otrs_payment_logs`
    ADD COLUMN IF NOT EXISTS `notes` varchar(500) DEFAULT NULL
        COMMENT 'Free-text detail for profile-change log entries'
        AFTER `new_status`;
