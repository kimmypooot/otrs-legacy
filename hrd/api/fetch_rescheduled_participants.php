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

$trainingId = isset($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
if ($trainingId < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid training ID.']);
    exit;
}

try {
    // Paid / promissory note participants — with transfer status
    $stmt = $conn->prepare("
        SELECT
            r.id                        AS registration_id,
            p.last_name,
            p.first_name,
            p.middle_name,
            p.position,
            p.agency,
            r.payment_status,
            r.registration_status,
            CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END AS is_transferred,
            t.to_training_id,
            tr.name                     AS new_training_name
        FROM otrs_registrations r
        JOIN otrs_participants p ON p.id = r.participant_id
        LEFT JOIN otrs_participant_transfers t ON t.registration_id = r.id
        LEFT JOIN otrs_trainings tr            ON tr.id = t.to_training_id
        WHERE r.training_id = :tid
          AND r.payment_status IN ('paid', 'promissory note')
          AND r.registration_status != 'Cancelled'
        ORDER BY p.last_name ASC, p.first_name ASC
    ");
    $stmt->execute([':tid' => $trainingId]);
    $paid = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pending participants count (will be cancelled on transfer)
    $pendStmt = $conn->prepare("
        SELECT COUNT(*) FROM otrs_registrations
        WHERE training_id = :tid
          AND payment_status = 'pending'
          AND registration_status NOT IN ('Cancelled', 'not qualified')
    ");
    $pendStmt->execute([':tid' => $trainingId]);
    $pendingCount = (int)$pendStmt->fetchColumn();

    echo json_encode([
        'success'       => true,
        'participants'  => $paid,
        'pending_count' => $pendingCount,
    ]);
} catch (PDOException $e) {
    error_log('fetch_rescheduled_participants.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
