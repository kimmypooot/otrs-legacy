<?php
// imis/otrs/hrd/api/ccs_email_ajax.php
// POST: cert_id — send one CCS certificate PDF to the participant via email.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../inc/mail_config.php';
require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../../phpmailer/SMTP.php';
require_once __DIR__ . '/../../../phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
}

$certId = filter_input(INPUT_POST, 'cert_id', FILTER_VALIDATE_INT);
if (!$certId) {
    exit(json_encode(['success' => false, 'error' => 'Invalid certificate ID.']));
}

// ── Fetch cert + participant email ─────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        SELECT c.id, c.certificate_number, c.participant_name, c.training_title,
               c.start_date, c.end_date, c.certificate_file, c.source, c.category,
               p.email, p.fname, p.lname, p.mname, p.suffix
          FROM otrs_ccs_certificates c
          LEFT JOIN otrs_participants p ON p.id = c.participant_id
         WHERE c.id = :id AND c.status != 'voided'
         LIMIT 1
    ");
    $stmt->execute([':id' => $certId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ccs_email_ajax] fetch: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'error' => 'Failed to fetch certificate.']));
}

if (!$rec) {
    exit(json_encode(['success' => false, 'error' => 'Certificate not found or voided.']));
}

$email = trim($rec['email'] ?? '');
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['success' => false, 'error' => 'No valid email address on file for this participant.']));
}

// Build display name — prefer structured fields from otrs_participants
if (!empty($rec['fname'])) {
    $mi       = !empty($rec['mname']) ? strtoupper($rec['mname'][0]) . '. ' : '';
    $sfx      = !empty(trim($rec['suffix'] ?? '')) ? ', ' . trim($rec['suffix']) : '';
    $fullName = trim("{$rec['fname']} {$mi}{$rec['lname']}{$sfx}");
} else {
    $fullName = $rec['participant_name'];
}

// ── Resolve absolute path to PDF ───────────────────────────────────────────────
$certFile    = $rec['certificate_file'];
$certAbsPath = false;
if ($certFile) {
    $rel = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $certFile), DIRECTORY_SEPARATOR);
    $candidates = [
        realpath(__DIR__ . '/../../assets/uploads/certificates') . DIRECTORY_SEPARATOR . $rel,
        realpath(__DIR__ . '/../../../../assets/uploads/certificates') . DIRECTORY_SEPARATOR . $rel,
    ];
    foreach ($candidates as $candidate) {
        if ($candidate && file_exists($candidate)) { $certAbsPath = $candidate; break; }
    }
}

if ($certAbsPath === false) {
    exit(json_encode(['success' => false, 'error' => 'Certificate PDF file not found on server. Please re-issue it first.']));
}

// ── Build friendly date string ─────────────────────────────────────────────────
$fmtDate     = fn($d) => date('F j, Y', strtotime($d));
$displayDate = '';
if ($rec['start_date'] && $rec['start_date'] !== '0000-00-00') {
    $displayDate = ($rec['end_date'] && $rec['end_date'] !== '0000-00-00' && $rec['end_date'] !== $rec['start_date'])
        ? $fmtDate($rec['start_date']) . ' – ' . $fmtDate($rec['end_date'])
        : $fmtDate($rec['start_date']);
}

// ── Compose & send ─────────────────────────────────────────────────────────────
$categoryLabel = ucfirst($rec['category'] ?? 'Completion');
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host        = SMTP_HOST;
    $mail->SMTPAuth    = true;
    $mail->Username    = SMTP_USERNAME;
    $mail->Password    = SMTP_PASSWORD;
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port        = SMTP_PORT;
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($email, $fullName);
    $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

    $pdfSlug = preg_replace('/[^A-Za-z0-9\-]/', '_', $rec['certificate_number']
        ?? preg_replace('/[^A-Za-z0-9\- ]/', '', $rec['participant_name']));
    $pdfName = 'Certificate_' . $pdfSlug . '.pdf';
    $mail->addAttachment($certAbsPath, $pdfName);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Your Certificate of {$categoryLabel} – {$rec['training_title']}";
    $mail->Body    = _ccsBuildEmailHtml($fullName, $rec['training_title'], $displayDate, $rec['certificate_number'] ?? '', $categoryLabel);
    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $mail->Body));
    $mail->send();

    $u = $conn->prepare("UPDATE otrs_ccs_certificates SET email_status = 'sent', emailed_at = NOW() WHERE id = :id");
    $u->execute([':id' => $certId]);

    echo json_encode(['success' => true, 'email' => $email, 'name' => $fullName]);
} catch (Exception $e) {
    error_log('[ccs_email_ajax] mailer: ' . $mail->ErrorInfo);
    $u = $conn->prepare("UPDATE otrs_ccs_certificates SET email_status = 'failed' WHERE id = :id");
    $u->execute([':id' => $certId]);
    echo json_encode(['success' => false, 'error' => 'Email failed: ' . htmlspecialchars($mail->ErrorInfo)]);
}

// ── Email HTML template ────────────────────────────────────────────────────────
function _ccsBuildEmailHtml(string $name, string $training, string $date, string $certNo, string $category): string
{
    $dateRow = $date ? "<p style='margin:4px 0 0;color:#555;font-size:.88rem;'>Date: {$date}</p>" : '';
    $certRow = $certNo !== ''
        ? "<p>Your certificate number is: <code style=\"background:#eef2ff;padding:3px 8px;border-radius:4px;font-size:.9rem;color:#012970;\">{$certNo}</code></p>"
        : '';
    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">
<tr><td style="background:#012970;padding:22px 28px;">
    <p style="margin:0;color:#fff;font-size:1.15rem;font-weight:700;">Civil Service Commission</p>
    <p style="margin:4px 0 0;color:#a8c0df;font-size:.82rem;">Regional Office VIII — Eastern Visayas</p>
</td></tr>
<tr><td style="padding:28px 28px 8px;">
    <p>Dear <strong>{$name}</strong>,</p>
    <p>Please find attached your <strong>Certificate of {$category}</strong> for the following training/activity:</p>
    <div style="background:#f0f4ff;border-left:4px solid #0077b6;padding:14px 18px;margin:16px 0;border-radius:5px;">
        <p style="margin:0;font-weight:700;color:#012970;">{$training}</p>
        {$dateRow}
    </div>
    {$certRow}
    <p style="margin-top:24px;color:#555;">For verification or concerns, please contact our office directly.</p>
    <p style="margin-top:8px;">Sincerely,<br><strong>CSC RO VIII Training Unit</strong><br>
    <span style="font-size:.82rem;color:#888;">cscro8.imis@gmail.com</span></p>
</td></tr>
<tr><td style="background:#f8f9fc;padding:14px 28px;text-align:center;border-top:1px solid #ebeef4;">
    <p style="margin:0;font-size:.75rem;color:#aaa;">Civil Service Commission Regional Office VIII &bull; Eastern Visayas, Philippines</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}
