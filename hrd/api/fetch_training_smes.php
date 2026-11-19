<?php
/**
 * hrd/api/fetch_training_smes.php
 * GET ?training_id=N
 * Returns all SMEs assigned to a training with their day assignments.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid training ID.']);
    exit;
}

try {
    // Fetch all SMEs for this training
    $stmt = $conn->prepare("
        SELECT
            ts.id, ts.sme_id, ts.sme_name, ts.designation, ts.sort_order, ts.created_at,
            s.name AS master_name, s.email AS master_email, s.agency AS master_agency,
            s.status AS master_status, s.sme_type,
            COALESCE(s.photo, '') AS photo,
            COALESCE(u.profile, '') AS user_profile
        FROM otrs_training_smes ts
        LEFT JOIN otrs_smes s ON s.id = ts.sme_id
        LEFT JOIN users_cscro8 u ON u.id = s.user_id
        WHERE ts.training_id = :tid
        ORDER BY ts.sort_order ASC, ts.id ASC
    ");
    $stmt->execute([':tid' => $trainingId]);
    $smes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($smes)) {
        echo json_encode(['status' => 'success', 'data' => []]);
        exit;
    }

    // Fetch day assignments for all returned SME rows
    $ids        = array_column($smes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtDays   = $conn->prepare("
        SELECT training_sme_id, day_number, training_date
        FROM otrs_sme_training_days
        WHERE training_sme_id IN ({$placeholders})
        ORDER BY day_number ASC
    ");
    $stmtDays->execute($ids);
    $dayRows = $stmtDays->fetchAll(PDO::FETCH_ASSOC);

    // Group days by training_sme_id
    $dayMap = [];
    foreach ($dayRows as $d) {
        $dayMap[(int)$d['training_sme_id']][] = [
            'day_number'    => (int)$d['day_number'],
            'training_date' => $d['training_date'],
        ];
    }

    foreach ($smes as &$sme) {
        $sme['days'] = $dayMap[(int)$sme['id']] ?? [];
    }
    unset($sme);

    echo json_encode(['status' => 'success', 'data' => $smes]);
} catch (PDOException $e) {
    error_log('[fetch_training_smes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch training SMEs.']);
}
