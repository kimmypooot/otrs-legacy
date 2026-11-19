<?php
/**
 * hrd/api/send_email-bkup.php
 * Sends a database backup file as an email attachment to the specified address.
 * Admin/HRD-only endpoint — used by the DB backup management page.
 *
 * POST body: file (filename only, no path separators), email (recipient address)
 */
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/bootstrap.php';

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

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../../phpmailer/SMTP.php';
require_once __DIR__ . '/../../../phpmailer/Exception.php';
require_once __DIR__ . '/../../inc/mail_config.php';

// Accept only a plain filename — no directory separators
$file  = basename(trim($_POST['file']  ?? ''));
$email = trim($_POST['email'] ?? '');

if ($file === '' || $email === '') {
    echo json_encode(['success' => false, 'message' => 'File name and recipient email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid recipient email address.']);
    exit;
}

$filePath = 'db_backup/' . $file;
if (!is_file($filePath)) {
    echo json_encode(['success' => false, 'message' => 'Backup file not found.']);
    exit;
}

$currentDate = date('F j, Y');

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 30; // allow longer timeout for large files

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($email);
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    $mail->Subject = 'OTRS Database Backup – ' . $currentDate;
    $mail->Body    = "<!DOCTYPE html>
<html lang='en'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1.0'></head>
<body style='margin:0;padding:0;background-color:#f4f6f9;font-family:Verdana,Geneva,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f4f6f9;padding:30px 0;'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='background-color:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);'>
  <tr><td style='background-color:#012970;padding:22px 32px;text-align:center;'>
    <p style='margin:0;font-size:11px;color:#a0b4d0;letter-spacing:1.5px;text-transform:uppercase;'>Civil Service Commission &ndash; Regional Office VIII</p>
    <h1 style='margin:6px 0 0;font-size:16px;color:#fff;font-weight:700;'>OTRS Database Backup</h1>
  </td></tr>
  <tr><td style='padding:28px 32px;font-size:14px;color:#333;line-height:1.8;'>
    <p style='margin:0 0 14px;'>Attached is the automated database backup for the <b>Online Training Registration System (OTRS)</b>.</p>
    <table cellpadding='0' cellspacing='0' style='font-size:13px;color:#444;margin-bottom:14px;'>
      <tr><td style='padding:2px 0;width:110px;font-weight:700;'>Backup File:</td><td>" . htmlspecialchars($file, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</td></tr>
      <tr><td style='padding:2px 0;font-weight:700;'>Date:</td><td>{$currentDate}</td></tr>
    </table>
    <p style='margin:0;font-size:12px;color:#777;'>Please store this backup file securely. Do not share it with unauthorized personnel.</p>
  </td></tr>
  <tr><td style='background-color:#f4f6f9;border-top:1px solid #e0e8f0;padding:16px 32px;'>
    <p style='margin:0;font-size:10.5px;color:#999;line-height:1.5;'>This is a system-generated message from the CSC RO VIII OTRS. Please do not reply to this email.</p>
  </td></tr>
</table>
</td></tr>
</table>
</body></html>";
    $mail->AltBody = "OTRS Database Backup\n"
        . "Civil Service Commission – Regional Office VIII\n\n"
        . "Attached is the database backup for the Online Training Registration System (OTRS).\n\n"
        . "File: {$file}\nDate: {$currentDate}\n\n"
        . "Please store this backup file securely.";

    $mail->addAttachment($filePath, $file);
    $mail->send();

    echo json_encode(['success' => true, 'message' => "Backup file sent to {$email}."]);

} catch (Exception $e) {
    error_log('send_email-bkup.php Error: ' . $mail->ErrorInfo);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send backup email: ' . $mail->ErrorInfo]);
}
