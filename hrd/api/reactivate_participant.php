<?php
// api/reactivate_participant.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

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

if (empty($_POST['participantId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing registration ID.']);
    exit;
}

$regId = filter_var($_POST['participantId'], FILTER_VALIDATE_INT);
if ($regId === false || $regId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID.']);
    exit;
}

try {
    // Verify the registration exists and is cancelled
    $check = $conn->prepare(
        "SELECT id, training_id, registration_status, payment_status
         FROM otrs_registrations
         WHERE id = :id
         LIMIT 1"
    );
    $check->execute([':id' => $regId]);
    $reg = $check->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }

    $isCancelled = $reg['registration_status'] === 'cancelled'
                || $reg['payment_status']      === 'cancelled';

    if (!$isCancelled) {
        echo json_encode(['success' => false, 'message' => 'Registration is not in Cancelled status.']);
        exit;
    }

    // Restore registration to Pending and clear payment fields
    $update = $conn->prepare(
        "UPDATE otrs_registrations
         SET registration_status = 'confirmed',
             payment_status      = 'pending',
             or_no               = '',
             or_date             = NULL,
             collecting_officer  = ''
         WHERE id = :id"
    );
    $update->execute([':id' => $regId]);

    // Consume one slot — only if the training still has availability.
    $slots = $conn->prepare(
        "UPDATE otrs_trainings
         SET slots = slots - 1
         WHERE id = :training_id AND slots > 0"
    );
    $slots->execute([':training_id' => $reg['training_id']]);

    if ($slots->rowCount() === 0) {
        // No slot was decremented — training is full. Roll back the registration restore.
        $conn->prepare(
            "UPDATE otrs_registrations
             SET registration_status = 'cancelled',
                 payment_status      = 'cancelled'
             WHERE id = :id"
        )->execute([':id' => $regId]);

        echo json_encode([
            'success' => false,
            'message' => 'Cannot reactivate — this training is already full (no available slots).',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Participant successfully reactivated to Pending.',
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('reactivate_participant Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
