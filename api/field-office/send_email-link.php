<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../inc/mail_config.php';
require __DIR__ . '/../../../phpmailer/PHPMailer.php';
require __DIR__ . '/../../../phpmailer/SMTP.php';
require __DIR__ . '/../../../phpmailer/Exception.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['username'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
}

$trainingId    = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$enrollmentIds = json_decode($_POST['enrollmentIds'] ?? '[]', true);
$zoomLink      = trim($_POST['zoomLinkInput'] ?? '');
$zoomPDFFile   = $_FILES['zoomPDF']  ?? null;
$zoomBgFile    = $_FILES['zoomBg']   ?? null;

if (!$trainingId || empty($enrollmentIds) || !is_array($enrollmentIds)) {
    http_response_code(400);
    echo 'Invalid request parameters.';
    exit;
}

/* Fetch training details */
try {
    $stmtT = $conn->prepare("
        SELECT name, start_date, end_date
        FROM   otrs_trainings
        WHERE  id = :id
        LIMIT  1
    ");
    $stmtT->bindValue(':id', $trainingId, PDO::PARAM_INT);
    $stmtT->execute();
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Database error fetching training.';
    exit;
}

if (!$training) {
    http_response_code(404);
    echo 'Training not found.';
    exit;
}

$trainingName = $training['name'];

/* Build display date */
$trainingDate = '';
if (!empty($training['start_date']) && $training['start_date'] !== '0000-00-00') {
    try {
        $s = new DateTimeImmutable($training['start_date']);
        $e = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
             ? new DateTimeImmutable($training['end_date']) : null;
        if (!$e || $s->format('Y-m-d') === $e->format('Y-m-d')) {
            $trainingDate = $s->format('F j, Y');
        } elseif ($s->format('Y-m') === $e->format('Y-m')) {
            $trainingDate = $s->format('F j') . '–' . $e->format('j, Y');
        } else {
            $trainingDate = $s->format('F j') . ' – ' . $e->format('F j, Y');
        }
    } catch (Exception $ex) {
        $trainingDate = $training['start_date'];
    }
}

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
    $mail->isHTML(true);
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);

    $userLocation = strtolower($_SESSION['type'] ?? '');

    foreach ($enrollmentIds as $enrollmentId) {
        $enrollmentId = (int) $enrollmentId;
        if (!$enrollmentId) continue;

        try {
            $stmtP = $conn->prepare("
                SELECT p.fname, p.lname, p.email, r.payment_status
                FROM   otrs_registrations r
                JOIN   otrs_participants p ON p.id = r.participant_id
                WHERE  r.id = :eid AND r.training_id = :tid
                  AND  UPPER(p.location) = UPPER(:user_loc)
                LIMIT  1
            ");
            $stmtP->bindValue(':eid',      $enrollmentId, PDO::PARAM_INT);
            $stmtP->bindValue(':tid',      $trainingId,   PDO::PARAM_INT);
            $stmtP->bindValue(':user_loc', $userLocation);
            $stmtP->execute();
            $participant = $stmtP->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            continue;
        }

        if (!$participant || empty($participant['email'])) continue;
        if (!in_array($participant['payment_status'], ['paid', 'promissory note'], true)) continue;

        $mail->addAddress($participant['email'], $participant['fname'] . ' ' . $participant['lname']);
        $mail->Subject = 'Zoom Link for Training: ' . $trainingName;
        $mail->Body    = '<p style="font-family:Verdana,sans-serif;">Dear Mr/Ms. <b>' . htmlspecialchars($participant['fname']) . ' ' . htmlspecialchars($participant['lname']) . '</b>,<br></p>'
                       . '<p style="font-family:Verdana,sans-serif;">Good day, Ma\'am/Sir!<br></p>'
                       . '<p style="font-family:Verdana,sans-serif;">The <b>Civil Service Commission Regional Office VIII</b> is inviting you to a scheduled Zoom meeting. Please <b><u>rename</u></b> your Zoom account/devices with the Format: <b>First Name, Middle Initial, Last Name_Agency/Office</b>. <u>Kindly log in 15 minutes before 8:30 AM.</u><br></p>'
                       . '<p style="font-family:Verdana,sans-serif;">Zoom Link for the <b>' . htmlspecialchars($trainingName) . '</b> on <b>' . htmlspecialchars($trainingDate) . ':</b></p>'
                       . '<a style="font-family:Verdana,sans-serif;" href="' . htmlspecialchars($zoomLink) . '">' . htmlspecialchars($zoomLink) . '</a><br><br>'
                       . '<p style="font-family:Verdana,sans-serif;">Thank you and have a great training session!<br><br></p>'
                       . '<p style="font-family:Verdana,sans-serif;">Best regards,<br></p>'
                       . '<p style="font-family:Verdana,sans-serif;"><b>(Sgd.) JAY M. MERELOS</b><br>Chief Human Resource Specialist<br>Human Resource Division</p>';

        if ($zoomPDFFile && $zoomPDFFile['error'] === UPLOAD_ERR_OK) {
            $mail->addAttachment($zoomPDFFile['tmp_name'], $zoomPDFFile['name']);
        }
        if ($zoomBgFile && $zoomBgFile['error'] === UPLOAD_ERR_OK) {
            $mail->addAttachment($zoomBgFile['tmp_name'], $zoomBgFile['name']);
        }

        $participantDisplay = $participant['fname'] . ' ' . $participant['lname'];
        try {
            $mail->send();
            $sent++;
        } catch (Exception $mailEx) {
            $errors[] = $participantDisplay . ': ' . $mail->ErrorInfo;
            error_log('[send_email-link FO] ' . $mailEx->getMessage());
        }
        $mail->clearAddresses();
        $mail->clearAttachments();
    }
} catch (Exception $e) {
    error_log('[send_email-link FO setup] ' . $e->getMessage());
    echo json_encode(['success' => false, 'sent' => $sent, 'failed' => 1, 'errors' => ['Setup failed: ' . $e->getMessage()]]);
    exit;
}

echo json_encode(['success' => $sent > 0, 'sent' => $sent, 'failed' => count($errors), 'errors' => $errors]);
