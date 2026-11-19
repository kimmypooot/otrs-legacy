-- otrs_payment_logs: audit trail for payment status changes on otrs_registrations.
-- Run via phpMyAdmin to apply to the live database.

DROP TABLE IF EXISTS `otrs_payment_logs`;

CREATE TABLE `otrs_payment_logs` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `registration_id` int(11)      NOT NULL COMMENT 'FK → otrs_registrations.id',
  `action`          varchar(60)  NOT NULL COMMENT 'Payment Updated | Registration Cancelled | Participant Info Updated',
  `old_status`      varchar(50)  DEFAULT NULL,
  `new_status`      varchar(50)  NOT NULL,
  `notes`           varchar(500) DEFAULT NULL COMMENT 'Free-text detail for profile-change log entries',
  `updated_by`      varchar(100) NOT NULL,
  `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_paylog_reg`  (`registration_id`),
  KEY `idx_paylog_date` (`updated_at`),
  CONSTRAINT `fk_paylog_reg` FOREIGN KEY (`registration_id`)
      REFERENCES `otrs_registrations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Audit log for payment status changes on otrs_registrations.';
