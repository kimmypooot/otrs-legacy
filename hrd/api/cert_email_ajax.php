<?php
// imis/otrs/hrd/cert_email_ajax.php
// AJAX endpoint: send certificate PDFs to participants via email
// Expects POST: trainingId (int), participantIds[] (int[])

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../inc/mail_config.php';

// PHPMailer
require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../../phpmailer/SMTP.php';
require_once __DIR__ . '/../../../phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Input validation ────────────────────────────────────────────
$trainingId     = filter_input(INPUT_POST, 'trainingId', FILTER_VALIDATE_INT);
$participantIds = $_POST['participantIds'] ?? [];

if (!$trainingId || empty($participantIds)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit;
}

// Sanitize IDs
$participantIds = array_values(array_filter(array_map('intval', $participantIds)));
if (empty($participantIds)) {
    echo json_encode(['success' => false, 'error' => 'No valid participant IDs provided.']);
    exit;
}

// ── Fetch training ──────────────────────────────────────────────
try {
    $stmtT = $conn->prepare("
        SELECT ot.name, ot.start_date, ot.end_date, ot.location, ot.fee
        FROM otrs_trainings ot
        WHERE ot.id = :id
        LIMIT 1
    ");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        echo json_encode(['success' => false, 'error' => 'Training not found.']);
        exit;
    }
} catch (PDOException $e) {
    error_log('[cert_email_ajax] Fetch training: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch training data.']);
    exit;
}

// Build friendly training date for email body
$displayDate = '';
if (!empty($training['start_date']) && $training['start_date'] !== '0000-00-00') {
    $s = new DateTime($training['start_date']);
    $e = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
       ? new DateTime($training['end_date']) : $s;
    if ($s == $e)
        $displayDate = $s->format('F j, Y');
    elseif ($s->format('Y') !== $e->format('Y'))
        $displayDate = $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
    elseif ($s->format('m') !== $e->format('m'))
        $displayDate = $s->format('M j') . ' – ' . $e->format('M j, Y');
    else
        $displayDate = $s->format('F j') . '–' . $e->format('j, Y');
}

// ── Fetch certificates for requested participants ────────────────
$inPlaceholders = implode(',', array_fill(0, count($participantIds), '?'));

try {
    $stmtC = $conn->prepare("
        SELECT
            c.id AS cert_id,
            c.certificate_number,
            c.certificate_file,
            c.participant_id,
            p.fname,
            p.mname,
            p.lname,
            p.suffix,
            p.email,
            p.position_title AS positiontitle,
            p.agency_name
        FROM otrs_certificates c
        INNER JOIN otrs_participants p ON p.id = c.participant_id
        WHERE c.training_id = ?
          AND c.participant_id IN ({$inPlaceholders})
          AND c.status = 'active'
        ORDER BY p.lname, p.fname
    ");
    $stmtC->execute(array_merge([$trainingId], $participantIds));
    $certRecords = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[cert_email_ajax] Fetch certs: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch certificate records.']);
    exit;
}

if (empty($certRecords)) {
    echo json_encode([
        'success' => false,
        'error'   => 'No issued certificates found for the selected participants.'
    ]);
    exit;
}

// Resolve certificate path — checks canonical public_html/assets/... first,
// then imis/assets/... as fallback for certs issued between the api/ move and this fix.
function resolveCertPath(string $relPath): string|false {
    $rel = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath), DIRECTORY_SEPARATOR);
    foreach ([
        realpath(__DIR__ . '/../../assets/uploads/certificates'),       // current: imis/otrs/assets/...
        realpath(__DIR__ . '/../../../assets/uploads/certificates'),    // legacy: imis/assets/...
        realpath(__DIR__ . '/../../../../assets/uploads/certificates'), // legacy: public_html/assets/...
    ] as $base) {
        if ($base === false) continue;
        $candidate = $base . DIRECTORY_SEPARATOR . $rel;
        if (file_exists($candidate)) return $candidate;
    }
    return false;
}

// ── Send emails ─────────────────────────────────────────────────
$results   = [];
$errors    = [];
$now       = date('Y-m-d H:i:s');

foreach ($certRecords as $rec) {
    $pid             = (int) $rec['participant_id'];
    $certId          = (int) $rec['cert_id'];
    $email           = trim($rec['email'] ?? '');
    $middleInitial   = !empty($rec['mname']) ? strtoupper($rec['mname'][0]) . '. ' : '';
    $suffixPart      = !empty(trim($rec['suffix'] ?? '')) ? ', ' . trim($rec['suffix']) : '';
    $fullName        = trim("{$rec['fname']} {$middleInitial}{$rec['lname']}{$suffixPart}");
    $certNumber      = $rec['certificate_number'];
    $certFile        = $rec['certificate_file'];
    $certAbsPath     = resolveCertPath($certFile);

    // ── Validate email ──────────────────────────────────────────
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[]  = "No valid email for <strong>{$fullName}</strong>.";
        $results[] = ['pid' => $pid, 'success' => false, 'error' => 'No valid email.'];

        // Mark as failed in DB
        _updateEmailStatus($conn, $certId, 'failed', null);
        continue;
    }

    // ── Validate PDF file ───────────────────────────────────────
    if (empty($certFile) || $certAbsPath === false) {
        $errors[]  = "Certificate file missing for <strong>{$fullName}</strong>.";
        $results[] = ['pid' => $pid, 'success' => false, 'error' => 'Certificate file not found.'];
        _updateEmailStatus($conn, $certId, 'failed', null);
        continue;
    }

    // ── Compose & send ──────────────────────────────────────────
    $mail = new PHPMailer(true);

    try {
        // SMTP
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Sender / Recipient
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email, $fullName);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        // Attachment
        $pdfFileName = 'Certificate_' . preg_replace('/[^A-Za-z0-9\-]/', '_', $certNumber) . '.pdf';
        $mail->addAttachment($certAbsPath, $pdfFileName);

        // Content
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Subject  = "Your Certificate of Completion – {$training['name']}";
        $mail->Body     = _buildEmailHtml($fullName, $training['name'], $displayDate, $certNumber, $rec['positiontitle'], $rec['agency_name']);
        $mail->AltBody  = _buildEmailText($fullName, $training['name'], $displayDate, $certNumber);

        $mail->send();

        // Update DB
        _updateEmailStatus($conn, $certId, 'sent', $now);

        $results[] = ['pid' => $pid, 'success' => true];

    } catch (Exception $e) {
        $errMsg = $mail->ErrorInfo;
        error_log("[cert_email_ajax] Mailer error for PID {$pid}: {$errMsg}");
        $errors[]  = "Failed to send email to <strong>{$fullName}</strong>: " . htmlspecialchars($errMsg);
        $results[] = ['pid' => $pid, 'success' => false, 'error' => $errMsg];
        _updateEmailStatus($conn, $certId, 'failed', null);
    }

    // Small pause to avoid SMTP throttling for large batches
    usleep(300000); // 0.3 s
}

$sentCount = count(array_filter($results, fn($r) => $r['success']));

echo json_encode([
    'success' => $sentCount > 0,
    'results' => $results,
    'errors'  => $errors,
    'sent'    => $sentCount,
]);
exit;

// ── Helpers ─────────────────────────────────────────────────────

/**
 * Update emailed_at / email_status on the certificate record.
 */
function _updateEmailStatus(PDO $conn, int $certId, string $status, ?string $emailedAt): void
{
    try {
        if ($emailedAt !== null) {
            $stmt = $conn->prepare(
                "UPDATE otrs_certificates SET email_status = :s, emailed_at = :ea WHERE id = :id"
            );
            $stmt->execute([':s' => $status, ':ea' => $emailedAt, ':id' => $certId]);
        } else {
            $stmt = $conn->prepare(
                "UPDATE otrs_certificates SET email_status = :s WHERE id = :id"
            );
            $stmt->execute([':s' => $status, ':id' => $certId]);
        }
    } catch (PDOException $e) {
        error_log('[cert_email_ajax] updateEmailStatus: ' . $e->getMessage());
    }
}

/**
 * Build HTML email body.
 */
function _buildEmailHtml(
    string $fullName,
    string $trainingName,
    string $trainingDate,
    string $certNumber,
    string $positionTitle,
    string $agencyName
): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificate of Completion</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:'Poppins',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

        <!-- Header Banner -->
        <tr>
          <td style="background:linear-gradient(135deg,#0077b6 0%,#023e8a 100%);padding:32px 40px;text-align:center;">
            <img src="https://otrs.cscro8.com/assets/img/csclogo-email.png"
              alt="CSC Logo"
              width="50"
              style="display:block;background:#fff;padding:6px;margin:0 auto 12px auto;border-radius:50%;"
              onerror="this.style.display='none'">
            <div style="color:#fff;font-size:13px;font-weight:600;letter-spacing:1.5px;
                        text-transform:uppercase;opacity:.8;margin-bottom:4px;
                        font-family:'Poppins',Arial,sans-serif;">
              Civil Service Commission
            </div>
            <div style="color:#fff;font-size:22px;font-weight:700;line-height:1.25;
                        font-family:'Poppins',Arial,sans-serif;">
              Regional Office VIII
            </div>
            <div style="color:#90caf9;font-size:13px;margin-top:4px;
                        font-family:'Poppins',Arial,sans-serif;">
              Human Resource Division
            </div>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:40px 40px 32px;">
            <p style="color:#0077b6;font-size:13px;font-weight:600;text-transform:uppercase;
                       letter-spacing:1.2px;margin:0 0 8px;
                       font-family:'Poppins',Arial,sans-serif;">Certificate of Completion</p>
            <p style="color:#1a1a2e;font-size:16px;margin:0 0 24px;line-height:1.6;
                      font-family:'Poppins',Arial,sans-serif;">
              Dear <strong>{$fullName}</strong>,
            </p>
            <p style="color:#333;font-size:15px;line-height:1.75;margin:0 0 20px;
                      font-family:'Poppins',Arial,sans-serif;">
              Congratulations! Please find attached your
              <strong>Certificate of Completion</strong> for successfully completing the
              following training program:
            </p>

            <!-- Training Info Box -->
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#eef4ff;border-left:4px solid #0077b6;border-radius:0 8px 8px 0;
                          margin-bottom:24px;">
              <tr>
                <td style="padding:20px 24px;">
                  <div style="color:#012970;font-size:16px;font-weight:700;margin-bottom:12px;
                              line-height:1.35;font-family:'Poppins',Arial,sans-serif;">{$trainingName}</div>
                  <table cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding-right:8px;padding-bottom:6px;">
                        <span style="background:#0077b6;color:#fff;font-size:11px;font-weight:700;
                                     padding:2px 8px;border-radius:20px;
                                     font-family:'Poppins',Arial,sans-serif;">📅 Date</span>
                      </td>
                      <td style="color:#444;font-size:13px;padding-bottom:6px;
                                 font-family:'Poppins',Arial,sans-serif;">{$trainingDate}</td>
                    </tr>
                    <tr>
                      <td style="padding-right:8px;padding-bottom:6px;">
                        <span style="background:#0077b6;color:#fff;font-size:11px;font-weight:700;
                                     padding:2px 8px;border-radius:20px;
                                     font-family:'Poppins',Arial,sans-serif;">🎓 Cert No.</span>
                      </td>
                      <td style="color:#444;font-size:13px;font-family:'Poppins',Arial,sans-serif;
                                 padding-bottom:6px;">
                        {$certNumber}
                      </td>
                    </tr>
                    <tr>
                      <td style="padding-right:8px;">
                        <span style="background:#0077b6;color:#fff;font-size:11px;font-weight:700;
                                     padding:2px 8px;border-radius:20px;
                                     font-family:'Poppins',Arial,sans-serif;">🏢 Agency</span>
                      </td>
                      <td style="color:#444;font-size:13px;
                                 font-family:'Poppins',Arial,sans-serif;">{$agencyName}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <p style="color:#333;font-size:14px;line-height:1.75;margin:0 0 16px;
                      font-family:'Poppins',Arial,sans-serif;">
              Your certificate is attached to this email as a <strong>PDF file</strong>.
              Please keep a copy for your personal records. You may present this certificate
              as proof of training completion.
            </p>
            <p style="color:#555;font-size:14px;line-height:1.75;margin:0 0 32px;
                      font-family:'Poppins',Arial,sans-serif;">
              Should you have any questions or concerns regarding your certificate,
              please do not hesitate to contact us at
              <a href="mailto:cscro8.imis@gmail.com" style="color:#0077b6;text-decoration:none;
                 font-family:'Poppins',Arial,sans-serif;">
                cscro8.imis@gmail.com
              </a>.
            </p>

            <!-- CTA note -->
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#fff8ec;border:1px solid #fde8c0;border-radius:8px;margin-bottom:8px;">
              <tr>
                <td style="padding:14px 20px;font-size:13px;color:#7a4700;
                           font-family:'Poppins',Arial,sans-serif;">
                  <strong>📎 Note:</strong> Your certificate PDF is attached to this email.
                  Please ensure your email client is configured to display attachments.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Sign-off -->
        <tr>
          <td style="padding:0 40px 32px;">
            <p style="color:#333;font-size:14px;line-height:1.75;margin:0 0 4px;
                      font-family:'Poppins',Arial,sans-serif;">
              Warm regards,
            </p>
            <p style="color:#012970;font-size:15px;font-weight:700;margin:0 0 2px;
                      font-family:'Poppins',Arial,sans-serif;">
              Human Resource Division
            </p>
            <p style="color:#0077b6;font-size:13px;margin:0;
                      font-family:'Poppins',Arial,sans-serif;">
              Civil Service Commission Regional Office VIII
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8faff;border-top:1px solid #e8eef8;
                     padding:18px 40px;text-align:center;">
            <p style="color:#adb5bd;font-size:11px;margin:0;
                      font-family:'Poppins',Arial,sans-serif;">
              This is an automated email. Please do not reply directly to this message.<br>
              &copy; {$year} Civil Service Commission – Regional Office VIII. All rights reserved.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

/**
 * Build plain-text fallback email body.
 */
function _buildEmailText(
    string $fullName,
    string $trainingName,
    string $trainingDate,
    string $certNumber
): string {
    $year = date('Y');
    return <<<TEXT
Certificate of Completion
Civil Service Commission Regional Office VIII
Human Resource Division

Dear {$fullName},

Congratulations! Please find attached your Certificate of Completion for the following training:

Training : {$trainingName}
Date     : {$trainingDate}
Cert No. : {$certNumber}

Your certificate is attached to this email as a PDF file. Please keep a copy for your records.

For questions, contact us at cscro8.imis@gmail.com.

Warm regards,
Human Resource Division
Civil Service Commission Regional Office VIII

---
This is an automated email. Please do not reply directly.
© {$year} Civil Service Commission Regional Office VIII
TEXT;
}