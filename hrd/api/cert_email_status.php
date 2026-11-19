<?php
// imis/otrs/hrd/cert_email_status.php
// Polling endpoint: returns the current state of an email batch job.
// Expects GET: jobId (string)
//
// Response shape:
// {
//   "found"      : true|false,
//   "status"     : "processing"|"done",
//   "total"      : 10,
//   "sent"       : 7,
//   "failed"     : 1,
//   "remaining"  : 2,
//   "pct"        : 80,
//   "finishedAt" : "2025-06-01 14:32:00" | null
// }

require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');

$jobId = trim($_GET['jobId'] ?? '');

if (empty($jobId) || !preg_match('/^[0-9a-f]{32}$/', $jobId)) {
    echo json_encode(['found' => false, 'error' => 'Invalid or missing jobId.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT status, total, sent, failed, finished_at
        FROM otrs_cert_email_jobs
        WHERE job_id = :jid
        LIMIT 1
    ");
    $stmt->execute([':jid' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[cert_email_status] ' . $e->getMessage());
    echo json_encode(['found' => false, 'error' => 'Database error.']);
    exit;
}

if (!$job) {
    echo json_encode(['found' => false, 'error' => 'Job not found.']);
    exit;
}

$total     = (int) $job['total'];
$sent      = (int) $job['sent'];
$failed    = (int) $job['failed'];
$processed = $sent + $failed;
$remaining = max(0, $total - $processed);
$pct       = $total > 0 ? (int) round(($processed / $total) * 100) : 0;

echo json_encode([
    'found'      => true,
    'status'     => $job['status'],        // "processing" | "done"
    'total'      => $total,
    'sent'       => $sent,
    'failed'     => $failed,
    'remaining'  => $remaining,
    'pct'        => $pct,
    'finishedAt' => $job['finished_at'],
]);
exit;