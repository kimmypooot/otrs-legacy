<?php
/**
 * hrd/api/send_email-link.php
 * Sends Zoom/online meeting access link to selected participants.
 * Only dispatches to participants whose payment_status is 'paid' or 'promissory note'.
 * Optional file attachments: Zoom guide PDF and Zoom background image.
 * HRD-only endpoint.
 *
 * POST body: participantIds (JSON array of registration IDs), zoomLinkInput
 * POST files: zoomPDF (optional), zoomBg (optional)
 * GET param:  id (training ID)
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

if (!isset($_POST['participantIds'])) {
    echo json_encode(['success' => false, 'message' => 'No participants selected.']);
    exit;
}

$participantIds = json_decode($_POST['participantIds'], true);
$zoomLink       = trim($_POST['zoomLinkInput'] ?? '');

if (!is_array($participantIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant data.']);
    exit;
}

$zoomPDFFile = (isset($_FILES['zoomPDF']) && $_FILES['zoomPDF']['error'] === UPLOAD_ERR_OK) ? $_FILES['zoomPDF'] : null;
$zoomBgFile  = (isset($_FILES['zoomBg'])  && $_FILES['zoomBg']['error']  === UPLOAD_ERR_OK) ? $_FILES['zoomBg']  : null;

$sent   = 0;
$errors = [];

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
    $mail->CharSet    = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);

    if (!isset($_GET['id'])) {
        throw new Exception('Training ID is not provided.');
    }
    $trainingId = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT ot.id, ot.name, ot.start_date, ot.end_date, ot.location
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
    $eTraining      = hrdEmailEsc($trainingName);
    $eZoomLink      = hrdEmailEsc($zoomLink);

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

        if (!$p) continue;

        // Only send to paid or promissory note participants
        $status = strtolower($p['payment_status'] ?? '');
        if ($status !== 'paid' && $status !== 'promissory note') {
            error_log("send_email-link: Skipped reg ID {$pId} — status: {$p['payment_status']}");
            continue;
        }

        $sfx            = !empty(trim($p['suffix'] ?? '')) ? ', ' . trim($p['suffix']) : '';
        $fullName       = trim($p['fname'] . ' ' . $p['lname'] . $sfx);
        $recipientEmail = $p['email'];
        $eName          = hrdEmailEsc($fullName);

        // ── HTML body ──────────────────────────────────────────────────────
        $bodyRows = hrdEmailHeader('Online Training Access');
        $bodyRows .= "
  <tr>
    <td style='padding:30px 36px 24px;'>
      <p style='margin:0 0 16px;font-size:14px;color:#333;'>Dear <b>{$eName}</b>,</p>

      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        Good day! The <b>Civil Service Commission Regional Office VIII</b> is inviting
        you to a scheduled online training session.
      </p>

      <!-- Training info box -->
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 18px;'>
        <tr>
          <td style='background-color:#eaf1fb;border-left:4px solid #012970;border-radius:4px;padding:14px 18px;'>
            <p style='margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#012970;'>Training Details</p>
            <table cellpadding='0' cellspacing='0' style='font-size:13px;color:#1e3a5f;'>
              <tr><td style='padding:2px 0;width:80px;font-weight:700;'>Training:</td><td>{$eTraining}</td></tr>
              " . ($trainingDate ? "<tr><td style='padding:2px 0;font-weight:700;'>Date:</td><td>{$trainingDate}</td></tr>" : '') . "
            </table>
          </td>
        </tr>
      </table>

      <!-- Instructions box -->
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 18px;'>
        <tr>
          <td style='background-color:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:14px 18px;'>
            <p style='margin:0 0 6px;font-size:12px;font-weight:700;color:#856404;'>Before Joining</p>
            <ul style='margin:0;padding-left:20px;font-size:13px;color:#5a4a00;line-height:1.9;'>
              <li>Please <b>rename</b> your Zoom account/device using the format:<br>
                  <b>First Name, Middle Initial, Last Name_Agency/Office</b></li>
              <li>Kindly log in <b>15 minutes before 8:30 AM</b>.</li>
            </ul>
          </td>
        </tr>
      </table>

      " . ($zoomLink ? "
      <!-- Zoom link CTA -->
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 18px;'>
        <tr>
          <td style='background-color:#eaf7f0;border-left:4px solid #28a745;border-radius:4px;padding:14px 18px;'>
            <p style='margin:0 0 8px;font-size:13px;font-weight:700;color:#1a5c32;'>Your Access Link</p>
            <a href='{$eZoomLink}' style='display:inline-block;background-color:#012970;color:#ffffff;font-size:13px;font-weight:700;padding:10px 22px;border-radius:4px;text-decoration:none;margin-bottom:8px;'>
              Join Training
            </a>
            <p style='margin:8px 0 0;font-size:11px;color:#555;word-break:break-all;'>{$eZoomLink}</p>
          </td>
        </tr>
      </table>" : '') . "

      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        Thank you and have a great training session!
      </p>

      " . hrdEmailSignature() . "
    </td>
  </tr>";
        $bodyRows .= hrdEmailFooter();

        $mail->addAddress($recipientEmail, $fullName);
        $mail->Subject = 'Online Training Access Link – ' . $trainingName . ($trainingDateTx ? ' – ' . $trainingDateTx : '');
        $mail->Body    = hrdEmailWrap($bodyRows);
        $mail->AltBody = implode("\n", [
            "ONLINE TRAINING ACCESS LINK",
            "Civil Service Commission – Regional Office VIII",
            "Human Resource Division",
            str_repeat('-', 60),
            "Dear {$fullName},",
            '',
            "Good day! CSC RO VIII is inviting you to a scheduled online training session.",
            '',
            "Training : {$trainingName}",
            ($trainingDateTx ? "Date     : {$trainingDateTx}" : ''),
            '',
            "Before joining:",
            "  - Rename your Zoom account: First Name, Middle Initial, Last Name_Agency/Office",
            "  - Log in 15 minutes before 8:30 AM",
            '',
            ($zoomLink ? "Access link: {$zoomLink}" : ''),
            '',
            "Thank you and have a great training session!",
            '',
            "Sincerely yours,",
            "(Sgd.) JAY M. MERELOS",
            "Chief Human Resource Specialist",
            "Human Resource Division",
            "Civil Service Commission – Regional Office VIII",
        ]);

        if ($zoomPDFFile) $mail->addAttachment($zoomPDFFile['tmp_name'], $zoomPDFFile['name']);
        if ($zoomBgFile)  $mail->addAttachment($zoomBgFile['tmp_name'],  $zoomBgFile['name']);

        try {
            $mail->send();
            $sent++;

            $by = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
            $logStmt = $conn->prepare("
                INSERT INTO otrs_email_logs (registration_id, training_id, email_type, sent_by, recipient_email)
                VALUES (:reg_id, :training_id, 'zoom_link', :sent_by, :email)
            ");
            $logStmt->execute([
                ':reg_id'     => $pId,
                ':training_id'=> $trainingId,
                ':sent_by'    => $by,
                ':email'      => $recipientEmail,
            ]);
        } catch (Exception $e) {
            error_log("send_email-link: Failed for {$recipientEmail}: " . $mail->ErrorInfo);
            $errors[] = "Failed for {$fullName}: " . $mail->ErrorInfo;
        }

        $mail->clearAddresses();
        $mail->clearAttachments();
    }

    echo json_encode([
        'success' => $sent > 0,
        'sent'    => $sent,
        'failed'  => count($errors),
        'errors'  => $errors,
    ]);

} catch (Exception $e) {
    error_log('send_email-link.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Email sending failed: ' . $e->getMessage()]);
}
