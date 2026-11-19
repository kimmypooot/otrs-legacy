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

$trainingId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$enrollmentIds = json_decode($_POST['enrollmentIds'] ?? '[]', true);

if (!$trainingId || empty($enrollmentIds) || !is_array($enrollmentIds)) {
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

$trainingName      = $training['name'];
$locationTraining  = $training['location'];
$link              = $training['registration_link'];
$fee               = $training['fee'];
$deadline          = $training['registration_deadline'];

/* Format date range for display */
function formatDateRange(string $start, ?string $end): string
{
    if (empty($start) || $start === '0000-00-00') return '';
    try {
        $s = new DateTimeImmutable($start);
        $e = ($end && $end !== '0000-00-00') ? new DateTimeImmutable($end) : null;
        if (!$e || $s->format('Y-m-d') === $e->format('Y-m-d')) return $s->format('F j, Y');
        if ($s->format('Y-m') === $e->format('Y-m')) return $s->format('F j') . '–' . $e->format('j, Y');
        return $s->format('F j') . ' – ' . $e->format('F j, Y');
    } catch (Exception $ex) {
        return htmlspecialchars($start);
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

    foreach ($enrollmentIds as $enrollmentId) {
        $enrollmentId = (int) $enrollmentId;
        if (!$enrollmentId) continue;

        try {
            $stmtP = $conn->prepare("
                SELECT p.fname, p.lname, p.email
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

        $mail->addAddress($participant['email'], $participant['fname'] . ' ' . $participant['lname']);
        $mail->Subject = 'Acknowledgment Letter - ' . $trainingName;

        $mail->Body = '<html><body>';
        $mail->Body .= '<p style="font-family:Verdana,sans-serif;">Dear Fellow Lingkod-Bayani:</p>
        <p style="font-family:Verdana,sans-serif;">Greetings from the CSC RO VIII-HRD!</p>
        <p style="font-family:Verdana,sans-serif;">The CSC RO VIII - Human Resource Division acknowledges your registration in our scheduled training entitled, <b><i>' . htmlspecialchars($trainingName) . '</i></b> at
        ' . htmlspecialchars($locationTraining) . ' with a registration fee amounting to <b><u>Php ' . htmlspecialchars($fee) . '</u></b>.</p>
        <p style="font-family:Verdana,sans-serif;">By personally registering for the above-mentioned training, it is understood that <b><i>you agree to the terms and conditions of the
        training and bound yourself to the Data Privacy Policy</i></b>. Thus, to properly validate your attendance as well as a basis for the issuance of the Certificate of Completion to
        the above-cited training, the Human Resource Division (HRD) requests that you <b><i>submit the following:</i></b></p>
        <ol style="font-family:Verdana,sans-serif;">
            <li><b><i>Scanned copy of your proof of payment</i></b> or <b><i>promissory note</i></b> of your intention to pay for the corresponding registration fee;</li>
            <li><b><i>Scanned copy of your government-issued or valid ID with picture</i></b>; and</li>
            <li><b><i>Office Order from the Head of Agency</i></b> that permits you to attend <b><i>or Approved Leave of Absence, if personal expense.</i></b> Please send them through email directly to the CSC
            Field Office nearest you or to the HRS using the following Registration Link:<br>
            <h3 style="text-align:center"><a href="' . htmlspecialchars($link) . '"><b>CLICK HERE TO SEND YOUR ATTACHMENTS...</b></a></h3></li>
        </ol>
        <p style="font-family:Verdana,sans-serif;">This is a first-to-pay, first-served-basis training. The deadline for registration is until ' . $deadlineFormat . ' or until all available slots are filled.</p>
        <p style="font-family:Verdana,sans-serif;">Further, registered participants will be charged with an amount equivalent to <b>100% of the registration fee if they fail to attend the training or cancel their registration
        within seven (7) days before the conduct of training.</b></p>
        <p style="font-family:Verdana,sans-serif;">For clarification, please call the <i>CSC RO VIII - HRD</i> at <b><i><u>(053) 888-1811</u> or any of the CSC field offices.</i></b> You may send your payment using the following
        information:</p>
        <p style="font-family:Verdana,sans-serif;text-align:center">Name of Bank: <b><i><u>Landbank of the Philippines</u></i></b><br>
        Account Name: <b><i><u>Civil Service Commission</u></i></b><br>
        Account Number: <b><i><u>018-211-0825</u></i></b></p>
        <p style="font-family:Verdana,sans-serif;"><b>Note:</b> <i>Please disregard this information if you have already submitted the required documents mentioned above.</i></p>
        <br><p style="font-family:Verdana,sans-serif;">Thank you.<br><br>
        Sincerely yours,<br><br>
        <b>(Sgd.) JAY M. MERELOS</b><br>
        Chief Human Resource Specialist<br>
        Human Resource Division<br></p>';
        $mail->Body .= '</body></html>';

        $participantDisplay = $participant['fname'] . ' ' . $participant['lname'];
        try {
            $mail->send();
            $sent++;
        } catch (Exception $mailEx) {
            $errors[] = $participantDisplay . ': ' . $mail->ErrorInfo;
            error_log('[send_email-ack FO] ' . $mailEx->getMessage());
        }
        $mail->clearAllRecipients();
    }
} catch (Exception $e) {
    error_log('[send_email-ack FO setup] ' . $e->getMessage());
    echo json_encode(['success' => false, 'sent' => $sent, 'failed' => 1, 'errors' => ['Setup failed: ' . $e->getMessage()]]);
    exit;
}

echo json_encode(['success' => $sent > 0, 'sent' => $sent, 'failed' => count($errors), 'errors' => $errors]);
