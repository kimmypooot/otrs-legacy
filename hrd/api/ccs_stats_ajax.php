<?php
// imis/otrs/hrd/api/ccs_stats_ajax.php
// GET — returns 5 stat counts for the CCS Dashboard header cards.
// No body params needed.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}

try {
    $row = $conn->query("
        SELECT
            SUM(status = 'active')                                              AS total_active,
            SUM(status = 'active' AND source = 'public_offered')               AS otrs_standard,
            SUM(status = 'active' AND source = 'agency')                       AS agency,
            SUM(status = 'active' AND source = 'appearance')                   AS appearance,
            SUM(status = 'active' AND source = 'internal')                     AS internal,
            SUM(status = 'active' AND category = 'merged')                     AS merged,
            SUM(status = 'active' AND category = 'merged_certification')       AS merged_certification,
            SUM(status = 'voided')                                              AS voided
        FROM otrs_ccs_certificates
    ")->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'stats' => [
        'total_active'         => (int)$row['total_active'],
        'otrs_standard'        => (int)$row['otrs_standard'],
        'agency'               => (int)$row['agency'],
        'appearance'           => (int)$row['appearance'],
        'internal'             => (int)$row['internal'],
        'merged'               => (int)$row['merged'],
        'merged_certification' => (int)$row['merged_certification'],
        'voided'               => (int)$row['voided'],
    ]]);
} catch (Throwable $e) {
    error_log('[ccs_stats_ajax] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load stats.']);
}
