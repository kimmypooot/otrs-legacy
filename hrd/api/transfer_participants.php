<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

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

$fromId   = isset($_POST['from_training_id']) ? (int)$_POST['from_training_id'] : 0;
$toId     = isset($_POST['to_training_id'])   ? (int)$_POST['to_training_id']   : 0;
$regIds   = array_filter(array_map('intval', (array)($_POST['registration_ids'] ?? [])));

if ($fromId < 1 || $toId < 1 || $fromId === $toId) {
    echo json_encode(['success' => false, 'error' => 'Invalid training selection.']);
    exit;
}
if (empty($regIds)) {
    echo json_encode(['success' => false, 'error' => 'No participants selected.']);
    exit;
}

try {
    // Verify target training is active and not rescheduled/completed/cancelled
    $chk = $conn->prepare(
        "SELECT training_status, slots,
                (SELECT COUNT(*) FROM otrs_registrations
                 WHERE training_id = :id2
                   AND registration_status NOT IN ('Cancelled','not qualified')) AS enrolled
         FROM otrs_trainings WHERE id = :id LIMIT 1"
    );
    $chk->execute([':id' => $toId, ':id2' => $toId]);
    $target = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        echo json_encode(['success' => false, 'error' => 'Target training not found.']);
        exit;
    }
    if (in_array(strtolower($target['training_status']), ['completed', 'cancelled', 'rescheduled'])) {
        echo json_encode(['success' => false, 'error' => 'Target training is not accepting participants.']);
        exit;
    }
    $available = (int)$target['slots'] - (int)$target['enrolled'];
    if (count($regIds) > $available) {
        echo json_encode([
            'success' => false,
            'error'   => "Not enough slots in the target training. Available: {$available}.",
        ]);
        exit;
    }

    $conn->beginTransaction();

    // Move each selected registration
    $move = $conn->prepare(
        "UPDATE otrs_registrations SET training_id = :to WHERE id = :rid AND training_id = :from"
    );
    $log = $conn->prepare(
        "INSERT INTO otrs_participant_transfers (registration_id, from_training_id, to_training_id)
         VALUES (:rid, :from, :to)"
    );
    foreach ($regIds as $rid) {
        $move->execute([':to' => $toId, ':rid' => $rid, ':from' => $fromId]);
        // Only log if a row was actually moved
        if ($move->rowCount() > 0) {
            $log->execute([':rid' => $rid, ':from' => $fromId, ':to' => $toId]);
        }
    }

    // Cancel remaining pending registrations in the old training
    $conn->prepare(
        "UPDATE otrs_registrations
         SET registration_status = 'Cancelled', payment_status = 'cancelled'
         WHERE training_id = :tid
           AND payment_status = 'pending'
           AND registration_status NOT IN ('Cancelled','not qualified')"
    )->execute([':tid' => $fromId]);

    // Link old training to its replacement
    $conn->prepare(
        "UPDATE otrs_trainings SET rescheduled_to_id = :to WHERE id = :from"
    )->execute([':to' => $toId, ':from' => $fromId]);

    $conn->commit();
    echo json_encode(['success' => true, 'transferred' => count($regIds)]);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log('transfer_participants.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
