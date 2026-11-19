<?php
/**
 * hrd/api/send_eval_link_email.php
 * POST { training_id, reg_id, email?, day_filter? }
 *
 * Sends evaluation form link(s) to a participant via email.
 * If day_filter (int) is provided, only that day's link is sent.
 * Generates HMAC-signed links and delivers them via the HRD SMTP account.
 */
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../../phpmailer/SMTP.php';
require_once __DIR__ . '/../../../phpmailer/Exception.php';
require_once __DIR__ . '/../../inc/mail_config.php';
require_once __DIR__ . '/../../inc/email_template.php';

$payload    = json_decode(file_get_contents('php://input'), true) ?: [];
$trainingId = filter_var($payload['training_id'] ?? null, FILTER_VALIDATE_INT);
$regId      = filter_var($payload['reg_id']      ?? null, FILTER_VALIDATE_INT);
$emailOverride = trim($payload['email'] ?? '');
$dayFilter  = isset($payload['day_filter']) ? filter_var($payload['day_filter'], FILTER_VALIDATE_INT) : null;

if (!$trainingId || !$regId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Missing training_id or reg_id.']);
    exit;
}

if ($emailOverride !== '' && !filter_var($emailOverride, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
    exit;
}

if ($dayFilter !== null && $dayFilter === false) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid day_filter value.']);
    exit;
}

function evalLinkSig(int $regId, int $dayNum, string $email, string $regDate): string
{
    $key = $email . substr($regDate, 0, 10);
    return substr(hash_hmac('sha256', "{$regId}:{$dayNum}", $key), 0, 32);
}

function evalBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'imis.cscro8.com';
    // Script is at imis/otrs/hrd/api/ — go up 3 levels to reach imis/otrs/
    $script = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
    return rtrim("{$scheme}://{$host}{$script}", '/');
}

function fmtDateRangeEmail(?string $s, ?string $e): string
{
    if (!$s || $s === '0000-00-00') return '';
    try {
        $sd = new DateTimeImmutable($s);
        $ed = ($e && $e !== '0000-00-00') ? new DateTimeImmutable($e) : null;
        if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('F j, Y');
        if ($sd->format('Y-m') === $ed->format('Y-m')) return $sd->format('F j') . '–' . $ed->format('j, Y');
        return $sd->format('F j') . ' – ' . $ed->format('F j, Y');
    } catch (Exception $ex) { return htmlspecialchars($s); }
}

try {
    // Load registration + participant + training
    $stmt = $conn->prepare("
        SELECT r.id AS reg_id, r.training_id, r.participant_id,
               r.registration_status, r.payment_status,
               DATE(r.created_at) AS reg_date,
               TRIM(CONCAT(p.fname, ' ',
                   CASE WHEN p.mname IS NOT NULL AND p.mname <> '' THEN CONCAT(LEFT(TRIM(p.mname),1), '. ') ELSE '' END,
                   p.lname,
                   CASE WHEN p.suffix IS NOT NULL AND p.suffix <> '' THEN CONCAT(', ', p.suffix) ELSE '' END
               )) AS full_name,
               p.email AS participant_email,
               t.name AS training_name, t.start_date, t.end_date, t.mode, t.location
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        JOIN   otrs_trainings     t ON t.id = r.training_id
        WHERE  r.id = :rid AND r.training_id = :tid
        LIMIT  1
    ");
    $stmt->execute([':rid' => $regId, ':tid' => $trainingId]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        echo json_encode(['status' => 'error', 'message' => 'Registration not found for this training.']);
        exit;
    }

    if (in_array(strtolower($reg['registration_status']), ['cancelled', 'canceled'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'This registration has been cancelled.']);
        exit;
    }

    // Compute total training days
    $sd       = new DateTime($reg['start_date']);
    $ed       = (!empty($reg['end_date']) && $reg['end_date'] !== '0000-00-00') ? new DateTime($reg['end_date']) : clone $sd;
    $totalDays = (int)$ed->diff($sd)->days + 1;

    // Compute eval days (last assigned day per SME)
    $stmtSme = $conn->prepare("
        SELECT COALESCE(MAX(std.day_number), :total_days) AS eval_day
        FROM   otrs_training_smes ts
        LEFT JOIN otrs_sme_training_days std ON std.training_sme_id = ts.id
        WHERE  ts.training_id = :tid
        GROUP BY ts.id
    ");
    $stmtSme->execute([':total_days' => $totalDays, ':tid' => $trainingId]);
    $evalDays = array_unique(array_map('intval', $stmtSme->fetchAll(PDO::FETCH_COLUMN)));
    sort($evalDays);

    if (empty($evalDays)) {
        echo json_encode(['status' => 'error', 'message' => 'No evaluation days computed yet. Please assign SMEs and their day assignments first.']);
        exit;
    }

    // Apply day_filter if provided
    if ($dayFilter !== null) {
        if (!in_array($dayFilter, $evalDays, true)) {
            echo json_encode(['status' => 'error', 'message' => "Day {$dayFilter} is not a valid evaluation day for this training."]);
            exit;
        }
        $evalDays = [$dayFilter];
    }

    // Determine recipient email
    $recipientEmail = $emailOverride !== '' ? $emailOverride : $reg['participant_email'];
    $participantName = $reg['full_name'];
    $trainingName    = $reg['training_name'];
    $trainingDate    = fmtDateRangeEmail($reg['start_date'], $reg['end_date']);
    $location        = $reg['location'];
    $regDate         = $reg['reg_date'];
    $base            = evalBaseUrl();

    // Build HMAC-signed links for each eval day
    $linkItems = '';
    foreach ($evalDays as $dn) {
        $sig  = evalLinkSig($regId, $dn, $reg['participant_email'], $regDate);
        $url  = "{$base}/evaluation_form.php?reg_id={$regId}&day={$dn}&sig={$sig}";
        $linkItems .= '<tr>
            <td style="padding:10px 14px;font-size:13px;color:#333;border-bottom:1px solid #d0dff0;white-space:nowrap;font-weight:700;">Day ' . $dn . '</td>
            <td style="padding:10px 14px;font-size:13px;border-bottom:1px solid #d0dff0;">
                <a href="' . htmlspecialchars($url) . '" style="color:#012970;word-break:break-all;">' . htmlspecialchars($url) . '</a>
            </td>
        </tr>';
    }
    $dayCount = count($evalDays);
    $dayWord  = $dayCount === 1 ? 'evaluation session' : "{$dayCount} evaluation sessions";
    $subject  = $dayFilter !== null
        ? "Day {$dayFilter} Evaluation Form Link – {$trainingName}"
        : "Evaluation Form Link – {$trainingName}";

    // Build email body using shared template
    $eName      = htmlspecialchars($participantName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $eTraining  = htmlspecialchars($trainingName,    ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $eLocation  = htmlspecialchars($location ?? '',  ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $bodyRows = hrdEmailHeader('Training Evaluation Form');
    $bodyRows .= "
  <tr>
    <td style='padding:30px 36px 24px;'>
      <p style='margin:0 0 14px;font-size:14px;color:#333;'>Dear <b>{$eName}</b>,</p>
      <p style='margin:0 0 18px;font-size:14px;color:#333;line-height:1.7;'>
        Greetings from the <b>CSC RO VIII &ndash; Human Resource Division!</b>
        Please use the personalized link(s) below to complete your evaluation for the training program you attended.
      </p>

      <!-- Training info -->
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 20px;'>
        <tr>
          <td style='background-color:#eaf1fb;border-left:4px solid #012970;border-radius:4px;padding:14px 18px;'>
            <p style='margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#012970;'>Training Details</p>
            <table cellpadding='0' cellspacing='0' style='font-size:13px;color:#1e3a5f;'>
              <tr><td style='padding:2px 10px 2px 0;font-weight:700;white-space:nowrap;'>Training:</td><td style='padding:2px 0;'>{$eTraining}</td></tr>"
      . ($trainingDate ? "<tr><td style='padding:2px 10px 2px 0;font-weight:700;white-space:nowrap;'>Date:</td><td style='padding:2px 0;'>" . htmlspecialchars($trainingDate) . "</td></tr>" : '')
      . ($location     ? "<tr><td style='padding:2px 10px 2px 0;font-weight:700;white-space:nowrap;'>Venue:</td><td style='padding:2px 0;'>{$eLocation}</td></tr>" : '') . "
            </table>
          </td>
        </tr>
      </table>

      <!-- Links section -->
      <p style='margin:0 0 8px;font-size:13px;font-weight:700;color:#012970;'>
        Your Evaluation Link" . ($dayCount > 1 ? 's' : '') . "
        <span style='font-size:11px;font-weight:400;color:#666;'>({$dayWord})</span>
      </p>
      <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #d0dff0;border-radius:6px;overflow:hidden;margin-bottom:20px;'>
        <thead>
          <tr style='background-color:#eaf1fb;'>
            <th style='padding:10px 14px;font-size:12px;font-weight:700;text-align:left;color:#012970;text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #d0dff0;'>Session</th>
            <th style='padding:10px 14px;font-size:12px;font-weight:700;text-align:left;color:#012970;text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #d0dff0;'>Evaluation Link</th>
          </tr>
        </thead>
        <tbody>{$linkItems}</tbody>
      </table>

      <!-- Important notes -->
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 20px;'>
        <tr>
          <td style='background-color:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:14px 18px;'>
            <p style='margin:0 0 8px;font-size:12px;font-weight:700;color:#856404;'>Important Notes</p>
            <ul style='margin:0;padding-left:18px;font-size:13px;color:#5a4a00;line-height:1.9;'>
              <li>Each link is <b>personalized</b> &mdash; do not share it with others.</li>
              <li>Links are only accessible on or after the designated evaluation day.</li>
              <li>You must complete all evaluation sessions to receive your certificate.</li>
            </ul>
          </td>
        </tr>
      </table>

      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        If you have any concerns, please contact the HRD team directly.
        Thank you for your participation and continued commitment to public service.
      </p>

      " . hrdEmailSignature() . "
    </td>
  </tr>";
    $bodyRows .= hrdEmailFooter();

    $body = hrdEmailWrap($bodyRows, 640);

    // Send via PHPMailer
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = SMTP_PORT;
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($recipientEmail, $participantName);
    $mail->CharSet  = 'UTF-8';
    $mail->isHTML(true);
    $mail->Subject  = $subject;
    $mail->Body     = $body;
    $mail->AltBody  = "Evaluation Form Link – {$trainingName}\n\n"
        . "Dear {$participantName},\n\n"
        . "Please complete your training evaluation using the personalized link(s) below:\n\n"
        . implode("\n", array_map(function ($dn) use ($regId, $regDate, $reg, $base) {
            $sig = evalLinkSig($regId, $dn, $reg['participant_email'], $regDate);
            return "Day {$dn}: {$base}/evaluation_form.php?reg_id={$regId}&day={$dn}&sig={$sig}";
        }, $evalDays))
        . "\n\nThank you,\nHuman Resource Division\nCSC Regional Office VIII";

    $mail->send();

    error_log(sprintf(
        '[send_eval_link_email] Sent to %s (reg_id=%d, training_id=%d, days=%s%s) by %s',
        $recipientEmail, $regId, $trainingId, implode(',', $evalDays),
        $dayFilter !== null ? " [day_filter={$dayFilter}]" : '',
        $_SESSION['username'] ?? 'unknown'
    ));

    $sentLabel = $dayFilter !== null
        ? "Day {$dayFilter} evaluation link"
        : "Evaluation link" . ($dayCount > 1 ? 's' : '');
    echo json_encode([
        'status'    => 'success',
        'message'   => "{$sentLabel} sent to {$recipientEmail}.",
        'recipient' => $recipientEmail,
        'days_sent' => $evalDays,
    ]);

} catch (\PHPMailer\PHPMailer\Exception $e) {
    error_log('[send_eval_link_email] PHPMailer error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to send email. Please try again or contact the system administrator.']);
} catch (PDOException $e) {
    error_log('[send_eval_link_email] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
