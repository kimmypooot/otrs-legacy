-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 15, 2026 at 08:04 AM
-- Server version: 11.8.6-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u390694310_cscro8`
--

-- --------------------------------------------------------

--
-- Table structure for table `imis_access_roles`
--

CREATE TABLE `imis_access_roles` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL COMMENT 'Role label shown in the UI badge (Admin, User, Superadmin, staff…)',
  `project_url` varchar(255) NOT NULL COMMENT 'Redirect target — relative path or full https:// URL for external systems',
  `is_external` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = open in new tab',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imis_admin_audit_log`
--

CREATE TABLE `imis_admin_audit_log` (
  `id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL COMMENT 'superadmin who performed the action',
  `actor_name` varchar(128) NOT NULL,
  `action` varchar(64) NOT NULL COMMENT 'e.g. create_user, delete_user, reset_password',
  `target_id` int(11) DEFAULT NULL COMMENT 'affected user_id / project_id',
  `target_label` varchar(128) DEFAULT NULL COMMENT 'human-readable target (username, project name)',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'before/after snapshot (passwords excluded)' CHECK (json_valid(`details`)),
  `ip_address` varchar(45) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all superadmin actions in the IMIS admin panel';

-- --------------------------------------------------------

--
-- Table structure for table `imis_announcements`
--

CREATE TABLE `imis_announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_by` int(11) NOT NULL COMMENT 'FK → users_cscro8.id',
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = never expires; set to a future datetime to auto-hide',
  `scheduled_at` datetime DEFAULT NULL COMMENT 'NULL = publish immediately; future datetime = not shown until then',
  `target_scope` enum('all','division','role') NOT NULL DEFAULT 'all' COMMENT 'Audience scope for the announcement',
  `target_value` varchar(100) DEFAULT NULL COMMENT 'Division code or role name when target_scope != all',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System-wide dashboard announcements managed by superadmin';

-- --------------------------------------------------------

--
-- Table structure for table `imis_dash_categories`
--

CREATE TABLE `imis_dash_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'bi-grid' COMMENT 'Bootstrap-icon class suffix used in the category label',
  `type` varchar(50) NOT NULL DEFAULT 'rsu' COMMENT 'CSS class suffix (rsu | committee) – drives badge colour',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Top-level groupings shown on the IMIS dashboard';

-- --------------------------------------------------------

--
-- Table structure for table `imis_dash_divisions`
--

CREATE TABLE `imis_dash_divisions` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'bi bi-building',
  `description` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Division / process-owner cards shown on the IMIS dashboard';

-- --------------------------------------------------------

--
-- Table structure for table `imis_dash_division_systems`
--

CREATE TABLE `imis_dash_division_systems` (
  `id` int(11) NOT NULL,
  `division_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Mapping: which systems appear under which dashboard division card';

-- --------------------------------------------------------

--
-- Table structure for table `imis_history_access_logs`
--

CREATE TABLE `imis_history_access_logs` (
  `id` int(11) NOT NULL,
  `login_log_id` int(11) DEFAULT NULL COMMENT 'FK to imis_history_login_logs — join for username, ip_address, user_agent, session_id',
  `user_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `project_code` varchar(100) NOT NULL COMMENT 'Denormalised code_name for fast queries without a JOIN',
  `role_name` varchar(64) NOT NULL COMMENT 'Role the user held at the time of access',
  `accessed_at` datetime NOT NULL COMMENT 'Asia/Manila timestamp set by PHP',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail of every successful system/project access via IMIS dashboard';

-- --------------------------------------------------------

--
-- Table structure for table `imis_history_login_logs`
--

CREATE TABLE `imis_history_login_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `login_time` datetime NOT NULL,
  `logout_time` datetime DEFAULT NULL,
  `last_heartbeat` datetime DEFAULT NULL COMMENT 'Refreshed every ~30 s by session_heartbeat.php. NULL or stale = session abandoned.',
  `user_agent` text NOT NULL,
  `status` enum('active','logged_out','timeout') NOT NULL DEFAULT 'active',
  `session_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imis_migrations`
--

CREATE TABLE `imis_migrations` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL COMMENT 'e.g. 001_dashboard_enhancements.sql',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `applied_by` varchar(64) NOT NULL DEFAULT 'cli' COMMENT 'runner identity'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks applied database migrations for IMIS';

-- --------------------------------------------------------

--
-- Table structure for table `imis_projects`
--

CREATE TABLE `imis_projects` (
  `id` int(11) NOT NULL,
  `code_name` varchar(100) NOT NULL COMMENT 'System ID used in PHP/JS (e.g. OTRS, GAD-CORNER)',
  `project_name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'bi bi-app',
  `guest_url` varchar(255) DEFAULT NULL COMMENT 'Redirect for users with no assigned role — NULL means no guest access',
  `requires_setup` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = server performs a secondary DB query before redirecting',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = shown on dashboard regardless of assigned role (e.g., public-facing ERIS)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imis_security_audit_logs`
--

CREATE TABLE `imis_security_audit_logs` (
  `id` int(11) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Security event audit trail for IMIS';

-- --------------------------------------------------------

--
-- Table structure for table `imis_security_rate_limits`
--

CREATE TABLE `imis_security_rate_limits` (
  `id` int(11) NOT NULL,
  `identifier` varchar(128) NOT NULL COMMENT 'IP address or username being rate-limited',
  `action` varchar(64) NOT NULL COMMENT 'Action context: login, password_reset, etc.',
  `attempt_count` int(11) NOT NULL DEFAULT 1,
  `window_start` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rate limiting and brute-force lockout records for IMIS';

-- --------------------------------------------------------

--
-- Table structure for table `imis_system_access`
--

CREATE TABLE `imis_system_access` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `granted_by` int(11) DEFAULT NULL COMMENT 'FK to users_cscro8.id — admin who granted this access entry'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imis_user_recent_systems`
--

CREATE TABLE `imis_user_recent_systems` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `project_code` varchar(100) NOT NULL COMMENT 'Matches imis_projects.code_name (stored uppercase)',
  `accessed_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Persists last 5 systems accessed per user across browser sessions';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_agency_batches`
--

CREATE TABLE `otrs_agency_batches` (
  `id` int(12) NOT NULL,
  `training_title` varchar(255) NOT NULL,
  `venue` varchar(255) NOT NULL DEFAULT '',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `cert_type` enum('Completion','Certification') NOT NULL DEFAULT 'Completion',
  `no_hrs` int(11) DEFAULT NULL,
  `training_type` enum('Technical','Foundation','Leadership and Management') DEFAULT NULL,
  `created_by` int(12) DEFAULT NULL COMMENT 'imis user id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='One row per agency-requested certificate generation batch.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_agency_cert_records`
--

CREATE TABLE `otrs_agency_cert_records` (
  `id` int(12) NOT NULL,
  `batch_id` int(12) NOT NULL,
  `participant_name` varchar(255) NOT NULL,
  `certificate_number` varchar(64) NOT NULL,
  `certificate_file` varchar(512) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `qr_code_data` varchar(512) DEFAULT NULL,
  `cert_type` enum('Completion','Certification') NOT NULL DEFAULT 'Completion',
  `issued_at` datetime DEFAULT NULL,
  `issued_by` int(12) DEFAULT NULL,
  `status` enum('active','revoked') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='One row per generated certificate in an agency batch.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_attendance`
--

CREATE TABLE `otrs_attendance` (
  `id` int(12) NOT NULL,
  `registration_id` int(12) NOT NULL COMMENT 'FK → otrs_registrations.id',
  `participant_id` int(12) NOT NULL COMMENT 'denorm for quick lookup',
  `training_id` int(12) NOT NULL COMMENT 'denorm',
  `day_number` tinyint(3) UNSIGNED NOT NULL COMMENT '1..N',
  `attendance_date` date DEFAULT NULL,
  `status` enum('present','absent','excused') NOT NULL DEFAULT 'present',
  `marked_by` int(12) DEFAULT NULL COMMENT 'IMIS user ID',
  `source` enum('admin','self') NOT NULL DEFAULT 'admin' COMMENT 'admin = marked by HRD; self = participant self-reported on public portal',
  `marked_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Participant attendance per training day.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_cancellation_logs`
--

CREATE TABLE `otrs_cancellation_logs` (
  `id` int(12) NOT NULL,
  `request_id` int(12) NOT NULL COMMENT 'FK → otrs_cancellation_requests.id',
  `action` varchar(32) NOT NULL COMMENT 'submitted | approved | rejected',
  `actor` varchar(128) NOT NULL COMMENT 'Name of the person who performed the action',
  `actor_location` varchar(32) NOT NULL DEFAULT '' COMMENT 'Location/office code of the actor',
  `remarks` text DEFAULT NULL COMMENT 'Notes accompanying the action',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Audit trail for every status change on a cancellation request';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_cancellation_requests`
--

CREATE TABLE `otrs_cancellation_requests` (
  `id` int(12) NOT NULL,
  `enrollment_id` int(12) NOT NULL COMMENT 'FK → otrs_registrations.id',
  `participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `remarks` text NOT NULL COMMENT 'Reason for cancellation from field office',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by` varchar(128) NOT NULL COMMENT 'Session name of requesting FO officer',
  `requested_by_location` varchar(16) NOT NULL DEFAULT '' COMMENT 'FO location code',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` varchar(128) DEFAULT NULL COMMENT 'HRD officer who actioned the request',
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` text DEFAULT NULL COMMENT 'HRD decision remarks'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Cancellation requests from field offices awaiting HRD approval';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_certificates`
--

CREATE TABLE `otrs_certificates` (
  `id` int(12) NOT NULL,
  `participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `certificate_number` varchar(64) NOT NULL COMMENT 'e.g. CERT-2026-000001',
  `cert_type` varchar(64) NOT NULL DEFAULT 'Completion',
  `certificate_file` varchar(1024) NOT NULL DEFAULT '',
  `qr_code` varchar(512) NOT NULL DEFAULT '',
  `qr_code_data` text NOT NULL COMMENT 'Verification URL in QR',
  `status` varchar(16) NOT NULL DEFAULT 'active' COMMENT 'active|revoked',
  `is_claimed` tinyint(1) NOT NULL DEFAULT 0,
  `claim_source` varchar(32) NOT NULL DEFAULT 'admin' COMMENT 'admin|public',
  `download_count` int(4) NOT NULL DEFAULT 0,
  `downloaded_at` datetime DEFAULT NULL,
  `download_ip` varchar(45) DEFAULT NULL,
  `emailed_at` datetime DEFAULT NULL,
  `email_status` varchar(16) DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `issued_by` varchar(128) NOT NULL DEFAULT 'OTRS System',
  `generated_at` datetime DEFAULT NULL,
  `generated_by` int(12) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_cert_email_jobs`
--

CREATE TABLE `otrs_cert_email_jobs` (
  `id` int(10) UNSIGNED NOT NULL,
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `job_id` char(32) NOT NULL COMMENT 'Random hex token',
  `total` smallint(6) NOT NULL DEFAULT 0,
  `sent` smallint(6) NOT NULL DEFAULT 0,
  `failed` smallint(6) NOT NULL DEFAULT 0,
  `status` enum('processing','done') NOT NULL DEFAULT 'processing',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_claim_tokens`
--

CREATE TABLE `otrs_claim_tokens` (
  `id` int(12) NOT NULL,
  `token` varchar(128) NOT NULL COMMENT '64-char hex',
  `certificate_id` int(12) NOT NULL COMMENT 'FK → otrs_certificates.id',
  `participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `evaluation_id` int(12) NOT NULL COMMENT 'FK → otrs_evaluations.id',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_ip` varchar(45) DEFAULT NULL,
  `used_ip` varchar(45) DEFAULT NULL,
  `used_ua` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_collecting_officer`
--

CREATE TABLE `otrs_collecting_officer` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `position` varchar(64) NOT NULL,
  `location` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_email_templates`
--

CREATE TABLE `otrs_email_templates` (
  `id` int(12) NOT NULL,
  `template_code` varchar(64) NOT NULL COMMENT 'Unique slug, e.g. registration_ack',
  `template_name` varchar(128) NOT NULL,
  `badge_label` varchar(64) NOT NULL DEFAULT '' COMMENT 'Badge text in email header banner',
  `email_subject` varchar(255) NOT NULL,
  `email_body` longtext NOT NULL COMMENT 'HTML inner content with {variable} placeholders',
  `email_altbody` text NOT NULL DEFAULT '' COMMENT 'Plain text AltBody with {variable} placeholders',
  `description` varchar(255) NOT NULL DEFAULT '',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `version` int(11) NOT NULL DEFAULT 1,
  `created_by` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(64) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Centralized email templates for HRD notifications';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_email_template_logs`
--

CREATE TABLE `otrs_email_template_logs` (
  `id` bigint(20) NOT NULL,
  `template_id` int(12) DEFAULT NULL,
  `template_code` varchar(64) NOT NULL,
  `action` enum('created','updated','activated','deactivated','test_sent','bulk_sent','restored','seeded') NOT NULL,
  `performed_by` varchar(64) NOT NULL,
  `performed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `details` text NOT NULL DEFAULT '' COMMENT 'JSON: recipients, training_id, etc.',
  `ip_address` varchar(45) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Audit log for email template actions and sends';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_email_template_versions`
--

CREATE TABLE `otrs_email_template_versions` (
  `id` int(12) NOT NULL,
  `template_id` int(12) NOT NULL,
  `version` int(11) NOT NULL,
  `template_name` varchar(128) NOT NULL,
  `badge_label` varchar(64) NOT NULL DEFAULT '',
  `email_subject` varchar(255) NOT NULL,
  `email_body` longtext NOT NULL,
  `email_altbody` text NOT NULL DEFAULT '',
  `changed_by` varchar(64) NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `change_note` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Version history for email templates';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_evaluations`
--

CREATE TABLE `otrs_evaluations` (
  `id` int(12) NOT NULL,
  `participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `certificate_id` int(12) DEFAULT NULL COMMENT 'Set after certificate generation',
  `confidence_rating` tinyint(1) NOT NULL COMMENT '1–5',
  `commitment_rating` tinyint(1) NOT NULL COMMENT '1–5',
  `design_q1` tinyint(1) NOT NULL,
  `design_q2` tinyint(1) NOT NULL,
  `design_q3` tinyint(1) NOT NULL,
  `design_q4` tinyint(1) NOT NULL,
  `design_comment` text NOT NULL DEFAULT '',
  `materials_q1` tinyint(1) NOT NULL,
  `materials_q2` tinyint(1) NOT NULL DEFAULT 0,
  `materials_q3` tinyint(1) NOT NULL DEFAULT 0,
  `materials_q4` tinyint(1) NOT NULL DEFAULT 0,
  `materials_comment` text NOT NULL DEFAULT '',
  `admin_q1` tinyint(1) NOT NULL,
  `admin_q2` tinyint(1) NOT NULL DEFAULT 0,
  `admin_q3` tinyint(1) NOT NULL DEFAULT 0,
  `admin_q4` tinyint(1) NOT NULL DEFAULT 0,
  `admin_comment` text NOT NULL DEFAULT '',
  `liked_most` text NOT NULL DEFAULT '',
  `improvements` text NOT NULL DEFAULT '',
  `recommendations` text NOT NULL DEFAULT '',
  `future_topics` text NOT NULL DEFAULT '',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `day_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Eval day this form covers — last day the rated SME(s) present (from otrs_sme_training_days)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_evaluation_commitment_reasons`
--

CREATE TABLE `otrs_evaluation_commitment_reasons` (
  `id` int(12) NOT NULL,
  `evaluation_id` int(12) NOT NULL COMMENT 'FK → otrs_evaluations.id',
  `reason_code` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_evaluation_confidence_reasons`
--

CREATE TABLE `otrs_evaluation_confidence_reasons` (
  `id` int(12) NOT NULL,
  `evaluation_id` int(12) NOT NULL COMMENT 'FK → otrs_evaluations.id',
  `reason_code` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_evaluation_sme_ratings`
--

CREATE TABLE `otrs_evaluation_sme_ratings` (
  `id` int(12) NOT NULL,
  `evaluation_id` int(12) NOT NULL COMMENT 'FK → otrs_evaluations.id',
  `sme_id` int(12) NOT NULL COMMENT 'FK → otrs_training_smes.id',
  `q1_rating` tinyint(1) NOT NULL COMMENT 'Knowledge improved',
  `q2_rating` tinyint(1) NOT NULL COMMENT 'Encouraged interaction',
  `q3_rating` tinyint(1) NOT NULL COMMENT 'Kept engaged',
  `q4_rating` tinyint(1) NOT NULL COMMENT 'Followed learning pace',
  `comments` text NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_hrd_calendar`
--

CREATE TABLE `otrs_hrd_calendar` (
  `id` int(12) NOT NULL,
  `training_id` int(12) DEFAULT NULL COMMENT 'FK → otrs_trainings.id (nullable)',
  `title` varchar(512) NOT NULL,
  `description` text DEFAULT NULL,
  `start` datetime NOT NULL,
  `end` datetime NOT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `recurrence_type` enum('none','monthly','quarterly','annually') NOT NULL DEFAULT 'none' COMMENT 'Recurrence pattern',
  `recurrence_exceptions` text DEFAULT NULL COMMENT 'JSON array of Y-m-d dates to skip (for single-occurrence deletes)',
  `activity_type` varchar(32) NOT NULL DEFAULT 'event' COMMENT 'training|event|leave|holiday|other',
  `is_synced_training` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = auto-synced from trainings table',
  `status` varchar(16) NOT NULL DEFAULT 'active' COMMENT 'active|cancelled|deleted',
  `created_by` int(12) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='HRD calendar. Training entries are synced from otrs_trainings.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_participants`
--

CREATE TABLE `otrs_participants` (
  `id` int(12) NOT NULL,
  `fname` varchar(64) NOT NULL DEFAULT '',
  `mname` varchar(64) NOT NULL DEFAULT '',
  `lname` varchar(64) NOT NULL DEFAULT '',
  `suffix` varchar(16) DEFAULT NULL COMMENT 'Name suffix: Jr., Sr., III, IV, etc.',
  `email` varchar(64) NOT NULL DEFAULT '',
  `mobile_no` varchar(16) NOT NULL DEFAULT '',
  `gender` varchar(32) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `civil_status` varchar(16) NOT NULL DEFAULT '',
  `pwd` enum('Yes','No') NOT NULL DEFAULT 'No' COMMENT 'Persons With Disability (Yes / No)',
  `salary_grade` varchar(12) NOT NULL DEFAULT '',
  `position_title` varchar(254) NOT NULL DEFAULT '',
  `position_level` varchar(64) NOT NULL DEFAULT '',
  `employment_status` varchar(64) NOT NULL DEFAULT '',
  `civil_servant` varchar(16) NOT NULL DEFAULT '',
  `agency_unit` varchar(254) NOT NULL DEFAULT '',
  `agency_name` varchar(254) NOT NULL DEFAULT '',
  `sector` varchar(64) NOT NULL DEFAULT '',
  `geo` varchar(32) NOT NULL DEFAULT '',
  `location` varchar(8) NOT NULL DEFAULT '',
  `agency_address` varchar(254) NOT NULL DEFAULT '',
  `home_address` varchar(254) NOT NULL DEFAULT '',
  `food_restrictions` varchar(254) NOT NULL DEFAULT '',
  `radio_personal` varchar(3) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Participant profile only. Training history in otrs_registrations.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_participant_transfers`
--

CREATE TABLE `otrs_participant_transfers` (
  `id` int(11) NOT NULL,
  `registration_id` int(12) NOT NULL COMMENT 'FK → otrs_registrations.id',
  `from_training_id` int(12) NOT NULL,
  `to_training_id` int(12) NOT NULL,
  `transferred_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log of participant transfers between trainings.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_payment_logs`
--

CREATE TABLE `otrs_payment_logs` (
  `id` int(11) NOT NULL,
  `registration_id` int(11) NOT NULL COMMENT 'FK → otrs_registrations.id',
  `action` varchar(60) NOT NULL COMMENT '''Payment Updated | Registration\r\nCancelled | Participant Info Updated''',
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `notes` varchar(500) DEFAULT NULL COMMENT 'Free-text detail for profile-change log entries',
  `updated_by` varchar(100) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Audit log for payment status changes on otrs_registrations.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_proof_payment`
--

CREATE TABLE `otrs_proof_payment` (
  `id` int(12) NOT NULL,
  `participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `proof_file` varchar(1024) NOT NULL DEFAULT '',
  `file_original_name` varchar(255) NOT NULL DEFAULT '',
  `file_type` varchar(64) NOT NULL DEFAULT '' COMMENT 'MIME type',
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
  `reviewed_by` int(12) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Proof of payment per participant–training. Direct FK, no denormalized name/date fields.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_rate_limits`
--

CREATE TABLE `otrs_rate_limits` (
  `id` int(12) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `action` varchar(64) NOT NULL,
  `hit_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_registrations`
--

CREATE TABLE `otrs_registrations` (
  `id` int(12) NOT NULL,
  `participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `registration_status` varchar(24) NOT NULL DEFAULT 'Confirmed' COMMENT 'Pending|Confirmed|Cancelled',
  `training_status` varchar(24) NOT NULL DEFAULT '' COMMENT 'Ongoing|Completed',
  `payment_status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|free|cancelled|promissory note',
  `or_no` varchar(12) NOT NULL DEFAULT '',
  `or_date` date DEFAULT NULL,
  `collecting_officer` varchar(64) NOT NULL DEFAULT '',
  `remarks` varchar(254) NOT NULL DEFAULT '',
  `proof_file` varchar(1024) NOT NULL DEFAULT '',
  `radio_charge` varchar(3) NOT NULL DEFAULT '',
  `radio_cert` varchar(3) NOT NULL DEFAULT '',
  `supervisor_status` varchar(16) NOT NULL DEFAULT '' COMMENT 'Supervisor eligibility choice recorded at registration: SG18+ | SG15-17 | blank for non-SDC/SDP',
  `designation_order_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if participant was required to upload a designation order/office order/memo (SG 15-17 designate path)',
  `designation_order_file` varchar(1024) NOT NULL DEFAULT '' COMMENT 'Permanent storage path for uploaded designation order. Empty until registration succeeds.',
  `registered_at` datetime DEFAULT NULL COMMENT 'Original registration time',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `prime_hrm_discount` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Participant–training registration bridge. Replaces otrs_enrollments.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_replacement_requests`
--

CREATE TABLE `otrs_replacement_requests` (
  `id` int(12) NOT NULL,
  `enrollment_id` int(12) NOT NULL COMMENT 'FK → otrs_registrations.id',
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `original_participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id (original)',
  `new_participant_id` int(12) NOT NULL COMMENT 'FK → otrs_participants.id (replacement)',
  `replacement_reason` text NOT NULL COMMENT 'Reason provided by the officer performing the replacement',
  `replaced_by` varchar(128) NOT NULL COMMENT 'Session name of the officer who performed the replacement',
  `replaced_by_location` varchar(16) NOT NULL DEFAULT '' COMMENT 'Office/location code of the actor',
  `replaced_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Audit trail of participant replacements in training registrations';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_smes`
--

CREATE TABLE `otrs_smes` (
  `id` int(12) NOT NULL,
  `name` varchar(128) NOT NULL,
  `sme_type` enum('internal','external') NOT NULL DEFAULT 'external' COMMENT 'internal = CSC RO VIII employee; external = outside speaker',
  `user_id` int(11) DEFAULT NULL COMMENT 'FK → users_cscro8.id; NULL for external SMEs',
  `designation` varchar(128) NOT NULL DEFAULT '',
  `email` varchar(128) NOT NULL DEFAULT '',
  `mobile_no` varchar(20) NOT NULL DEFAULT '',
  `agency` varchar(255) NOT NULL DEFAULT '',
  `expertise` varchar(512) NOT NULL DEFAULT '',
  `bio` text NOT NULL DEFAULT '',
  `photo` varchar(128) NOT NULL DEFAULT '',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Global SME/facilitator profile registry.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_sme_training_days`
--

CREATE TABLE `otrs_sme_training_days` (
  `id` int(12) NOT NULL,
  `training_sme_id` int(12) NOT NULL COMMENT 'FK → otrs_training_smes.id',
  `day_number` tinyint(3) UNSIGNED NOT NULL COMMENT '1..N',
  `training_date` date DEFAULT NULL COMMENT 'Actual calendar date for that day'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Day-level SME assignment. No rows = assigned to all days.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_trainings`
--

CREATE TABLE `otrs_trainings` (
  `id` int(12) NOT NULL,
  `type` varchar(64) NOT NULL DEFAULT '' COMMENT 'Training type (denormalized from otrs_training_types)',
  `name` varchar(512) NOT NULL COMMENT 'Full training program title',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `registration_deadline` date NOT NULL,
  `registration_link` varchar(254) NOT NULL DEFAULT '',
  `location` varchar(254) NOT NULL DEFAULT '',
  `mode` varchar(16) NOT NULL DEFAULT '' COMMENT 'face-to-face|online|hybrid|blended',
  `fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `slots` int(12) NOT NULL DEFAULT 0,
  `no_hrs` int(5) NOT NULL DEFAULT 0 COMMENT 'Credit hours on certificate',
  `target_audience` text NOT NULL,
  `level` varchar(64) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `training_status` varchar(32) NOT NULL DEFAULT 'upcoming' COMMENT 'Upcoming|Ongoing|Completed|Cancelled',
  `status` varchar(24) NOT NULL DEFAULT 'active' COMMENT 'active|inactive|draft|archived',
  `force_display` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(12) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `reschedule_reason` varchar(255) DEFAULT NULL COMMENT 'Why this training was rescheduled',
  `rescheduled_to_id` int(12) DEFAULT NULL COMMENT 'FK → otrs_trainings.id of the replacement training'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Master training record. Replaces latin1 legacy trainings table.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_training_names`
--

CREATE TABLE `otrs_training_names` (
  `id` int(12) NOT NULL,
  `name` varchar(512) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `default_target` text DEFAULT NULL COMMENT 'Pre-filled Target Participants',
  `default_level` varchar(64) DEFAULT NULL COMMENT 'Pre-filled Curriculum Area',
  `default_description` text DEFAULT NULL COMMENT 'Pre-filled Description'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otrs_training_smes`
--

CREATE TABLE `otrs_training_smes` (
  `id` int(12) NOT NULL,
  `training_id` int(12) NOT NULL COMMENT 'FK → otrs_trainings.id',
  `sme_name` varchar(128) NOT NULL,
  `designation` varchar(128) NOT NULL DEFAULT '',
  `sort_order` int(5) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sme_id` int(12) DEFAULT NULL COMMENT 'FK → otrs_smes.id (NULL = ad-hoc entry, not from master registry)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Subject-matter experts / facilitators per training.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_training_submissions`
--

CREATE TABLE `otrs_training_submissions` (
  `id` int(12) NOT NULL,
  `registration_id` int(12) NOT NULL COMMENT 'FK → otrs_registrations.id',
  `day_number` tinyint(3) NOT NULL COMMENT '1..N (dynamic per training duration)',
  `submission_date` date DEFAULT NULL,
  `file_path` varchar(1024) NOT NULL DEFAULT '',
  `file_original_name` varchar(255) NOT NULL DEFAULT '',
  `validation_status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
  `validated_by` int(12) DEFAULT NULL COMMENT 'Admin user ID',
  `validated_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='Participant output submissions per training day. Replaces day1–day5 column sprawl.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_training_suspensions`
--

CREATE TABLE `otrs_training_suspensions` (
  `id` int(11) NOT NULL,
  `training_id` int(12) NOT NULL,
  `suspended_date` date NOT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Suspended training days (holidays, weather, etc.) per training.';

-- --------------------------------------------------------

--
-- Table structure for table `otrs_visitor_count`
--

CREATE TABLE `otrs_visitor_count` (
  `id` int(11) NOT NULL DEFAULT 1,
  `total` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_cscro8`
--

CREATE TABLE `users_cscro8` (
  `id` int(12) NOT NULL,
  `fname` varchar(64) NOT NULL,
  `lname` varchar(64) NOT NULL,
  `mname` varchar(36) NOT NULL,
  `minitial` varchar(64) NOT NULL,
  `sex` enum('Male','Female','') NOT NULL DEFAULT '',
  `username` varchar(64) NOT NULL,
  `password` varchar(512) NOT NULL,
  `email` varchar(128) NOT NULL,
  `position` varchar(128) NOT NULL,
  `fo_rsu` varchar(128) NOT NULL,
  `first_day` date NOT NULL,
  `exam_fo_id` int(12) NOT NULL,
  `itg_id` int(12) NOT NULL,
  `birthday` date NOT NULL,
  `status` varchar(36) NOT NULL,
  `type` varchar(12) NOT NULL,
  `role` enum('user','admin','superadmin') NOT NULL DEFAULT 'user',
  `profile` varchar(512) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = user is redirected to change_pw.php until password is updated',
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `imis_access_roles`
--
ALTER TABLE `imis_access_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_access_roles_project_name` (`project_id`,`name`);

--
-- Indexes for table `imis_admin_audit_log`
--
ALTER TABLE `imis_admin_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aal_actor` (`actor_id`),
  ADD KEY `idx_aal_action` (`action`),
  ADD KEY `idx_aal_created` (`created_at`);

--
-- Indexes for table `imis_announcements`
--
ALTER TABLE `imis_announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ann_created_by` (`created_by`),
  ADD KEY `idx_ann_active_sched_expires` (`is_active`,`scheduled_at`,`expires_at`);

--
-- Indexes for table `imis_dash_categories`
--
ALTER TABLE `imis_dash_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dash_cat_name` (`name`);

--
-- Indexes for table `imis_dash_divisions`
--
ALTER TABLE `imis_dash_divisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_dd_category` (`category_id`),
  ADD KEY `idx_dd_cat_active_order` (`category_id`,`is_active`,`display_order`);

--
-- Indexes for table `imis_dash_division_systems`
--
ALTER TABLE `imis_dash_division_systems`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dds_div_proj` (`division_id`,`project_id`),
  ADD KEY `fk_dds_division` (`division_id`),
  ADD KEY `fk_dds_project` (`project_id`),
  ADD KEY `idx_dds_div_active_order` (`division_id`,`is_active`,`display_order`);

--
-- Indexes for table `imis_history_access_logs`
--
ALTER TABLE `imis_history_access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hal_login_log_id` (`login_log_id`),
  ADD KEY `idx_hal_user_id` (`user_id`),
  ADD KEY `idx_hal_project_id` (`project_id`),
  ADD KEY `idx_hal_accessed_at` (`accessed_at`),
  ADD KEY `idx_hal_dedup` (`user_id`,`project_id`,`accessed_at`),
  ADD KEY `idx_hal_user_time` (`user_id`,`accessed_at`);

--
-- Indexes for table `imis_history_login_logs`
--
ALTER TABLE `imis_history_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_login_time` (`login_time`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_user_login_time` (`user_id`,`login_time`),
  ADD KEY `idx_active_sessions` (`user_id`,`status`,`session_id`),
  ADD KEY `idx_hll_session_status` (`session_id`,`status`),
  ADD KEY `idx_cleanup_stale` (`status`,`last_heartbeat`),
  ADD KEY `idx_cleanup_old` (`status`,`login_time`);

--
-- Indexes for table `imis_migrations`
--
ALTER TABLE `imis_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_migrations_filename` (`filename`);

--
-- Indexes for table `imis_projects`
--
ALTER TABLE `imis_projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_projects_code_name` (`code_name`),
  ADD KEY `idx_proj_active` (`is_active`,`id`);

--
-- Indexes for table `imis_security_audit_logs`
--
ALTER TABLE `imis_security_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_event` (`event_type`),
  ADD KEY `idx_audit_ip` (`ip_address`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_sev` (`severity`),
  ADD KEY `idx_sal_event_created` (`event_type`,`created_at`);

--
-- Indexes for table `imis_security_rate_limits`
--
ALTER TABLE `imis_security_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rl_identifier_action` (`identifier`,`action`),
  ADD KEY `idx_rl_locked` (`locked_until`);

--
-- Indexes for table `imis_system_access`
--
ALTER TABLE `imis_system_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_imsa_user_project` (`user_id`,`project_id`),
  ADD KEY `fk_imsa_project` (`project_id`),
  ADD KEY `fk_imsa_role` (`role_id`),
  ADD KEY `idx_imsa_user_project_role` (`user_id`,`project_id`,`role_id`),
  ADD KEY `idx_imsa_granted_by` (`granted_by`);

--
-- Indexes for table `imis_user_recent_systems`
--
ALTER TABLE `imis_user_recent_systems`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_urs_user_project` (`user_id`,`project_code`),
  ADD KEY `idx_urs_user_time` (`user_id`,`accessed_at`);

--
-- Indexes for table `otrs_agency_batches`
--
ALTER TABLE `otrs_agency_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `otrs_agency_cert_records`
--
ALTER TABLE `otrs_agency_cert_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cert_number` (`certificate_number`),
  ADD KEY `idx_batch` (`batch_id`);

--
-- Indexes for table `otrs_attendance`
--
ALTER TABLE `otrs_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_reg_day` (`registration_id`,`day_number`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_participant_id` (`participant_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `otrs_cancellation_logs`
--
ALTER TABLE `otrs_cancellation_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_id` (`request_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `otrs_cancellation_requests`
--
ALTER TABLE `otrs_cancellation_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enrollment_id` (`enrollment_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_requested_at` (`requested_at`);

--
-- Indexes for table `otrs_certificates`
--
ALTER TABLE `otrs_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_certificate_number` (`certificate_number`),
  ADD UNIQUE KEY `uq_participant_training` (`participant_id`,`training_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_issued_at` (`issued_at`),
  ADD KEY `idx_is_claimed` (`is_claimed`);

--
-- Indexes for table `otrs_cert_email_jobs`
--
ALTER TABLE `otrs_cert_email_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_job_id` (`job_id`),
  ADD KEY `idx_training_id` (`training_id`);

--
-- Indexes for table `otrs_claim_tokens`
--
ALTER TABLE `otrs_claim_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_token` (`token`),
  ADD KEY `idx_certificate_id` (`certificate_id`),
  ADD KEY `idx_participant_id` (`participant_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `otrs_collecting_officer`
--
ALTER TABLE `otrs_collecting_officer`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `otrs_email_templates`
--
ALTER TABLE `otrs_email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_template_code` (`template_code`);

--
-- Indexes for table `otrs_email_template_logs`
--
ALTER TABLE `otrs_email_template_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_etl_template` (`template_id`),
  ADD KEY `idx_etl_performed_at` (`performed_at`);

--
-- Indexes for table `otrs_email_template_versions`
--
ALTER TABLE `otrs_email_template_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_etv_template` (`template_id`),
  ADD KEY `idx_etv_ver` (`template_id`,`version`);

--
-- Indexes for table `otrs_evaluations`
--
ALTER TABLE `otrs_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_eval_participant_day` (`training_id`,`participant_id`,`day_number`),
  ADD UNIQUE KEY `uq_eval_pid_tid_day` (`participant_id`,`training_id`,`day_number`),
  ADD KEY `idx_participant_id` (`participant_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_submitted_at` (`submitted_at`);

--
-- Indexes for table `otrs_evaluation_commitment_reasons`
--
ALTER TABLE `otrs_evaluation_commitment_reasons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_evaluation_id` (`evaluation_id`);

--
-- Indexes for table `otrs_evaluation_confidence_reasons`
--
ALTER TABLE `otrs_evaluation_confidence_reasons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_evaluation_id` (`evaluation_id`);

--
-- Indexes for table `otrs_evaluation_sme_ratings`
--
ALTER TABLE `otrs_evaluation_sme_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_eval_sme` (`evaluation_id`,`sme_id`),
  ADD KEY `idx_sme_id` (`sme_id`);

--
-- Indexes for table `otrs_hrd_calendar`
--
ALTER TABLE `otrs_hrd_calendar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_start_end` (`start`,`end`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_activity_type` (`activity_type`);

--
-- Indexes for table `otrs_participants`
--
ALTER TABLE `otrs_participants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_agency` (`agency_name`(100)),
  ADD KEY `idx_sector` (`sector`),
  ADD KEY `idx_fullname` (`lname`,`fname`),
  ADD KEY `idx_part_email` (`email`);

--
-- Indexes for table `otrs_participant_transfers`
--
ALTER TABLE `otrs_participant_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_from_training` (`from_training_id`),
  ADD KEY `idx_to_training` (`to_training_id`),
  ADD KEY `idx_transfer_from` (`from_training_id`);

--
-- Indexes for table `otrs_payment_logs`
--
ALTER TABLE `otrs_payment_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_paylog_reg` (`registration_id`),
  ADD KEY `idx_paylog_date` (`updated_at`);

--
-- Indexes for table `otrs_proof_payment`
--
ALTER TABLE `otrs_proof_payment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_participant_id` (`participant_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_submitted_at` (`submitted_at`);

--
-- Indexes for table `otrs_rate_limits`
--
ALTER TABLE `otrs_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_action_time` (`ip_address`,`action`,`hit_at`),
  ADD KEY `idx_rate_limits_ip_action_time` (`ip_address`,`action`,`hit_at`),
  ADD KEY `idx_rate_ip_action_time` (`ip_address`,`action`,`hit_at`);

--
-- Indexes for table `otrs_registrations`
--
ALTER TABLE `otrs_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_participant_training` (`participant_id`,`training_id`),
  ADD KEY `idx_participant_id` (`participant_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_registration_status` (`registration_status`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_training_status` (`training_status`),
  ADD KEY `idx_registrations_training_participant` (`training_id`,`participant_id`),
  ADD KEY `idx_registrations_payment_training` (`training_id`,`payment_status`),
  ADD KEY `idx_reg_training_participant` (`training_id`,`participant_id`),
  ADD KEY `idx_reg_training_payment` (`training_id`,`payment_status`);

--
-- Indexes for table `otrs_replacement_requests`
--
ALTER TABLE `otrs_replacement_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enrollment_id` (`enrollment_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_original_participant` (`original_participant_id`),
  ADD KEY `idx_replaced_at` (`replaced_at`);

--
-- Indexes for table `otrs_smes`
--
ALTER TABLE `otrs_smes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_name` (`name`(100)),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sme_type` (`sme_type`);

--
-- Indexes for table `otrs_sme_training_days`
--
ALTER TABLE `otrs_sme_training_days`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sme_day` (`training_sme_id`,`day_number`),
  ADD KEY `idx_training_sme_id` (`training_sme_id`);

--
-- Indexes for table `otrs_trainings`
--
ALTER TABLE `otrs_trainings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_start_date` (`start_date`),
  ADD KEY `idx_end_date` (`end_date`),
  ADD KEY `idx_training_status` (`training_status`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_name` (`name`(255)),
  ADD KEY `idx_registration` (`name`(255),`start_date`,`end_date`,`status`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_trainings_status_date` (`status`,`start_date`,`end_date`),
  ADD KEY `idx_trn_status_date` (`status`,`training_status`,`start_date`,`end_date`);

--
-- Indexes for table `otrs_training_names`
--
ALTER TABLE `otrs_training_names`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_training_name` (`name`(191));

--
-- Indexes for table `otrs_training_smes`
--
ALTER TABLE `otrs_training_smes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_sme_id` (`sme_id`);

--
-- Indexes for table `otrs_training_submissions`
--
ALTER TABLE `otrs_training_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_registration_day` (`registration_id`,`day_number`),
  ADD KEY `idx_registration_id` (`registration_id`),
  ADD KEY `idx_validation_status` (`validation_status`);

--
-- Indexes for table `otrs_training_suspensions`
--
ALTER TABLE `otrs_training_suspensions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_training_date` (`training_id`,`suspended_date`),
  ADD KEY `idx_susp_training` (`training_id`,`suspended_date`);

--
-- Indexes for table `otrs_visitor_count`
--
ALTER TABLE `otrs_visitor_count`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users_cscro8`
--
ALTER TABLE `users_cscro8`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_users_status_role` (`status`,`role`),
  ADD KEY `idx_users_fo_rsu` (`fo_rsu`),
  ADD KEY `idx_exam_fo_id` (`exam_fo_id`),
  ADD KEY `idx_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `imis_access_roles`
--
ALTER TABLE `imis_access_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_admin_audit_log`
--
ALTER TABLE `imis_admin_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_announcements`
--
ALTER TABLE `imis_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_dash_categories`
--
ALTER TABLE `imis_dash_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_dash_divisions`
--
ALTER TABLE `imis_dash_divisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_dash_division_systems`
--
ALTER TABLE `imis_dash_division_systems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_history_access_logs`
--
ALTER TABLE `imis_history_access_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_history_login_logs`
--
ALTER TABLE `imis_history_login_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_migrations`
--
ALTER TABLE `imis_migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_projects`
--
ALTER TABLE `imis_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_security_audit_logs`
--
ALTER TABLE `imis_security_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_security_rate_limits`
--
ALTER TABLE `imis_security_rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_system_access`
--
ALTER TABLE `imis_system_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imis_user_recent_systems`
--
ALTER TABLE `imis_user_recent_systems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_agency_batches`
--
ALTER TABLE `otrs_agency_batches`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_agency_cert_records`
--
ALTER TABLE `otrs_agency_cert_records`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_attendance`
--
ALTER TABLE `otrs_attendance`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_cancellation_logs`
--
ALTER TABLE `otrs_cancellation_logs`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_cancellation_requests`
--
ALTER TABLE `otrs_cancellation_requests`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_certificates`
--
ALTER TABLE `otrs_certificates`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_cert_email_jobs`
--
ALTER TABLE `otrs_cert_email_jobs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_claim_tokens`
--
ALTER TABLE `otrs_claim_tokens`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_collecting_officer`
--
ALTER TABLE `otrs_collecting_officer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_email_templates`
--
ALTER TABLE `otrs_email_templates`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_email_template_logs`
--
ALTER TABLE `otrs_email_template_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_email_template_versions`
--
ALTER TABLE `otrs_email_template_versions`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_evaluations`
--
ALTER TABLE `otrs_evaluations`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_evaluation_commitment_reasons`
--
ALTER TABLE `otrs_evaluation_commitment_reasons`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_evaluation_confidence_reasons`
--
ALTER TABLE `otrs_evaluation_confidence_reasons`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_evaluation_sme_ratings`
--
ALTER TABLE `otrs_evaluation_sme_ratings`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_hrd_calendar`
--
ALTER TABLE `otrs_hrd_calendar`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_participants`
--
ALTER TABLE `otrs_participants`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_participant_transfers`
--
ALTER TABLE `otrs_participant_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_payment_logs`
--
ALTER TABLE `otrs_payment_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_proof_payment`
--
ALTER TABLE `otrs_proof_payment`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_rate_limits`
--
ALTER TABLE `otrs_rate_limits`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_registrations`
--
ALTER TABLE `otrs_registrations`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_replacement_requests`
--
ALTER TABLE `otrs_replacement_requests`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_smes`
--
ALTER TABLE `otrs_smes`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_sme_training_days`
--
ALTER TABLE `otrs_sme_training_days`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_trainings`
--
ALTER TABLE `otrs_trainings`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_training_names`
--
ALTER TABLE `otrs_training_names`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_training_smes`
--
ALTER TABLE `otrs_training_smes`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_training_submissions`
--
ALTER TABLE `otrs_training_submissions`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otrs_training_suspensions`
--
ALTER TABLE `otrs_training_suspensions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users_cscro8`
--
ALTER TABLE `users_cscro8`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `imis_access_roles`
--
ALTER TABLE `imis_access_roles`
  ADD CONSTRAINT `fk_imar_project` FOREIGN KEY (`project_id`) REFERENCES `imis_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_announcements`
--
ALTER TABLE `imis_announcements`
  ADD CONSTRAINT `fk_ann_created_by` FOREIGN KEY (`created_by`) REFERENCES `users_cscro8` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_dash_divisions`
--
ALTER TABLE `imis_dash_divisions`
  ADD CONSTRAINT `fk_dd_category` FOREIGN KEY (`category_id`) REFERENCES `imis_dash_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_dash_division_systems`
--
ALTER TABLE `imis_dash_division_systems`
  ADD CONSTRAINT `fk_dds_division` FOREIGN KEY (`division_id`) REFERENCES `imis_dash_divisions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dds_project` FOREIGN KEY (`project_id`) REFERENCES `imis_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_history_access_logs`
--
ALTER TABLE `imis_history_access_logs`
  ADD CONSTRAINT `fk_hal_login_log_id` FOREIGN KEY (`login_log_id`) REFERENCES `imis_history_login_logs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_hal_project_id` FOREIGN KEY (`project_id`) REFERENCES `imis_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_hal_user_id` FOREIGN KEY (`user_id`) REFERENCES `users_cscro8` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_history_login_logs`
--
ALTER TABLE `imis_history_login_logs`
  ADD CONSTRAINT `fk_login_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users_cscro8` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_system_access`
--
ALTER TABLE `imis_system_access`
  ADD CONSTRAINT `fk_imsa_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users_cscro8` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_imsa_project` FOREIGN KEY (`project_id`) REFERENCES `imis_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_imsa_role` FOREIGN KEY (`role_id`) REFERENCES `imis_access_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_imsa_user` FOREIGN KEY (`user_id`) REFERENCES `users_cscro8` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imis_user_recent_systems`
--
ALTER TABLE `imis_user_recent_systems`
  ADD CONSTRAINT `fk_urs_user` FOREIGN KEY (`user_id`) REFERENCES `users_cscro8` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_agency_cert_records`
--
ALTER TABLE `otrs_agency_cert_records`
  ADD CONSTRAINT `fk_agency_cert_batch` FOREIGN KEY (`batch_id`) REFERENCES `otrs_agency_batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otrs_attendance`
--
ALTER TABLE `otrs_attendance`
  ADD CONSTRAINT `fk_attend_reg` FOREIGN KEY (`registration_id`) REFERENCES `otrs_registrations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_certificates`
--
ALTER TABLE `otrs_certificates`
  ADD CONSTRAINT `fk_cert_participant` FOREIGN KEY (`participant_id`) REFERENCES `otrs_participants` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cert_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `otrs_cert_email_jobs`
--
ALTER TABLE `otrs_cert_email_jobs`
  ADD CONSTRAINT `fk_email_jobs_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_claim_tokens`
--
ALTER TABLE `otrs_claim_tokens`
  ADD CONSTRAINT `fk_claim_certificate` FOREIGN KEY (`certificate_id`) REFERENCES `otrs_certificates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_claim_participant` FOREIGN KEY (`participant_id`) REFERENCES `otrs_participants` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_claim_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `otrs_email_template_versions`
--
ALTER TABLE `otrs_email_template_versions`
  ADD CONSTRAINT `fk_etv_template` FOREIGN KEY (`template_id`) REFERENCES `otrs_email_templates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otrs_evaluations`
--
ALTER TABLE `otrs_evaluations`
  ADD CONSTRAINT `fk_eval_participant` FOREIGN KEY (`participant_id`) REFERENCES `otrs_participants` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_eval_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `otrs_evaluation_commitment_reasons`
--
ALTER TABLE `otrs_evaluation_commitment_reasons`
  ADD CONSTRAINT `fk_commit_reason_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `otrs_evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_evaluation_confidence_reasons`
--
ALTER TABLE `otrs_evaluation_confidence_reasons`
  ADD CONSTRAINT `fk_conf_reason_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `otrs_evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_evaluation_sme_ratings`
--
ALTER TABLE `otrs_evaluation_sme_ratings`
  ADD CONSTRAINT `fk_sme_rating_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `otrs_evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sme_rating_sme` FOREIGN KEY (`sme_id`) REFERENCES `otrs_training_smes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_hrd_calendar`
--
ALTER TABLE `otrs_hrd_calendar`
  ADD CONSTRAINT `fk_calendar_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `otrs_payment_logs`
--
ALTER TABLE `otrs_payment_logs`
  ADD CONSTRAINT `fk_paylog_reg` FOREIGN KEY (`registration_id`) REFERENCES `otrs_registrations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_proof_payment`
--
ALTER TABLE `otrs_proof_payment`
  ADD CONSTRAINT `fk_proof_participant` FOREIGN KEY (`participant_id`) REFERENCES `otrs_participants` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_proof_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `otrs_registrations`
--
ALTER TABLE `otrs_registrations`
  ADD CONSTRAINT `fk_reg_participant` FOREIGN KEY (`participant_id`) REFERENCES `otrs_participants` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reg_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `otrs_sme_training_days`
--
ALTER TABLE `otrs_sme_training_days`
  ADD CONSTRAINT `fk_sme_days_tsme` FOREIGN KEY (`training_sme_id`) REFERENCES `otrs_training_smes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_training_smes`
--
ALTER TABLE `otrs_training_smes`
  ADD CONSTRAINT `fk_smes_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_training_sme_profile` FOREIGN KEY (`sme_id`) REFERENCES `otrs_smes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `otrs_training_submissions`
--
ALTER TABLE `otrs_training_submissions`
  ADD CONSTRAINT `fk_sub_registration` FOREIGN KEY (`registration_id`) REFERENCES `otrs_registrations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otrs_training_suspensions`
--
ALTER TABLE `otrs_training_suspensions`
  ADD CONSTRAINT `fk_susp_training` FOREIGN KEY (`training_id`) REFERENCES `otrs_trainings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
