<?php
// api/batch_cancel_participants.php
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

if (empty($_POST['participantIds'])) {
    echo json_encode(['success' => false, 'message' => 'No registration IDs provided.']);
    exit;
}

$raw = $_POST['participantIds'];
$ids = is_array($raw) ? $raw : json_decode($raw, true);

if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration IDs.']);
    exit;
}

$ids = array_values(array_filter(array_map('intval', $ids)));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid registration IDs.']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Fetch registrations not already Cancelled
    $fetchStmt = $conn->prepare(
        "SELECT id, training_id
         FROM otrs_registrations
         WHERE id IN ($placeholders)
           AND registration_status != 'cancelled'"
    );
    $fetchStmt->execute($ids);
    $toCancel = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($toCancel)) {
        echo json_encode([
            'success'   => false,
            'cancelled' => 0,
            'message'   => 'All selected participants are already cancelled.',
        ]);
        exit;
    }

    $cancelIds = array_column($toCancel, 'id');
    $cancelPlaceholders = implode(',', array_fill(0, count($cancelIds), '?'));

    $conn->beginTransaction();

    // Cancel registrations
    $updateStmt = $conn->prepare(
        "UPDATE otrs_registrations
         SET registration_status = 'cancelled'
         WHERE id IN ($cancelPlaceholders)"
    );
    $updateStmt->execute($cancelIds);

    // Refund slots grouped by training_id
    $trainingCounts = [];
    foreach ($toCancel as $row) {
        $tid = (int) $row['training_id'];
        $trainingCounts[$tid] = ($trainingCounts[$tid] ?? 0) + 1;
    }

    $slotStmt = $conn->prepare(
        "UPDATE otrs_trainings SET slots = slots + ? WHERE id = ?"
    );
    foreach ($trainingCounts as $trainingId => $count) {
        $slotStmt->execute([$count, $trainingId]);
    }

    $conn->commit();

    echo json_encode([
        'success'   => true,
        'cancelled' => count($cancelIds),
        'message'   => count($cancelIds) . ' participant(s) cancelled and slot(s) returned.',
    ]);

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('batch_cancel_participants Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
