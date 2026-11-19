-- ============================================================
-- OTRS Migration — Add source column to otrs_attendance
-- Run AFTER add_sme_management.sql.
-- Safe to re-run (IF NOT EXISTS guard via COLUMN_NAME check).
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'otrs_attendance'
      AND COLUMN_NAME  = 'source'
);

SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `otrs_attendance`
        ADD COLUMN `source` ENUM('admin','self') NOT NULL DEFAULT 'admin'
            COMMENT 'admin = marked by HRD; self = participant self-reported on public portal'
            AFTER `marked_by`",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
