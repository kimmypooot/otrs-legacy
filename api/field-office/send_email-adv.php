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

$trainingId  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$participants = json_decode($_POST['participants'] ?? '[]', true);

if (!$trainingId || empty($participants) || !is_array($participants)) {
    http_response_code(400);
    echo 'Invalid request parameters.';
    exit;
}

/* Fetch training details */
try {
    $stmtT = $conn->prepare("
        SELECT name, location, registration_link, fee, registration_deadline,
               start_date, end_date
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
$fee          = $training['fee'];
$deadline     = $training['registration_deadline'];

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

$deadlineFormat = $deadline ? date('F j, Y', strtotime($deadline)) : 'TBA';

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

    foreach ($participants as $participant) {
        $enrollmentId = (int) ($participant['id'] ?? 0);
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
            $pData = $stmtP->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            continue;
        }

        if (!$pData || empty($pData['email'])) continue;

        $mail->addAddress($pData['email'], $pData['fname'] . ' ' . $pData['lname']);
        $mail->Subject = 'Training Advisory - ' . $trainingName . ' - ' . $trainingDate;

        $participantStatus = $pData['payment_status'];

        $mail->Body  = "<span style='font-family:Verdana,sans-serif;'><span style='color:blue'><b>TRAINING ADVISORY</b></span></span><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>" . htmlspecialchars($trainingName) . "</span><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>" . htmlspecialchars($trainingDate) . "</span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>Good day, Ma'am/Sir: </span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>This is to inform you that your registration to attend the webinar on <b>" . htmlspecialchars($trainingName) . "</b> on <b>" . htmlspecialchars($trainingDate) . "</b> is hereby <b><u>confirmed</u></b>. </span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>Further, in our database, we noticed that your <b><u>registration fee</u></b> of <b><u>Php " . htmlspecialchars($fee) . "</u></b> is still tagged as <b><u>" . htmlspecialchars($participantStatus) . "</u></b>. In this regard, kindly settle your payment on or before " . $deadlineFormat . " or kindly present a Promissory Note.</span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>Please be guided by the following bank details for your payment:</span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'><i>Name of Bank: <b><u>Landbank of the Philippines</u></b></i></span><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'><i>Account Name: <b><u>Civil Service Commission</u></b></i></span><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'><i>Account Number: <b><u>018-211-0825</u></b></i></span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>Registered participants will be charged the amount equivalent to 80% of the registration fee if they fail to attend the training and/or failed to cancel their registration within seven (7) days before the conduct of the said training.</span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'><span style='color:blue'><b>Kindly disregard this email if you have already paid the said registration fee.</b></span></span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>For clarification, please call the CSC RO VIII - HRD at (053) 888-1811 or any of the CSC field offices.</span><br><br>";
        $mail->Body .= "<span style='font-family:Verdana,sans-serif;'>Thank you so much.</span>";

        $participantDisplay = ($participant['fname'] ?? '') . ' ' . ($participant['lname'] ?? '');
        try {
            $mail->send();
            $sent++;
        } catch (Exception $mailEx) {
            $errors[] = $participantDisplay . ': ' . $mail->ErrorInfo;
            error_log('[send_email-adv FO] ' . $mailEx->getMessage());
        }
        $mail->clearAllRecipients();
    }
} catch (Exception $e) {
    error_log('[send_email-adv FO setup] ' . $e->getMessage());
    echo json_encode(['success' => false, 'sent' => $sent, 'failed' => 1, 'errors' => ['Setup failed: ' . $e->getMessage()]]);
    exit;
}

echo json_encode(['success' => $sent > 0, 'sent' => $sent, 'failed' => count($errors), 'errors' => $errors]);
