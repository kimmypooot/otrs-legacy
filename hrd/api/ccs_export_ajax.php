<?php
// imis/otrs/hrd/api/ccs_export_ajax.php
// GET — export certificate list as UTF-8 CSV (no pagination limit).
// Accepts the same filter params as ccs_fetch_ajax.php.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}

// ── Inputs (mirrors ccs_fetch_ajax.php) ──────────────────────────────────────
$q        = trim($_GET['q']        ?? '');
$sources  = (array)($_GET['source']   ?? []);
$cats     = (array)($_GET['category'] ?? []);
$status   = trim($_GET['status']   ?? 'active');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');

$allowedSources  = ['public_offered', 'agency', 'internal', 'appearance'];
$allowedCats     = ['completion', 'certification', 'appearance', 'merged', 'merged_certification'];
$allowedStatuses = ['active', 'voided', 'archived', 'reissued', 'all'];

$sources = array_filter($sources, fn($s) => in_array($s, $allowedSources, true));
$cats    = array_filter($cats,    fn($c) => in_array($c, $allowedCats,    true));
if (!in_array($status, $allowedStatuses, true)) $status = 'active';

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where = []; $params = [];

if ($q !== '') {
    $where[] = '(participant_name LIKE :q1 OR training_title LIKE :q2)';
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
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
if ($dateFrom) {
    $where[] = 'DATE(issued_at) >= :dfrom';
    $params[':dfrom'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'DATE(issued_at) <= :dto';
    $params[':dto'] = $dateTo;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Query ─────────────────────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        SELECT certificate_number, participant_name, training_title,
               venue, start_date, end_date, no_hrs, training_type, training_mode,
               category, source, `status`, email_status, download_count, issued_at
          FROM otrs_ccs_certificates
        {$whereClause}
         ORDER BY issued_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ccs_export_ajax] ' . $e->getMessage());
    http_response_code(500);
    exit('Export failed.');
}

// ── Stream CSV ─────────────────────────────────────────────────────────────────
$filename = 'CCS_Certificates_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility

fputcsv($out, [
    'Cert No.',
    'Participant',
    'Training / Activity',
    'Venue',
    'Start Date',
    'End Date',
    'Hours',
    'Training Type',
    'Training Mode',
    'Category',
    'Source',
    'Status',
    'Email Status',
    'Download Count',
    'Issued At',
]);

$sourceMap = [
    'public_offered' => 'OTRS Standard',
    'agency'         => 'Agency',
    'internal'       => 'Internal',
    'appearance'     => 'Appearance',
];

foreach ($rows as $r) {
    fputcsv($out, [
        $r['certificate_number'] ?? '',
        $r['participant_name'],
        $r['training_title'],
        $r['venue']          ?? '',
        $r['start_date']     ?? '',
        $r['end_date']       ?? '',
        $r['no_hrs']         ?? '',
        $r['training_type']  ?? '',
        $r['training_mode']  ?? '',
        ucfirst($r['category'] ?? ''),
        $sourceMap[$r['source']] ?? $r['source'],
        ucfirst($r['status']   ?? ''),
        $r['email_status']   ?? '',
        $r['download_count'] ?? 0,
        $r['issued_at']      ?? '',
    ]);
}

fclose($out);
