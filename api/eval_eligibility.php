<?php
/**
 * imis/otrs/api/eval_eligibility.php
 * GET ?reg_id=N&day=N&sig=HASH
 *
 * Eligibility check for the evaluation_form.php wizard.
 * Analog of otrs/f2f/api/check_eligibility.php, using reg_id+sig instead of enc_pid.
 * Returns training dates, eval days, attendance status, and cert state.
 */
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../includes/connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Load service classes from the public OTRS claim/ directory ─────────────
(function () {
    $envFile = realpath(__DIR__ . '/../../../otrs/.env') ?: '';
    if ($envFile && is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
            [$k, $v] = explode('=', $ln, 2);
            $k = trim($k); $v = trim($v);
            $l = strlen($v);
            if ($l >= 2 && (($v[0] === '"' && $v[$l-1] === '"') || ($v[0] === "'" && $v[$l-1] === "'"))) $v = substr($v, 1, -1);
            if ($k !== '' && getenv($k) === false) putenv("$k=$v");
        }
    }
})();

if (!defined('INTERNAL_SECRET')) define('INTERNAL_SECRET', getenv('OTRS_INTERNAL_SECRET') ?: '');
if (!defined('CERT_PDF_DIR'))    define('CERT_PDF_DIR', realpath(__DIR__ . '/../../../assets/uploads/certificates') . DIRECTORY_SEPARATOR);
if (!defined('CLAIM_TOKEN_TTL')) define('CLAIM_TOKEN_TTL', 3600);

$_cb = realpath(__DIR__ . '/../../../otrs/claim/') . '/';
require_once $_cb . 'includes/helpers.php';
require_once $_cb . 'services/EvaluationService.php';
unset($_cb);

function evalEligError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function evalFormSig(int $regId, int $dayNum, string $email, string $regDate): string {
    $key = $email . substr($regDate, 0, 10);
    return substr(hash_hmac('sha256', "{$regId}:{$dayNum}", $key), 0, 32);
}

// ── Input validation ───────────────────────────────────────────────────────
$regId  = filter_input(INPUT_GET, 'reg_id', FILTER_VALIDATE_INT);
$dayNum = filter_input(INPUT_GET, 'day',    FILTER_VALIDATE_INT);
$sig    = trim($_GET['sig'] ?? '');

if (!$regId || !$dayNum || $sig === '' || $dayNum < 1) {
    evalEligError('Invalid or missing link parameters.', 422);
}

try {
    // Load registration + participant + training
    $stmtR = $conn->prepare("
        SELECT r.id AS reg_id, r.training_id, r.participant_id,
               r.registration_status, r.payment_status,
               DATE(r.created_at) AS reg_date,
               p.fname, p.lname, p.mname, p.suffix, p.email,
               t.name AS training_name, t.start_date, t.end_date,
               t.no_hrs, t.mode, t.location
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        JOIN   otrs_trainings     t ON t.id = r.training_id
        WHERE  r.id = :rid
        LIMIT  1
    ");
    $stmtR->execute([':rid' => $regId]);
    $reg = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$reg) evalEligError('Registration not found.', 404);

    // Verify HMAC signature
    $expectedSig = evalFormSig($regId, $dayNum, $reg['email'], $reg['reg_date']);
    if (!hash_equals($expectedSig, $sig)) evalEligError('Invalid link signature.', 403);

    // Registration status checks
    if (in_array(strtolower($reg['registration_status']), ['cancelled', 'canceled'], true)) {
        evalEligError('Your registration has been cancelled.', 403);
    }
    if (in_array(strtolower($reg['payment_status']), ['cancelled', 'canceled', 'not qualified'], true)) {
        evalEligError('Your registration is not eligible for this form.', 403);
    }

    $participantId = (int)$reg['participant_id'];
    $trainingId    = (int)$reg['training_id'];

    // Certificate early exit
    $stmtC = $conn->prepare("
        SELECT id, is_claimed, certificate_number FROM otrs_certificates
        WHERE participant_id = :pid AND training_id = :tid LIMIT 1
    ");
    $stmtC->execute([':pid' => $participantId, ':tid' => $trainingId]);
    $cert       = $stmtC->fetch(PDO::FETCH_ASSOC);
    $certExists = !empty($cert);
    $certClaimed = $certExists && (bool)$cert['is_claimed'];

    // Training dates (excludes suspensions)
    $trainingDates = EvaluationService::buildTrainingDates(
        $conn, $trainingId, $reg['start_date'], $reg['end_date']
    );
    $totalDays  = count($trainingDates);
    $today      = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $lastDate   = !empty($trainingDates) ? $trainingDates[$totalDays - 1] : null;
    $trainingEnded = $lastDate !== null && $today >= $lastDate;

    // Eval days
    $evalDays       = EvaluationService::getEvalDays($conn, $trainingId, $totalDays);
    $currentEvalDay = EvaluationService::currentEvalDay(
        $conn, $participantId, $trainingId, $evalDays, $trainingDates, $today
    );

    // Per-eval-day submission status
    $submittedRows  = [];
    $evalDayStatus  = [];
    if (!empty($evalDays)) {
        $ph    = implode(',', array_fill(0, count($evalDays), '?'));
        $stmtS = $conn->prepare("SELECT day_number, submitted_at FROM otrs_evaluations WHERE participant_id = ? AND training_id = ? AND day_number IN ($ph)");
        $stmtS->execute(array_merge([$participantId, $trainingId], $evalDays));
        foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $submittedRows[(int)$r['day_number']] = $r['submitted_at'];
        }
        foreach ($evalDays as $dn) {
            $date = $trainingDates[$dn - 1] ?? null;
            $evalDayStatus[] = [
                'day_number'   => $dn,
                'date'         => $date,
                'passed'       => $date !== null && $date <= $today,
                'submitted'    => isset($submittedRows[$dn]),
                'submitted_at' => $submittedRows[$dn] ?? null,
            ];
        }
    }

    $evalSubmitted = $currentEvalDay !== null && isset($submittedRows[$currentEvalDay]);

    // All attendance records (self + hrd). HRD-marked rows take priority over self.
    // Priority: hrd > self — if both exist for the same day, hrd wins.
    $stmtA = $conn->prepare("
        SELECT day_number, status, source FROM otrs_attendance
        WHERE participant_id = :pid AND training_id = :tid
        ORDER BY day_number ASC,
                 CASE source WHEN 'hrd' THEN 0 ELSE 1 END ASC
    ");
    $stmtA->execute([':pid' => $participantId, ':tid' => $trainingId]);
    $existingAttendance = [];
    $hrdLockedDays      = [];   // day numbers overridden by HRD
    foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $dn = (int)$a['day_number'];
        // hrd rows win — only write if not already set by a prior hrd row
        if (!isset($existingAttendance[$dn]) || $a['source'] === 'hrd') {
            $existingAttendance[$dn] = $a['status'];
        }
        if ($a['source'] === 'hrd') {
            $hrdLockedDays[$dn] = true;
        }
    }

    // Participant display name
    $mi   = !empty($reg['mname']) ? strtoupper($reg['mname'][0]) . '. ' : '';
    $base = strtoupper(trim("{$reg['fname']} {$mi}{$reg['lname']}"));
    $sfx  = !empty($reg['suffix']) ? ', ' . strtoupper($reg['suffix']) : '';

    echo json_encode([
        'success'             => true,
        'participant'         => [
            'name'            => $base . $sfx,
            'email_masked'    => maskEmail($reg['email']),
            'registration_id' => (int)$reg['reg_id'],
        ],
        'training'            => [
            'id'           => $trainingId,
            'name'         => $reg['training_name'],
            'display_date' => formatDateRange($reg['start_date'], $reg['end_date']),
            'no_hrs'       => (int)$reg['no_hrs'],
            'mode'         => $reg['mode'],
            'location'     => $reg['location'],
        ],
        'training_dates'      => $trainingDates,
        'total_days'          => $totalDays,
        'training_ended'      => $trainingEnded,
        'existing_attendance' => $existingAttendance,
        'hrd_locked_days'     => array_keys($hrdLockedDays),
        'eval_submitted'      => $evalSubmitted,
        'eval_days'           => $evalDayStatus,
        'current_eval_day'    => $currentEvalDay,
        'today'               => $today,
        'cert_exists'         => $certExists,
        'cert_claimed'        => $certClaimed,
        'cert_number'         => $certExists ? $cert['certificate_number'] : null,
    ]);

} catch (PDOException $e) {
    error_log('[eval_eligibility] ' . $e->getMessage());
    evalEligError('A system error occurred. Please try again later.', 500);
}
