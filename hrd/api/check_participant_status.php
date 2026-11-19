<?php
// api/check_participant_status.php
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

$raw = $_POST['participantIds'] ?? '';
$ids = is_string($raw) ? json_decode($raw, true) : $raw;

if (!is_array($ids) || empty($ids)) {
    echo json_encode([]);
    exit;
}

$ids = array_values(array_filter(array_map('intval', $ids)));

if (empty($ids)) {
    echo json_encode([]);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare(
        "SELECT id, payment_status, registration_status
         FROM otrs_registrations
         WHERE id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $effectiveStatus = ($row['registration_status'] === 'cancelled')
            ? 'cancelled'
            : $row['payment_status'];
        $result[$row['id']] = $effectiveStatus;
    }

    echo json_encode($result);

} catch (PDOException $e) {
    error_log('check_participant_status Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
