<?php
/**
 * hrd/api/send_email-adv.php
 * Sends a payment advisory to selected participants.
 * Email content is driven by the 'training_advisory' template in otrs_email_templates.
 * Falls back to inline HTML if the template is missing or inactive.
 *
 * POST body: participantIds (JSON array of registration IDs)
 * GET param: id (training ID)
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
require_once __DIR__ . '/../../inc/email_template.php';
require_once __DIR__ . '/../../inc/email_template_engine.php';

if (!isset($_POST['participantIds'])) {
    echo json_encode(['success' => false, 'message' => 'No participants selected.']);
    exit;
}
$participantIds = json_decode($_POST['participantIds'], true);
if (!is_array($participantIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant data.']);
    exit;
}

$sent   = 0;
$errors = [];
$by     = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';

// Fetch template (may be null if not seeded yet)
$tpl = getEmailTemplate('training_advisory', $conn);

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 15;
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    if (!isset($_GET['id'])) {
        throw new Exception('Training ID is not provided.');
    }
    $trainingId = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT ot.id, ot.name, ot.start_date, ot.end_date, ot.fee,
               ot.registration_deadline AS deadline
        FROM   otrs_trainings ot
        WHERE  ot.id = :id
        LIMIT  1
    ");
    $stmt->bindParam(':id', $trainingId, PDO::PARAM_INT);
    $stmt->execute();
    $training = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$training) {
        throw new Exception('Training not found.');
    }

    $trainingName   = $training['name'];
    $trainingDate   = hrdEmailFormatDate($training['start_date'], $training['end_date']);
    $trainingDateTx = hrdEmailFormatDatePlain($training['start_date'], $training['end_date']);
    $feeDisplay     = hrdEmailFormatFee($training['fee']);
    $deadline       = $training['deadline'];
    $deadlineLabel  = ($deadline && $deadline !== '0000-00-00')
        ? date('F j, Y', strtotime($deadline))
        : 'TBA';

    foreach ($participantIds as $pId) {

        $stmt = $conn->prepare("
            SELECT p.fname, p.mname, p.lname, p.suffix, p.email,
                   r.payment_status
            FROM   otrs_participants  p
            JOIN   otrs_registrations r ON r.participant_id = p.id
            WHERE  r.id = :reg_id
            LIMIT  1
        ");
        $stmt->bindParam(':reg_id', $pId, PDO::PARAM_INT);
        $stmt->execute();
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            error_log("send_email-adv: Registration ID {$pId} not found.");
            continue;
        }

        $sfx            = !empty(trim($p['suffix'] ?? '')) ? ', ' . trim($p['suffix']) : '';
        $fullName       = trim($p['fname'] . ' ' . $p['lname'] . $sfx);
        $recipientEmail = $p['email'];
        $rawStatus      = $p['payment_status'];
        $statusLabel    = hrdEmailFormatStatus($rawStatus);
        $isPaid         = strtolower($rawStatus) === 'paid';
        $eName          = hrdEmailEsc($fullName);

        // Per-participant payment status badge block
        $badgeBg     = $isPaid ? '#d4edda' : '#fff3cd';
        $badgeBorder = $isPaid ? '#28a745' : '#ffc107';
        $badgeColor  = $isPaid ? '#155724' : '#856404';
        $deadlineHtml = !$isPaid
            ? "<p style='margin:8px 0 0;font-size:13px;color:#856404;line-height:1.7;'>
                 Please settle your payment on or before <b>{$deadlineLabel}</b>,
                 or present a <b>Promissory Note</b>.</p>"
            : '';
        $deadlineTx = !$isPaid
            ? "Please settle your payment on or before {$deadlineLabel}, or present a Promissory Note."
            : '';

        $paymentStatusBadge = "
          <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 18px;'>
            <tr><td style='background-color:{$badgeBg};border-left:4px solid {$badgeBorder};border-radius:4px;padding:14px 18px;'>
              <p style='margin:0 0 6px;font-size:13px;font-weight:700;color:{$badgeColor};'>Payment Status</p>
              <p style='margin:0;font-size:13px;color:{$badgeColor};line-height:1.7;'>
                Your registration fee of <b>{$feeDisplay}</b> is currently tagged as:
                <b><u>{$statusLabel}</u></b>.
              </p>
              {$deadlineHtml}
            </td></tr>
          </table>";

        $vars = [
            'participant_name'    => $eName,
            'training_title'      => hrdEmailEsc($trainingName),
            'training_date'       => $trainingDate ?: '',
            'payment_amount'      => $feeDisplay,
            'payment_status'      => $statusLabel,
            'payment_deadline'    => $deadlineLabel,
            'payment_status_badge'=> $paymentStatusBadge,
        ];

        $emailSubjectSuffix = $trainingName . ($trainingDate ? ' – ' . strip_tags(str_replace('&ndash;', '–', $trainingDate)) : '');

        if ($tpl && $tpl['status'] === 'active') {
            // ── Template engine render ─────────────────────────────────────
            $rendered       = renderEmailTemplate($tpl, $vars);
            $mail->Subject  = 'Training Advisory – ' . $emailSubjectSuffix;
            $mail->Body     = $rendered['body'];
            $mail->AltBody  = etReplace($tpl['email_altbody'], $vars);
        } else {
            // ── Inline fallback (original behaviour) ───────────────────────
            $bodyRows = hrdEmailHeader('Training Advisory');
            $bodyRows .= "
  <tr>
    <td style='padding:30px 36px 24px;'>
      <p style='margin:0 0 16px;font-size:14px;color:#333;'>Dear <b>{$eName}</b>,</p>
      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        Good day! This is to inform you that your registration to attend the
        <b style='color:#012970;'>" . hrdEmailEsc($trainingName) . "</b>
        " . ($trainingDate ? "on <b>{$trainingDate}</b>" : '') . "
        is hereby <b><u>confirmed</u></b>.
      </p>
      {$paymentStatusBadge}
      " . hrdEmailBankBlock() . "
      " . hrdEmailPenaltyNote() . "
      " . hrdEmailContact() . "
      <p style='margin:0;font-size:13px;font-weight:700;color:#0066cc;line-height:1.7;'>
        Kindly disregard this email if you have already paid the registration fee.
      </p>
      " . hrdEmailSignature() . "
    </td>
  </tr>";
            $bodyRows .= hrdEmailFooter();
            $mail->Subject = 'Training Advisory – ' . $emailSubjectSuffix;
            $mail->Body    = hrdEmailWrap($bodyRows);
            $mail->AltBody = implode("\n", [
                "TRAINING ADVISORY — CSC RO VIII HRD",
                str_repeat('-', 60),
                "Dear {$fullName},",
                '', "Your registration for {$trainingName}" . ($trainingDateTx ? " on {$trainingDateTx}" : '') . " is confirmed.",
                '', "Payment Status: {$statusLabel}", "Fee: {$feeDisplay}",
                ($deadlineTx ? $deadlineTx : ''),
                '', "Bank: Landbank | Account: Civil Service Commission | No: 018-211-0825",
                "Proof upload: https://otrs.cscro8.com/proof_payment",
                '', "80% penalty for no-shows without 7-day cancellation. Call (053) 888-1811.",
                '', "Disregard if already paid.",
                '', "(Sgd.) JAY M. MERELOS — Chief HRS, HRD, CSC RO VIII",
            ]);
        }

        $mail->addAddress($recipientEmail, $fullName);
        try {
            $mail->send();
            $sent++;

            if ($tpl) {
                logEmailTemplateAction($conn, (int)$tpl['id'], $tpl['template_code'], 'bulk_sent', $by,
                    json_encode(['training_id' => $trainingId, 'reg_id' => $pId, 'to' => $recipientEmail]), $ip);
            }

            $logStmt = $conn->prepare("
                INSERT INTO otrs_email_logs (registration_id, training_id, email_type, sent_by, recipient_email)
                VALUES (:reg_id, :training_id, 'advisory', :sent_by, :email)
            ");
            $logStmt->execute([
                ':reg_id'     => $pId,
                ':training_id'=> $trainingId,
                ':sent_by'    => $by,
                ':email'      => $recipientEmail,
            ]);
        } catch (Exception $e) {
            error_log("Mailer Error for {$recipientEmail}: " . $mail->ErrorInfo);
            $errors[] = "Failed for {$fullName}: " . $mail->ErrorInfo;
        }

        $mail->clearAddresses();
    }

    echo json_encode([
        'success' => $sent > 0,
        'sent'    => $sent,
        'failed'  => count($errors),
        'errors'  => $errors,
    ]);

} catch (Exception $e) {
    error_log('send_email-adv.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Email sending failed: ' . $e->getMessage()]);
}
