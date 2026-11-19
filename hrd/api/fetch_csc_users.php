<?php
/**
 * hrd/api/fetch_csc_users.php
 * GET — searches users_cscro8 for internal SME autocomplete.
 * Returns matching active users (min 2-char query).
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT id,
               TRIM(CONCAT_WS(' ', fname, NULLIF(CONCAT(LEFT(TRIM(mname),1),'.'),'.'), lname)) AS full_name,
               lname, fname,
               `position`, fo_rsu, `type` AS office_code, email, `status`,
               COALESCE(profile, '') AS profile
        FROM   users_cscro8
        WHERE  `status` = 'Active'
          AND  (fname LIKE :q1 OR lname LIKE :q2
                OR CONCAT(fname,' ',lname) LIKE :q3
                OR CONCAT(lname,' ',fname) LIKE :q4)
        ORDER  BY lname ASC, fname ASC
        LIMIT  15
    ");
    $like = '%' . $q . '%';
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'data' => $rows]);
} catch (PDOException $e) {
    error_log('[fetch_csc_users] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Search failed: ' . $e->getMessage()]);
}
