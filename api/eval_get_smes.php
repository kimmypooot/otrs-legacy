<?php
/**
 * imis/otrs/api/eval_get_smes.php
 * GET ?reg_id=N&day=N&sig=HASH&eval_day=N
 *
 * Returns SMEs for the given eval_day of the training identified by reg_id.
 * Analog of otrs/f2f/api/get_smes.php, authenticated via reg_id+sig.
 */
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../includes/connect.php';
require_once __DIR__ . '/../hrd/inc/training_dates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

function evalSmeError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function evalSmeSig(int $regId, int $dayNum, string $email, string $regDate): string {
    $key = $email . substr($regDate, 0, 10);
    return substr(hash_hmac('sha256', "{$regId}:{$dayNum}", $key), 0, 32);
}

$regId   = filter_input(INPUT_GET, 'reg_id',   FILTER_VALIDATE_INT);
$dayNum  = filter_input(INPUT_GET, 'day',       FILTER_VALIDATE_INT);
$sig     = trim($_GET['sig'] ?? '');
$evalDay = filter_input(INPUT_GET, 'eval_day',  FILTER_VALIDATE_INT);

if (!$regId || !$dayNum || $sig === '' || $dayNum < 1) evalSmeError('Invalid parameters.', 422);

try {
    // Load registration
    $stmtR = $conn->prepare("
        SELECT r.training_id, r.participant_id,
               DATE(r.created_at) AS reg_date,
               p.email,
               t.start_date, t.end_date
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        JOIN   otrs_trainings     t ON t.id = r.training_id
        WHERE  r.id = :rid LIMIT 1
    ");
    $stmtR->execute([':rid' => $regId]);
    $reg = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$reg) evalSmeError('Registration not found.', 404);

    $expectedSig = evalSmeSig($regId, $dayNum, $reg['email'], $reg['reg_date']);
    if (!hash_equals($expectedSig, $sig)) evalSmeError('Invalid signature.', 403);

    $trainingId    = (int)$reg['training_id'];
    $participantId = (int)$reg['participant_id'];

    // Build training dates
    $trainingDates = getTrainingDateStrings($conn, $trainingId);
    $totalDays     = count($trainingDates) ?: 1;
    $today     = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

    // Fetch all SMEs
    $stmtSmes = $conn->prepare("
        SELECT ts.id, ts.sme_name, ts.designation, ts.sort_order, ts.sme_id,
               u.profile AS profile_photo
        FROM   otrs_training_smes ts
        LEFT JOIN users_cscro8 u ON u.id = ts.sme_id
        WHERE  ts.training_id = :tid
        ORDER BY ts.sort_order ASC, ts.id ASC
    ");
    $stmtSmes->execute([':tid' => $trainingId]);
    $allSmes = $stmtSmes->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allSmes)) {
        echo json_encode(['success' => true, 'smes' => [], 'eval_day' => $evalDay]);
        exit;
    }

    // Build day assignments
    $smeIds    = array_column($allSmes, 'id');
    $ph        = implode(',', array_fill(0, count($smeIds), '?'));
    $stmtDays  = $conn->prepare("SELECT training_sme_id, day_number FROM otrs_sme_training_days WHERE training_sme_id IN ($ph)");
    $stmtDays->execute($smeIds);
    $smeDayMap = [];
    foreach ($stmtDays->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $smeDayMap[(int)$d['training_sme_id']][] = (int)$d['day_number'];
    }
    foreach ($allSmes as &$sme) {
        $days = $smeDayMap[(int)$sme['id']] ?? [];
        sort($days);
        $sme['days'] = $days;
    }
    unset($sme);

    // Filter to SMEs whose day(s) have started
    $allSmes = array_values(array_filter($allSmes, function (array $sme) use ($trainingDates, $today): bool {
        if (empty($sme['days'])) return !empty($trainingDates) && $trainingDates[0] <= $today;
        foreach ($sme['days'] as $dn) {
            $date = $trainingDates[$dn - 1] ?? null;
            if ($date !== null && $date <= $today) return true;
        }
        return false;
    }));

    // Filter to SMEs whose last day == eval_day (mirrors f2f legacy filter)
    if ($evalDay) {
        $filtered = [];
        foreach ($allSmes as $sme) {
            $lastDay = empty($sme['days']) ? $totalDays : max($sme['days']);
            if ($lastDay === $evalDay) $filtered[] = $sme;
        }
        $allSmes = $filtered;
    }

    // Mark already_evaluated
    $evaluatedSet = [];
    $stmtEv = $conn->prepare("
        SELECT DISTINCT esr.sme_id
        FROM   otrs_evaluation_sme_ratings esr
        JOIN   otrs_evaluations e ON e.id = esr.evaluation_id
        WHERE  e.participant_id = :pid AND e.training_id = :tid
    ");
    $stmtEv->execute([':pid' => $participantId, ':tid' => $trainingId]);
    $evaluatedSet = array_flip(array_map('intval', array_column($stmtEv->fetchAll(PDO::FETCH_ASSOC), 'sme_id')));

    foreach ($allSmes as &$sme) {
        $sme['already_evaluated'] = isset($evaluatedSet[(int)$sme['id']]);
        $sme['sme_id']            = $sme['sme_id'] ? (int)$sme['sme_id'] : null;
    }
    unset($sme);

    echo json_encode(['success' => true, 'smes' => array_values($allSmes), 'eval_day' => $evalDay]);

} catch (PDOException $e) {
    error_log('[eval_get_smes] ' . $e->getMessage());
    evalSmeError('Failed to load facilitators.', 500);
}
