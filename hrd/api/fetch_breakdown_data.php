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
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$training_id = isset($_POST['training_id']) ? (int)$_POST['training_id'] : 0;

if ($training_id < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing training ID.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            p.location,
            COUNT(*) AS total_pax,
            SUM(CASE WHEN r.payment_status = 'paid'            THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN r.payment_status = 'promissory note' THEN 1 ELSE 0 END) AS promissory_note,
            SUM(CASE WHEN r.payment_status = 'pending'         THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN r.registration_status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled
        FROM otrs_registrations r
        JOIN otrs_participants p ON r.participant_id = p.id
        WHERE r.training_id = :training_id
        GROUP BY p.location
    ");
    $stmt->execute([':training_id' => $training_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    error_log('fetch_breakdown_data.php PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
