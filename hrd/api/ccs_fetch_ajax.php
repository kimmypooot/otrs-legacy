<?php
// imis/otrs/hrd/api/ccs_fetch_ajax.php
// GET — paginated certificate list for the CCS Dashboard DataTable.
//
// Query params (all optional):
//   q          — free-text search (participant_name + training_title)
//   source[]   — filter by source  (public_offered|agency|internal|appearance)
//   category[] — filter by category
//   status     — filter by status (active|voided|archived) — default 'active'
//   date_from  — issued_at >= YYYY-MM-DD
//   date_to    — issued_at <= YYYY-MM-DD
//   start      — DataTables offset  (default 0)
//   length     — DataTables page size (default 25, max 200)
//   order_col  — column index 0-10 (default 9 = issued_at)
//   order_dir  — asc|desc (default desc)

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$q        = trim($_GET['q'] ?? '');
$sources  = (array)($_GET['source']   ?? []);
$cats     = (array)($_GET['category'] ?? []);
$status   = trim($_GET['status']   ?? 'active');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');
$offset   = max(0, (int)($_GET['start']  ?? 0));
$length   = min(200, max(1, (int)($_GET['length'] ?? 25)));

$allowedSources  = ['public_offered', 'agency', 'internal', 'appearance'];
$allowedCats     = ['completion', 'certification', 'appearance', 'merged', 'merged_certification'];
$allowedStatuses = ['active', 'voided', 'archived', 'reissued', 'all'];
// Indexed by DataTable column position (0=chk, 1=#, 2=cert_no, 3=participant,
// 4=training, 5=venue, 6=category, 7=source, 8=status, 9=issued_at, 10=actions)
$allowedCols = [
    0  => 'issued_at',          // checkbox — non-orderable fallback
    1  => 'issued_at',          // row # — non-orderable fallback
    2  => 'certificate_number',
    3  => 'participant_name',
    4  => 'training_title',
    5  => 'venue',
    6  => 'category',
    7  => 'source',
    8  => 'status',
    9  => 'issued_at',
    10 => 'issued_at',          // actions — non-orderable fallback
];

$sources = array_filter($sources, fn($s) => in_array($s, $allowedSources, true));
$cats    = array_filter($cats,    fn($c) => in_array($c, $allowedCats,    true));
if (!in_array($status, $allowedStatuses, true)) $status = 'active';

$colIdx  = max(0, min(10, (int)($_GET['order_col'] ?? 9)));
$orderBy = $allowedCols[$colIdx] ?? 'issued_at';
$orderDir = strtolower($_GET['order_dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($q !== '') {
    $where[]      = '(participant_name LIKE :q1 OR training_title LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if (!empty($sources)) {
    $ph = [];
    foreach ($sources as $i => $s) { $k = ":src{$i}"; $ph[] = $k; $params[$k] = $s; }
    $where[] = 'source IN (' . implode(',', $ph) . ')';
}
if (!empty($cats)) {
    $ph = [];
    foreach ($cats as $i => $c) { $k = ":cat{$i}"; $ph[] = $k; $params[$k] = $c; }
    $where[] = 'category IN (' . implode(',', $ph) . ')';
}
if ($status !== 'all') {
    $where[]         = 'status = :status';
    $params[':status'] = $status;
}
if ($dateFrom) {
    $where[]            = 'DATE(issued_at) >= :dfrom';
    $params[':dfrom']   = $dateFrom;
}
if ($dateTo) {
    $where[]            = 'DATE(issued_at) <= :dto';
    $params[':dto']     = $dateTo;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    // Total matching (for DataTables recordsFiltered)
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM otrs_ccs_certificates {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Rows
    $sql = "
        SELECT id, certificate_number, participant_name, training_title,
               venue, start_date, end_date, category, source, status,
               certificate_file, issued_at, email_status, download_count,
               no_hrs, training_type, training_level, training_mode,
               position, agency
        FROM otrs_ccs_certificates
        {$whereClause}
        ORDER BY {$orderBy} {$orderDir}
        LIMIT :lim OFFSET :off
    ";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $length, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($r) {
        return [
            'id'              => (int)$r['id'],
            'cert_number'     => $r['certificate_number'],
            'participant'     => htmlspecialchars($r['participant_name'], ENT_QUOTES),
            'training'        => htmlspecialchars($r['training_title'],   ENT_QUOTES),
            'category'        => $r['category'],
            'source'          => $r['source'],
            'status'          => $r['status'],
            'issued_at'       => $r['issued_at'],
            'cert_file'       => $r['certificate_file'],
            'email_status'    => $r['email_status'],
            'download_count'  => (int)$r['download_count'],
            // Raw fields for the Edit modal (not HTML-escaped)
            'participant_name' => $r['participant_name'],
            'training_title'   => $r['training_title'],
            'venue'            => $r['venue']          ?? '',
            'start_date'       => $r['start_date']     ?? '',
            'end_date'         => $r['end_date']        ?? '',
            'no_hrs'           => (int)($r['no_hrs']   ?? 0),
            'training_type'    => $r['training_type']  ?? '',
            'training_level'   => $r['training_level'] ?? '',
            'training_mode'    => $r['training_mode']  ?? '',
            'position'         => $r['position'] ?? '',
            'agency'           => $r['agency']   ?? '',
        ];
    }, $rows);

    // Grand total (all certs, not filtered) for DataTables recordsTotal
    $grandTotal = (int)$conn->query('SELECT COUNT(*) FROM otrs_ccs_certificates')->fetchColumn();

    echo json_encode([
        'success'          => true,
        'draw'             => (int)($_GET['draw'] ?? 1),
        'recordsTotal'     => $grandTotal,
        'recordsFiltered'  => $total,
        'data'             => $data,
    ]);
} catch (Throwable $e) {
    error_log('[ccs_fetch_ajax] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Query failed.', 'draw' => 1,
        'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
}
