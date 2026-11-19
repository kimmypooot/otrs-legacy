<?php
/**
 * api/email_templates/seed_defaults.php
 * One-time endpoint that inserts the four default HRD email templates.
 * Safe to call multiple times — uses INSERT IGNORE.
 * POST, HRD-only, CSRF required.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
$csrf = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$by = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// ── Create tables if they don't exist yet ────────────────────────
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `otrs_email_templates` (
          `id`            int(12)        NOT NULL AUTO_INCREMENT,
          `template_code` varchar(64)    NOT NULL,
          `template_name` varchar(128)   NOT NULL,
          `badge_label`   varchar(64)    NOT NULL DEFAULT '',
          `email_subject` varchar(255)   NOT NULL,
          `email_body`    longtext       NOT NULL,
          `email_altbody` text           NOT NULL DEFAULT '',
          `description`   varchar(255)   NOT NULL DEFAULT '',
          `status`        enum('active','inactive') NOT NULL DEFAULT 'active',
          `version`       int(11)        NOT NULL DEFAULT 1,
          `created_by`    varchar(64)    NOT NULL DEFAULT '',
          `created_at`    datetime       NOT NULL DEFAULT current_timestamp(),
          `updated_by`    varchar(64)    NOT NULL DEFAULT '',
          `updated_at`    datetime       NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_template_code` (`template_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `otrs_email_template_versions` (
          `id`            int(12)  NOT NULL AUTO_INCREMENT,
          `template_id`   int(12)  NOT NULL,
          `version`       int(11)  NOT NULL,
          `template_name` varchar(128) NOT NULL,
          `badge_label`   varchar(64)  NOT NULL DEFAULT '',
          `email_subject` varchar(255) NOT NULL,
          `email_body`    longtext     NOT NULL,
          `email_altbody` text         NOT NULL DEFAULT '',
          `changed_by`    varchar(64)  NOT NULL,
          `changed_at`    datetime     NOT NULL DEFAULT current_timestamp(),
          `change_note`   varchar(255) NOT NULL DEFAULT '',
          PRIMARY KEY (`id`),
          KEY `idx_etv_template` (`template_id`),
          KEY `idx_etv_ver`      (`template_id`, `version`),
          CONSTRAINT `fk_etv_template`
            FOREIGN KEY (`template_id`) REFERENCES `otrs_email_templates` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `otrs_email_template_logs` (
          `id`            bigint(20) NOT NULL AUTO_INCREMENT,
          `template_id`   int(12)    DEFAULT NULL,
          `template_code` varchar(64) NOT NULL,
          `action`        enum('created','updated','activated','deactivated',
                               'test_sent','bulk_sent','restored','seeded') NOT NULL,
          `performed_by`  varchar(64) NOT NULL,
          `performed_at`  datetime    NOT NULL DEFAULT current_timestamp(),
          `details`       text        NOT NULL DEFAULT '',
          `ip_address`    varchar(45) NOT NULL DEFAULT '',
          PRIMARY KEY (`id`),
          KEY `idx_etl_template`     (`template_id`),
          KEY `idx_etl_performed_at` (`performed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    ");
} catch (PDOException $e) {
    error_log('[seed_defaults] DDL error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create tables: ' . $e->getMessage()]);
    exit;
}

$templates = [

/* ── 1. Registration Acknowledgment ──────────────────────────── */
[
'template_code' => 'registration_ack',
'template_name' => 'Registration Acknowledgment',
'badge_label'   => 'Registration Acknowledgment',
'email_subject' => 'Registration Acknowledgment – {training_title}',
'description'   => 'Sent to participants immediately after registration. Confirms receipt and lists required submission documents.',
'email_body'    => <<<'HTML'
<p style="margin:0 0 16px;font-size:14px;color:#333;">Dear <b>{participant_name}</b>,</p>

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">
  Greetings from the <b>CSC RO VIII &ndash; Human Resource Division!</b>
</p>

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">
  The CSC RO VIII &ndash; Human Resource Division acknowledges your registration in our scheduled training entitled
  <b><i>{training_title}</i></b> at <b>{venue}</b> with a registration fee amounting to <b>{payment_amount}</b>.
</p>

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">
  By personally registering for the above-mentioned training, it is understood that
  <b><i>you agree to the terms and conditions of the training and are bound by the Data Privacy Policy</i></b>.
  To properly validate your attendance and as a basis for the issuance of the Certificate of Completion,
  the Human Resource Division requests that you submit the following:
</p>

<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 16px;">
  <tr>
    <td style="background-color:#eaf1fb;border-left:4px solid #012970;border-radius:4px;padding:14px 18px;">
      <ol style="margin:0;padding-left:20px;font-size:13px;color:#222;line-height:1.9;">
        <li><b>Scanned copy of proof of payment</b> or a <b>promissory note</b> of your intention to pay the corresponding registration fee;</li>
        <li><b>Scanned copy of a government-issued or valid ID with photo</b>
          <i>(Note: Your data shall be treated with the utmost confidentiality and used only for the above-cited purposes.)</i>; and</li>
        <li><b>Office Order from the Head of Agency</b> permitting you to attend,
          <b>or an Approved Leave of Absence</b> if attending at personal expense.</li>
      </ol>
    </td>
  </tr>
</table>

{registration_link_block}

<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 16px;">
  <tr>
    <td style="background-color:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:12px 16px;">
      <p style="margin:0;font-size:13px;color:#5a4a00;line-height:1.7;">
        This is a <b>first-to-pay, first-served basis</b> training.
        The deadline for registration is <b>{payment_deadline}</b>.
      </p>
    </td>
  </tr>
</table>

{penalty_note}
{contact_line}
{bank_details_block}

<p style="margin:0;font-size:13px;color:#555;font-style:italic;line-height:1.7;">
  <b>Note:</b> Please disregard this information if you have already submitted the required documents.
  You may send the scanned copy of your receipt of payment to the concerned CSC Field Office nearest you.
  <b>The concerned CSC Field Office will issue an official receipt of your payment and update the HRD.</b>
</p>

{signature_block}
HTML,
'email_altbody' => <<<'TEXT'
REGISTRATION ACKNOWLEDGMENT
Civil Service Commission – Regional Office VIII
Human Resource Division
------------------------------------------------------------
Dear {participant_name},

Greetings from the CSC RO VIII – Human Resource Division!

The CSC RO VIII – HRD acknowledges your registration for:
  Training : {training_title}
  Venue    : {venue}
  Fee      : {payment_amount}

By registering, you agree to the training's terms and conditions and the Data Privacy Policy.
Please submit the following:
  1. Scanned copy of proof of payment or promissory note
  2. Scanned copy of government-issued ID
  3. Office Order or Approved Leave of Absence

Registration deadline: {payment_deadline}

Registered participants will be charged 80% of the registration fee
if they fail to attend and/or fail to cancel within 7 days before the training.

For clarification, call CSC RO VIII – HRD at (053) 888-1811.

--- Bank Details ---
Bank           : Landbank of the Philippines
Account Name   : Civil Service Commission
Account Number : 018-211-0825
Proof of payment: https://otrs.cscro8.com/proof_payment

Note: Disregard this if you have already submitted the required documents.

Sincerely yours,
(Sgd.) JAY M. MERELOS
Chief Human Resource Specialist
Human Resource Division
Civil Service Commission – Regional Office VIII
TEXT,
],

/* ── 2. Training Advisory (Payment) ─────────────────────────── */
[
'template_code' => 'training_advisory',
'template_name' => 'Training Advisory',
'badge_label'   => 'Training Advisory',
'email_subject' => 'Training Advisory – {training_title}',
'description'   => 'Confirms registration and shows current payment status. Includes bank details for unpaid participants.',
'email_body'    => <<<'HTML'
<p style="margin:0 0 16px;font-size:14px;color:#333;">Dear <b>{participant_name}</b>,</p>

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">
  Good day! This is to inform you that your registration to attend the
  <b style="color:#012970;">{training_title}</b>
  on <b>{training_date}</b> is hereby <b><u>confirmed</u></b>.
</p>

{payment_status_badge}

{bank_details_block}
{penalty_note}
{contact_line}

<p style="margin:0;font-size:13px;font-weight:700;color:#0066cc;line-height:1.7;">
  Kindly disregard this email if you have already paid the registration fee.
</p>

{signature_block}
HTML,
'email_altbody' => <<<'TEXT'
TRAINING ADVISORY
Civil Service Commission – Regional Office VIII
Human Resource Division
------------------------------------------------------------
Dear {participant_name},

Good day! Your registration for {training_title} on {training_date} is confirmed.

Payment Status : {payment_status}
Registration Fee: {payment_amount}

Please settle your payment on or before {payment_deadline}, or present a Promissory Note.

--- Bank Details ---
Bank           : Landbank of the Philippines
Account Name   : Civil Service Commission
Account Number : 018-211-0825
Proof of payment: https://otrs.cscro8.com/proof_payment

Registered participants will be charged 80% of the registration fee
if they fail to attend and/or fail to cancel within 7 days before the training.

For clarification, call CSC RO VIII – HRD at (053) 888-1811.

Kindly disregard this email if you have already paid.

Sincerely yours,
(Sgd.) JAY M. MERELOS
Chief Human Resource Specialist
Human Resource Division
Civil Service Commission – Regional Office VIII
TEXT,
],

/* ── 3. Online Training Access Link ─────────────────────────── */
[
'template_code' => 'online_training_access',
'template_name' => 'Online Training Access Link',
'badge_label'   => 'Online Training Access',
'email_subject' => 'Online Training Access Link – {training_title}',
'description'   => 'Sends Zoom or online meeting link to paid/promissory-note participants.',
'email_body'    => <<<'HTML'
<p style="margin:0 0 16px;font-size:14px;color:#333;">Dear <b>{participant_name}</b>,</p>

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">
  Good day! The <b>Civil Service Commission Regional Office VIII</b> is inviting you
  to a scheduled online training session.
</p>

<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 18px;">
  <tr>
    <td style="background-color:#eaf1fb;border-left:4px solid #012970;border-radius:4px;padding:14px 18px;">
      <p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#012970;">Training Details</p>
      <table cellpadding="0" cellspacing="0" style="font-size:13px;color:#1e3a5f;">
        <tr><td style="padding:2px 0;width:80px;font-weight:700;">Training:</td><td>{training_title}</td></tr>
        <tr><td style="padding:2px 0;font-weight:700;">Date:</td><td>{training_date}</td></tr>
      </table>
    </td>
  </tr>
</table>

<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 18px;">
  <tr>
    <td style="background-color:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:14px 18px;">
      <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#856404;">Before Joining</p>
      <ul style="margin:0;padding-left:20px;font-size:13px;color:#5a4a00;line-height:1.9;">
        <li>Please <b>rename</b> your Zoom account/device: <b>First Name, Middle Initial, Last Name_Agency/Office</b></li>
        <li>Kindly log in <b>15 minutes before 8:30 AM</b>.</li>
      </ul>
    </td>
  </tr>
</table>

{training_link_block}

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">Thank you and have a great training session!</p>

{signature_block}
HTML,
'email_altbody' => <<<'TEXT'
ONLINE TRAINING ACCESS LINK
Civil Service Commission – Regional Office VIII
Human Resource Division
------------------------------------------------------------
Dear {participant_name},

Good day! CSC RO VIII is inviting you to an online training session.

Training : {training_title}
Date     : {training_date}

Before joining:
  - Rename your Zoom: First Name, Middle Initial, Last Name_Agency/Office
  - Log in 15 minutes before 8:30 AM

Access link: {training_link}

Thank you and have a great training session!

Sincerely yours,
(Sgd.) JAY M. MERELOS
Chief Human Resource Specialist
Human Resource Division
Civil Service Commission – Regional Office VIII
TEXT,
],

/* ── 4. Evaluation Form Link ─────────────────────────────────── */
[
'template_code' => 'evaluation_form_link',
'template_name' => 'Training Evaluation Form Link',
'badge_label'   => 'Training Evaluation Form',
'email_subject' => 'Evaluation Form Link – {training_title}',
'description'   => 'Sends personalized HMAC-signed evaluation links per training day.',
'email_body'    => <<<'HTML'
<p style="margin:0 0 14px;font-size:14px;color:#333;">Dear <b>{participant_name}</b>,</p>

<p style="margin:0 0 18px;font-size:14px;color:#333;line-height:1.7;">
  Greetings from the <b>CSC RO VIII &ndash; Human Resource Division!</b>
  Please use the personalized link(s) below to complete your evaluation for the training program you attended.
</p>

{training_info_block}
{evaluation_links_table}

<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 20px;">
  <tr>
    <td style="background-color:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:14px 18px;">
      <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#856404;">Important Notes</p>
      <ul style="margin:0;padding-left:18px;font-size:13px;color:#5a4a00;line-height:1.9;">
        <li>Each link is <b>personalized</b> &mdash; do not share it with others.</li>
        <li>Links are only accessible on or after the designated evaluation day.</li>
        <li>You must complete all evaluation sessions to receive your certificate.</li>
      </ul>
    </td>
  </tr>
</table>

<p style="margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;">
  If you have any concerns, please contact the HRD team directly.
  Thank you for your participation and continued commitment to public service.
</p>

{signature_block}
HTML,
'email_altbody' => <<<'TEXT'
TRAINING EVALUATION FORM LINK
Civil Service Commission – Regional Office VIII
Human Resource Division
------------------------------------------------------------
Dear {participant_name},

Please complete your training evaluation using the personalized link(s) below.

Training : {training_title}
Date     : {training_date}

Important:
  - Each link is personalized — do not share it with others.
  - Links are only accessible on or after the designated evaluation day.
  - You must complete all evaluation sessions to receive your certificate.

Thank you for your participation!

Sincerely yours,
(Sgd.) JAY M. MERELOS
Chief Human Resource Specialist
Human Resource Division
Civil Service Commission – Regional Office VIII
TEXT,
],

]; // end $templates

try {
    $conn->beginTransaction();
    $inserted = 0;

    $stmt = $conn->prepare("
        INSERT IGNORE INTO otrs_email_templates
            (template_code, template_name, badge_label, email_subject,
             email_body, email_altbody, description, status, version,
             created_by, created_at, updated_by, updated_at)
        VALUES
            (:code, :name, :badge, :subj, :body, :alt, :desc,
             'active', 1, :created_by, NOW(), :updated_by, NOW())
    ");
    $log = $conn->prepare("
        INSERT INTO otrs_email_template_logs
            (template_id, template_code, action, performed_by, performed_at, details, ip_address)
        VALUES (:tid, :tcode, 'seeded', :performed_by, NOW(), :details, :ip)
    ");

    foreach ($templates as $t) {
        $stmt->execute([
            ':code'       => $t['template_code'],
            ':name'       => $t['template_name'],
            ':badge'      => $t['badge_label'],
            ':subj'       => $t['email_subject'],
            ':body'       => $t['email_body'],
            ':alt'        => $t['email_altbody'],
            ':desc'       => $t['description'],
            ':created_by' => $by,
            ':updated_by' => $by,
        ]);
        if ($conn->lastInsertId()) {
            $inserted++;
            $log->execute([
                ':tid'          => (int) $conn->lastInsertId(),
                ':tcode'        => $t['template_code'],
                ':performed_by' => $by,
                ':details'      => json_encode(['name' => $t['template_name']]),
                ':ip'           => $ip,
            ]);
        }
    }

    $conn->commit();
    echo json_encode([
        'success'  => true,
        'message'  => "{$inserted} default template(s) seeded. " . (count($templates) - $inserted) . ' already existed.',
        'inserted' => $inserted,
        'skipped'  => count($templates) - $inserted,
    ]);
} catch (PDOException $e) {
    $conn->rollBack();
    error_log('[seed_defaults] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error during seeding.']);
}
