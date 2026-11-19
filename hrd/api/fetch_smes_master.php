<?php
/**
 * hrd/api/fetch_smes_master.php
 * GET — returns all SMEs from the global otrs_smes registry.
 * Supports ?status=, ?sme_type=, and ?q= filters.
 * Works both before and after the add_sme_type.sql migration.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$statusFilter = trim($_GET['status']   ?? '');
$typeFilter   = trim($_GET['sme_type'] ?? '');
$search       = trim($_GET['q']        ?? '');

try {
    /* Detect whether the new columns exist yet */
    $chk        = $conn->query("SHOW COLUMNS FROM otrs_smes LIKE 'sme_type'");
    $hasSmeType = $chk->rowCount() > 0;

    $smeTypeExpr    = $hasSmeType ? 's.sme_type'                    : "'external' AS sme_type";
    $userIdExpr     = $hasSmeType ? 's.user_id'                     : 'NULL AS user_id';
    $foRsuExpr      = $hasSmeType ? "COALESCE(u.fo_rsu,'') AS fo_rsu" : "'' AS fo_rsu";
    $userProfileExpr= $hasSmeType ? "COALESCE(u.profile,'') AS user_profile" : "'' AS user_profile";
    $joinUser       = $hasSmeType
        ? "LEFT JOIN users_cscro8 u ON u.id = s.user_id"
        : "";

    /* Detect photo column (added by add_sme_photo.sql migration) */
    $chkPhoto    = $conn->query("SHOW COLUMNS FROM otrs_smes LIKE 'photo'");
    $hasPhoto    = $chkPhoto->rowCount() > 0;
    $photoExpr   = $hasPhoto ? "COALESCE(s.photo,'') AS photo" : "'' AS photo";

    $where  = [];
    $params = [];

    if ($statusFilter !== '') {
        $where[]           = 's.status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($hasSmeType && $typeFilter !== '') {
        $where[]             = 's.sme_type = :sme_type';
        $params[':sme_type'] = $typeFilter;
    }
    if ($search !== '') {
        $where[]       = '(s.name LIKE :q1 OR s.designation LIKE :q2 OR s.agency LIKE :q3)';
        $sLike = '%' . $search . '%';
        $params[':q1'] = $sLike;
        $params[':q2'] = $sLike;
        $params[':q3'] = $sLike;
    }

    $sql = "
        SELECT
            s.id, {$smeTypeExpr}, {$userIdExpr},
            s.name, s.designation, s.email, s.mobile_no,
            s.agency, s.expertise, s.bio, s.status, s.created_at,
            {$foRsuExpr},
            {$userProfileExpr},
            {$photoExpr},
            COUNT(DISTINCT ts.training_id) AS training_count
        FROM otrs_smes s
        {$joinUser}
        LEFT JOIN otrs_training_smes ts ON ts.sme_id = s.id
    ";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= $hasSmeType
        ? ' GROUP BY s.id ORDER BY s.sme_type ASC, s.name ASC'
        : ' GROUP BY s.id ORDER BY s.name ASC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $smes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $smes, 'migrated' => $hasSmeType]);
} catch (PDOException $e) {
    error_log('[fetch_smes_master] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch SMEs: ' . $e->getMessage()]);
}
