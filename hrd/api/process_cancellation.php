<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/audit_log.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$requestId     = filter_input(INPUT_POST, 'requestId', FILTER_VALIDATE_INT);
$action        = trim($_POST['action'] ?? '');
$reviewRemarks = mb_substr(trim($_POST['review_remarks'] ?? ''), 0, 1000);

if (!$requestId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request ID.']);
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

$reviewedBy = $_SESSION['name'] ?? $_SESSION['username'];

try {
    $fetch = $conn->prepare("
        SELECT cr.*,
               r.payment_status AS current_payment_status,
               CONCAT(p.fname, ' ', COALESCE(NULLIF(p.mname,''),''), ' ', p.lname) AS participant_name,
               p.email AS participant_email,
               t.name AS training_name,
               t.start_date,
               t.end_date,
               (r.id IS NULL OR p.id IS NULL) AS is_orphaned
        FROM   otrs_cancellation_requests cr
        LEFT JOIN otrs_registrations r  ON r.id  = cr.enrollment_id
        LEFT JOIN otrs_participants  p  ON p.id  = cr.participant_id
        LEFT JOIN otrs_trainings     t  ON t.id  = cr.training_id
        WHERE  cr.id = :id
        LIMIT  1
    ");
    $fetch->execute([':id' => $requestId]);
    $req = $fetch->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['status' => 'error', 'message' => 'Cancellation request not found.']);
        exit;
    }
    if ($req['status'] !== 'pending') {
        echo json_encode(['status' => 'error', 'message' => 'This request has already been ' . $req['status'] . '.']);
        exit;
    }
    if ($action === 'approve' && $req['is_orphaned']) {
        echo json_encode(['status' => 'error', 'message' => 'The associated registration or participant record no longer exists. Please reject this request instead.']);
        exit;
    }

    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

    $conn->beginTransaction();

    // Update the cancellation request record
    $upd = $conn->prepare("
        UPDATE otrs_cancellation_requests SET
            status         = :status,
            reviewed_by    = :reviewed_by,
            reviewed_at    = NOW(),
            review_remarks = :review_remarks
        WHERE  id = :id
    ");
    $upd->execute([
        ':status'         => $newStatus,
        ':reviewed_by'    => $reviewedBy,
        ':review_remarks' => $reviewRemarks,
        ':id'             => $requestId,
    ]);

    // On approval: set both payment_status and registration_status, restore slot
    $promoted = null;
    if ($action === 'approve') {
        $conn->prepare("
            UPDATE otrs_registrations SET
                payment_status      = 'cancelled',
                registration_status = 'cancelled',
                updated_at          = NOW()
            WHERE  id = :eid
        ")->execute([':eid' => $req['enrollment_id']]);

        if ($req['current_payment_status'] !== 'cancelled') {
            $conn->prepare("
                UPDATE otrs_trainings SET slots = slots + 1 WHERE id = :tid
            ")->execute([':tid' => (int) $req['training_id']]);
        }

        // Auto-promote first person from waitlist
        $wlStmt = $conn->prepare("
            SELECT w.id AS wl_id, w.participant_id,
                   CONCAT_WS(' ', TRIM(p.fname), TRIM(p.lname)) AS participant_name,
                   p.email
            FROM   otrs_waitlist w
            JOIN   otrs_participants p ON p.id = w.participant_id
            WHERE  w.training_id = :tid AND w.status = 'waiting'
            ORDER  BY w.position ASC
            LIMIT  1
        ");
        $wlStmt->execute([':tid' => (int)$req['training_id']]);
        $next = $wlStmt->fetch(PDO::FETCH_ASSOC);

        if ($next) {
            // Reduce slot again (restored above, now taken by promoted participant)
            $conn->prepare("UPDATE otrs_trainings SET slots = slots - 1 WHERE id = :tid AND slots > 0")
                 ->execute([':tid' => (int)$req['training_id']]);

            // Create registration for promoted participant
            $tType = $conn->prepare("SELECT `type` FROM otrs_trainings WHERE id=:id LIMIT 1");
            $tType->execute([':id' => (int)$req['training_id']]);
            $promotedPayStatus = (strtolower($tType->fetchColumn() ?? '') === 'free') ? 'free' : 'pending';

            $conn->prepare("
                INSERT INTO otrs_registrations
                    (participant_id, training_id, registration_status, payment_status, registered_at, created_at, updated_at)
                VALUES (:pid, :tid, 'confirmed', :pstatus, NOW(), NOW(), NOW())
            ")->execute([
                ':pid'     => $next['participant_id'],
                ':tid'     => (int)$req['training_id'],
                ':pstatus' => $promotedPayStatus,
            ]);

            // Mark waitlist entry as promoted
            $conn->prepare("
                UPDATE otrs_waitlist SET status='promoted', promoted_at=NOW()
                WHERE id=:wid
            ")->execute([':wid' => $next['wl_id']]);

            // Shift remaining waitlist positions down by 1
            $conn->prepare("
                UPDATE otrs_waitlist SET position = position - 1
                WHERE training_id = :tid AND status = 'waiting' AND position > 1
            ")->execute([':tid' => (int)$req['training_id']]);

            $promoted = $next;
        }
    }

    // Audit log
    $conn->prepare("
        INSERT INTO otrs_cancellation_logs
            (request_id, action, actor, actor_location, remarks, created_at)
        VALUES
            (:rid, :action, :actor, 'hrd', :remarks, NOW())
    ")->execute([
        ':rid'    => $requestId,
        ':action' => $newStatus,
        ':actor'  => $reviewedBy,
        ':remarks'=> $reviewRemarks,
    ]);

    $conn->commit();

    // Notify FO users by email (silent on failure — never block the response)
    try {
        $foLocation = $req['requested_by_location'] ?? '';
        if ($foLocation !== '') {
            $emailStmt = $conn->prepare(
                "SELECT email, name FROM users_cscro8
                 WHERE type = :location AND email IS NOT NULL AND email <> ''"
            );
            $emailStmt->execute([':location' => $foLocation]);
            $foUsers = $emailStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($foUsers)) {
                require_once __DIR__ . '/../../inc/mail_config.php';
                require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
                require_once __DIR__ . '/../../../phpmailer/SMTP.php';
                require_once __DIR__ . '/../../../phpmailer/Exception.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer();
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USERNAME;
                $mail->Password   = SMTP_PASSWORD;
                $mail->SMTPSecure = 'tls';
                $mail->Port       = SMTP_PORT;
                $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
                $mail->CharSet    = 'UTF-8';

                foreach ($foUsers as $u) {
                    $mail->addAddress($u['email'], $u['name'] ?? '');
                }

                $decision       = $action === 'approve' ? 'APPROVED' : 'REJECTED';
                $participantName = trim($req['participant_name'] ?? '');
                $trainingName    = htmlspecialchars($req['training_name'] ?? '', ENT_QUOTES);
                $startDate       = $req['start_date'] ?? '';
                $endDate         = $req['end_date']   ?? '';
                $dateRange       = ($startDate && $startDate !== '0000-00-00')
                    ? date('M j, Y', strtotime($startDate)) . ($endDate && $endDate !== $startDate ? ' – ' . date('M j, Y', strtotime($endDate)) : '')
                    : 'N/A';
                $hrRemarks       = htmlspecialchars($reviewRemarks, ENT_QUOTES);

                $mail->Subject = "Cancellation Request {$decision} — {$participantName}";
                $mail->isHTML(true);
                $mail->Body = "
                    <p>Dear Field Office Team,</p>
                    <p>The cancellation request submitted for the following participant has been <strong>{$decision}</strong>:</p>
                    <table cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>
                        <tr><td><strong>Participant:</strong></td><td>{$participantName}</td></tr>
                        <tr><td><strong>Training:</strong></td><td>{$trainingName}</td></tr>
                        <tr><td><strong>Training Date:</strong></td><td>{$dateRange}</td></tr>
                        <tr><td><strong>Decision:</strong></td><td>{$decision}</td></tr>
                        <tr><td><strong>HRD Remarks:</strong></td><td>{$hrRemarks}</td></tr>
                        <tr><td><strong>Reviewed by:</strong></td><td>" . htmlspecialchars($reviewedBy, ENT_QUOTES) . "</td></tr>
                    </table>
                    <p>This is an automated notification from the Online Training Registration System.</p>";
                $mail->AltBody = "Cancellation request for {$participantName} ({$trainingName}, {$dateRange}) has been {$decision}. HRD Remarks: {$hrRemarks}";

                $mail->send();
            }
        }
    } catch (\Exception $mailEx) {
        error_log('[process_cancellation] Email notification failed: ' . $mailEx->getMessage());
    }

    // Email participant directly if approved and they have an email address
    if ($action === 'approve') {
        try {
            $participantEmail = trim($req['participant_email'] ?? '');
            if ($participantEmail !== '' && filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
                if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                    require_once __DIR__ . '/../../inc/mail_config.php';
                    require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
                    require_once __DIR__ . '/../../../phpmailer/SMTP.php';
                    require_once __DIR__ . '/../../../phpmailer/Exception.php';
                }
                $pMail = new PHPMailer\PHPMailer\PHPMailer();
                $pMail->isSMTP();
                $pMail->Host     = SMTP_HOST;
                $pMail->SMTPAuth = true;
                $pMail->Username = SMTP_USERNAME;
                $pMail->Password = SMTP_PASSWORD;
                $pMail->SMTPSecure = 'tls';
                $pMail->Port     = SMTP_PORT;
                $pMail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
                $pMail->addAddress($participantEmail, trim($req['participant_name'] ?? ''));
                $pMail->CharSet  = 'UTF-8';
                $pMail->isHTML(true);
                $pMail->Subject = 'Your Registration Has Been Cancelled — ' . htmlspecialchars($req['training_name'] ?? '', ENT_QUOTES);
                $pMail->Body    = "
                    <p>Dear " . htmlspecialchars(trim($req['participant_name'] ?? ''), ENT_QUOTES) . ",</p>
                    <p>We regret to inform you that your registration for the following training has been <strong>CANCELLED</strong> upon your request:</p>
                    <table cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>
                        <tr><td><strong>Training:</strong></td><td>" . htmlspecialchars($req['training_name'] ?? '', ENT_QUOTES) . "</td></tr>
                        <tr><td><strong>HRD Remarks:</strong></td><td>" . htmlspecialchars($reviewRemarks, ENT_QUOTES) . "</td></tr>
                    </table>
                    <p>If you have any questions, please contact the HRD at (053) 888-1811.</p>
                    <p>This is an automated notification from the Online Training Registration System.</p>";
                $pMail->send();
            }
        } catch (\Exception $pMailEx) {
            error_log('[process_cancellation] Participant email failed: ' . $pMailEx->getMessage());
        }
    }

    $msg = $action === 'approve'
        ? 'Cancellation approved. Participant status updated to Cancelled.'
        : 'Cancellation request has been rejected.';

    otrsAuditLog($conn, "cancellation_{$newStatus}", 'cancellation_request', $requestId,
        (int)$req['training_id'],
        $req['participant_name'] ?? null,
        json_encode(['training' => $req['training_name'] ?? '', 'remarks' => $reviewRemarks]),
        $reviewedBy, $_SERVER['REMOTE_ADDR'] ?? null
    );

    $result = ['status' => 'success', 'message' => $msg];
    if ($promoted) {
        $result['promoted'] = [
            'name' => htmlspecialchars($promoted['participant_name'], ENT_QUOTES),
        ];
    }
    echo json_encode($result);

} catch (PDOException $ex) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[process_cancellation] ' . $ex->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => $ex->getMessage(),  // ← temporary, remove after debugging
        'trace'   => $ex->getTraceAsString()
    ]);
}
