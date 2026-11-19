<?php
/**
 * hrd/api/send_email-ack.php
 * Sends a registration acknowledgment letter to selected participants.
 * Email content is driven by the 'registration_ack' template in otrs_email_templates.
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
$tpl = getEmailTemplate('registration_ack', $conn);

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
        SELECT ot.id, ot.name, ot.start_date, ot.end_date, ot.location, ot.fee,
               ot.registration_link AS link, ot.registration_deadline AS deadline
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
    $location       = $training['location'];
    $feeDisplay     = hrdEmailFormatFee($training['fee']);
    $link           = $training['link'] ?? '';
    $deadline       = $training['deadline'];
    $deadlineLabel  = ($deadline && $deadline !== '0000-00-00')
        ? date('F j, Y', strtotime($deadline))
        : 'until all available slots are filled';

    foreach ($participantIds as $pId) {

        $stmt = $conn->prepare("
            SELECT p.fname, p.mname, p.lname, p.suffix, p.email
            FROM   otrs_participants  p
            JOIN   otrs_registrations r ON r.participant_id = p.id
            WHERE  r.id = :reg_id
            LIMIT  1
        ");
        $stmt->bindParam(':reg_id', $pId, PDO::PARAM_INT);
        $stmt->execute();
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;

        $sfx            = !empty(trim($p['suffix'] ?? '')) ? ', ' . trim($p['suffix']) : '';
        $fullName       = trim($p['fname'] . ' ' . $p['lname'] . $sfx);
        $recipientEmail = $p['email'];
        $eName          = hrdEmailEsc($fullName);
        $eLink          = hrdEmailEsc($link);

        // Build the optional registration-link button block
        $registrationLinkBlock = $link ? "
          <p style='margin:0 0 16px;font-size:14px;color:#333;line-height:1.7;'>
            Please send your documents through the registration link below:
          </p>
          <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 20px;text-align:center;'>
            <tr><td>
              <a href='{$eLink}' style='display:inline-block;background-color:#012970;color:#ffffff;font-size:13px;font-weight:700;padding:12px 28px;border-radius:5px;text-decoration:none;'>
                Send Your Attachments Here
              </a>
              <p style='margin:8px 0 0;font-size:11px;color:#777;'>{$eLink}</p>
            </td></tr>
          </table>" : '';

        $vars = [
            'participant_name'        => $eName,
            'training_title'          => hrdEmailEsc($trainingName),
            'training_date'           => $trainingDate ?: '',
            'venue'                   => hrdEmailEsc($location),
            'payment_amount'          => $feeDisplay,
            'payment_deadline'        => $deadlineLabel,
            'registration_link_block' => $registrationLinkBlock,
        ];

        $emailSubjectSuffix = $trainingName . ($trainingDate ? ' – ' . strip_tags(str_replace('&ndash;', '–', $trainingDate)) : '');

        if ($tpl && $tpl['status'] === 'active') {
            // ── Template engine render ─────────────────────────────────────
            $rendered       = renderEmailTemplate($tpl, $vars);
            $mail->Subject  = 'Registration Acknowledgment – ' . $emailSubjectSuffix;
            $mail->Body     = $rendered['body'];
            $mail->AltBody  = etReplace($tpl['email_altbody'], $vars);
        } else {
            // ── Inline fallback (original behaviour) ───────────────────────
            $bodyRows = hrdEmailHeader('Registration Acknowledgment');
            $bodyRows .= "
  <tr>
    <td style='padding:30px 36px 24px;'>
      <p style='margin:0 0 16px;font-size:14px;color:#333;'>Dear <b>{$eName}</b>,</p>
      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        Greetings from the <b>CSC RO VIII &ndash; Human Resource Division!</b>
      </p>
      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        The CSC RO VIII &ndash; HRD acknowledges your registration in our scheduled training entitled
        <b><i>" . hrdEmailEsc($trainingName) . "</i></b> at <b>" . hrdEmailEsc($location) . "</b>
        with a registration fee amounting to <b>{$feeDisplay}</b>.
      </p>
      <p style='margin:0 0 14px;font-size:14px;color:#333;line-height:1.7;'>
        By personally registering for the above-mentioned training, it is understood that
        <b><i>you agree to the terms and conditions of the training and are bound by the Data Privacy Policy</i></b>.
        To properly validate your attendance and as a basis for the issuance of the Certificate of Completion,
        the Human Resource Division requests that you submit the following:
      </p>
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 16px;'>
        <tr><td style='background-color:#eaf1fb;border-left:4px solid #012970;border-radius:4px;padding:14px 18px;'>
          <ol style='margin:0;padding-left:20px;font-size:13px;color:#222;line-height:1.9;'>
            <li><b>Scanned copy of proof of payment</b> or a <b>promissory note</b>;</li>
            <li><b>Scanned copy of a government-issued or valid ID with photo</b>; and</li>
            <li><b>Office Order from the Head of Agency</b> or an <b>Approved Leave of Absence</b>.</li>
          </ol>
        </td></tr>
      </table>
      {$registrationLinkBlock}
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 16px;'>
        <tr><td style='background-color:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:12px 16px;'>
          <p style='margin:0;font-size:13px;color:#5a4a00;line-height:1.7;'>
            This is a <b>first-to-pay, first-served basis</b> training.
            The deadline for registration is <b>{$deadlineLabel}</b>.
          </p>
        </td></tr>
      </table>
      " . hrdEmailPenaltyNote() . hrdEmailContact() . hrdEmailBankBlock() . "
      <p style='margin:0;font-size:13px;color:#555;font-style:italic;line-height:1.7;'>
        <b>Note:</b> Please disregard this if you have already submitted the required documents.
      </p>
      " . hrdEmailSignature() . "
    </td>
  </tr>";
            $bodyRows .= hrdEmailFooter();
            $mail->Subject = 'Registration Acknowledgment – ' . $emailSubjectSuffix;
            $mail->Body    = hrdEmailWrap($bodyRows);
            $mail->AltBody = implode("\n", [
                "REGISTRATION ACKNOWLEDGMENT — CSC RO VIII HRD",
                str_repeat('-', 60),
                "Dear {$fullName},",
                '', "Greetings from the CSC RO VIII – Human Resource Division!",
                '', "Training: {$trainingName}", "Venue: {$location}", "Fee: {$feeDisplay}",
                "Deadline: {$deadlineLabel}",
                '', "Submit: 1. Proof of payment / promissory note  2. Gov-issued ID  3. Office Order",
                ($link ? "\nLink: {$link}" : ''),
                '', "Bank: Landbank | Account: Civil Service Commission | No: 018-211-0825",
                "Proof upload: https://otrs.cscro8.com/proof_payment",
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
                VALUES (:reg_id, :training_id, 'ack', :sent_by, :email)
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
    error_log('send_email-ack.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Email sending failed: ' . $e->getMessage()]);
}
