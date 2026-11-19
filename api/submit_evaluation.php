<?php
/**
 * imis/otrs/api/submit_evaluation.php
 * POST (JSON body)
 *
 * Full attendance + evaluation submission for evaluation_form.php wizard.
 * Mirrors otrs/f2f/api/submit_evaluation.php, authenticated via reg_id+sig.
 *
 * Request body:
 *   reg_id, day, sig          — authentication
 *   attended_days[]           — day numbers participant attended (integers)
 *   attendance_only (bool)    — save attendance only, skip eval
 *   fast_path (bool)          — cert exists, re-issue claim token only
 *   sme_only (bool)           — mid-training SME-day submission
 *   eval_day (int)            — which eval day to submit (default: 1)
 *   sme_ratings[]             — [{sme_id, q1..q4, comments}]
 *   confidence_rating, commitment_rating
 *   confidence_reasons[], commitment_reasons[]
 *   design_q1..q4, design_comment
 *   materials_q1..q4, materials_comment
 *   admin_q1..q4, admin_comment
 *   liked_most, improvements, recommendations, future_topics
 */
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../includes/connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Load OTRS service classes ──────────────────────────────────────────────
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
require_once $_cb . 'services/CertificateService.php';
require_once $_cb . 'services/TokenService.php';
unset($_cb);

function evalSubmitError(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function evalSubmitSuccess(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function evalSubmitSig(int $regId, int $dayNum, string $email, string $regDate): string
{
    $key = $email . substr($regDate, 0, 10);
    return substr(hash_hmac('sha256', "{$regId}:{$dayNum}", $key), 0, 32);
}

// ── Parse JSON body ────────────────────────────────────────────────────────
$payload        = json_decode(file_get_contents('php://input'), true) ?: [];
$regId          = filter_var($payload['reg_id']   ?? null, FILTER_VALIDATE_INT);
$dayNum         = filter_var($payload['day']       ?? null, FILTER_VALIDATE_INT);
$sig            = trim($payload['sig'] ?? '');
$evalDay        = filter_var($payload['eval_day']  ?? 1, FILTER_VALIDATE_INT) ?: 1;
$attendedDays   = is_array($payload['attended_days'] ?? null)
    ? array_values(array_filter(array_map('intval', $payload['attended_days']), fn($d) => $d >= 1))
    : [];
$attendanceOnly = !empty($payload['attendance_only']);
$fastPath       = !empty($payload['fast_path']);
$smeOnly        = !empty($payload['sme_only']);

if (!$regId || !$dayNum || $sig === '' || $dayNum < 1) {
    evalSubmitError('Invalid parameters.', 422);
}

try {
    // Load registration + participant + training
    $stmtR = $conn->prepare("
        SELECT r.id AS reg_id, r.training_id, r.participant_id,
               r.registration_status, r.payment_status,
               DATE(r.created_at) AS reg_date,
               p.email,
               t.name AS training_name, t.start_date, t.end_date, t.training_status
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        JOIN   otrs_trainings     t ON t.id = r.training_id
        WHERE  r.id = :rid
        LIMIT  1
    ");
    $stmtR->execute([':rid' => $regId]);
    $reg = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$reg) evalSubmitError('Registration not found.', 404);

    // Re-verify HMAC on every POST
    $expectedSig = evalSubmitSig($regId, $dayNum, $reg['email'], $reg['reg_date']);
    if (!hash_equals($expectedSig, $sig)) evalSubmitError('Invalid signature.', 403);

    // Registration guards
    if (in_array(strtolower($reg['registration_status']), ['cancelled', 'canceled'], true)) {
        evalSubmitError('Your registration has been cancelled.', 403);
    }
    if (in_array(strtolower($reg['payment_status']), ['cancelled', 'canceled', 'not qualified'], true)) {
        evalSubmitError('Your registration is not eligible.', 403);
    }

    $participantId = (int)$reg['participant_id'];
    $trainingId    = (int)$reg['training_id'];

    // Training dates (excluding suspensions) — same helper used by f2f
    $trainingDates = EvaluationService::buildTrainingDates(
        $conn, $trainingId, $reg['start_date'], $reg['end_date']
    );
    $totalDays = count($trainingDates);
    $today     = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

    // ── Fast path: cert exists but not yet claimed — re-issue token ────────
    if ($fastPath) {
        $stmtC = $conn->prepare("SELECT id, is_claimed, certificate_number FROM otrs_certificates WHERE participant_id = :pid AND training_id = :tid LIMIT 1");
        $stmtC->execute([':pid' => $participantId, ':tid' => $trainingId]);
        $cert = $stmtC->fetch(PDO::FETCH_ASSOC);
        if ($cert && !$cert['is_claimed']) {
            $stmtEv = $conn->prepare("SELECT id FROM otrs_evaluations WHERE participant_id = :pid AND training_id = :tid ORDER BY id DESC LIMIT 1");
            $stmtEv->execute([':pid' => $participantId, ':tid' => $trainingId]);
            $evalIdRow  = (int)($stmtEv->fetchColumn() ?: 0);
            $tokenSvc   = new TokenService($conn);
            $claimToken = $tokenSvc->issue((int)$cert['id'], $participantId, $trainingId, $evalIdRow);
            evalSubmitSuccess([
                'cert_pending' => false,
                'claim_token'  => $claimToken,
                'cert_number'  => $cert['certificate_number'],
                'message'      => 'Certificate ready for download.',
            ]);
        }
        evalSubmitError('Certificate not available or already claimed.', 409);
    }

    // ── Save attendance ────────────────────────────────────────────────────
    if (!empty($attendedDays) || $attendanceOnly) {
        $attendedSet = array_flip($attendedDays);
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM otrs_attendance WHERE registration_id = :rid AND source = 'self'")->execute([':rid' => $regId]);
            $stmtIns = $conn->prepare("
                INSERT INTO otrs_attendance
                    (registration_id, participant_id, training_id, day_number, attendance_date, status, source, marked_at)
                VALUES (:rid, :pid, :tid, :dn, :adate, :status, 'self', NOW())
                ON DUPLICATE KEY UPDATE
                    status    = VALUES(status),
                    source    = VALUES(source),
                    marked_at = VALUES(marked_at)
            ");
            for ($dn = 1; $dn <= $totalDays; $dn++) {
                $adate = $trainingDates[$dn - 1] ?? null;
                if ($adate === null || $adate > $today) continue; // never write future days
                $stmtIns->execute([
                    ':rid'    => $regId,
                    ':pid'    => $participantId,
                    ':tid'    => $trainingId,
                    ':dn'     => $dn,
                    ':adate'  => $adate,
                    ':status' => isset($attendedSet[$dn]) ? 'present' : 'absent',
                ]);
            }
            $conn->commit();
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('[submit_evaluation: attendance] ' . $e->getMessage());
            evalSubmitError('Failed to save attendance. Please try again.', 500);
        }
    }

    if ($attendanceOnly) {
        evalSubmitSuccess([
            'reference' => 'EVAL-ATT-' . date('Y') . '-' . str_pad((string)$regId, 6, '0', STR_PAD_LEFT),
            'message'   => 'Attendance recorded successfully.',
        ]);
    }

    // ── Resolve eval_day against computed eval days ────────────────────────
    $evalDays = EvaluationService::getEvalDays($conn, $trainingId, $totalDays);
    if (!empty($evalDays) && !in_array($evalDay, $evalDays, true)) {
        // Fall back to computed current eval day rather than rejecting
        $currentEvalDay = EvaluationService::currentEvalDay(
            $conn, $participantId, $trainingId, $evalDays, $trainingDates, $today
        );
        if ($currentEvalDay !== null) $evalDay = $currentEvalDay;
    }

    // Guard: reject evaluation for a day that hasn't passed yet
    $evalDate = $trainingDates[$evalDay - 1] ?? null;
    if ($evalDate !== null && $evalDate > $today) {
        evalSubmitError('Evaluation for this day is not yet available.', 403);
    }

    // ── Build evaluation data ──────────────────────────────────────────────
    $smeRatings = is_array($payload['sme_ratings'] ?? null) ? $payload['sme_ratings'] : [];

    if ($smeOnly) {
        $evalData = [
            'confidence_rating'  => 0, 'confidence_reasons' => [],
            'commitment_rating'  => 0, 'commitment_reasons' => [],
            'design_q1'          => 0, 'design_q2'          => 0, 'design_q3' => 0, 'design_q4' => 0, 'design_comment'    => '',
            'materials_q1'       => 0, 'materials_q2'       => 0, 'materials_q3' => 0, 'materials_q4' => 0, 'materials_comment' => '',
            'admin_q1'           => 0, 'admin_q2'           => 0, 'admin_q3' => 0, 'admin_q4' => 0, 'admin_comment'     => '',
            'liked_most'         => '', 'improvements'  => '', 'recommendations' => '', 'future_topics' => '',
            'sme_ratings'        => $smeRatings,
        ];
    } else {
        $evalData = [
            'confidence_rating'  => $payload['confidence_rating']  ?? null,
            'confidence_reasons' => $payload['confidence_reasons'] ?? [],
            'commitment_rating'  => $payload['commitment_rating']  ?? null,
            'commitment_reasons' => $payload['commitment_reasons'] ?? [],
            'design_q1'          => $payload['design_q1']          ?? null,
            'design_q2'          => $payload['design_q2']          ?? null,
            'design_q3'          => $payload['design_q3']          ?? null,
            'design_q4'          => $payload['design_q4']          ?? null,
            'design_comment'     => $payload['design_comment']     ?? '',
            'materials_q1'       => $payload['materials_q1']       ?? null,
            'materials_q2'       => $payload['materials_q2']       ?? null,
            'materials_q3'       => $payload['materials_q3']       ?? null,
            'materials_q4'       => $payload['materials_q4']       ?? null,
            'materials_comment'  => $payload['materials_comment']  ?? '',
            'admin_q1'           => $payload['admin_q1']           ?? null,
            'admin_q2'           => $payload['admin_q2']           ?? null,
            'admin_q3'           => $payload['admin_q3']           ?? null,
            'admin_q4'           => $payload['admin_q4']           ?? null,
            'admin_comment'      => $payload['admin_comment']      ?? '',
            'liked_most'         => $payload['liked_most']         ?? '',
            'improvements'       => $payload['improvements']       ?? '',
            'recommendations'    => $payload['recommendations']    ?? '',
            'future_topics'      => $payload['future_topics']      ?? '',
            'sme_ratings'        => $smeRatings,
        ];
    }

    $evalSvc = new EvaluationService($conn);
    try {
        $result = $evalSvc->submitOrUpdate($participantId, $trainingId, $evalData, $evalDay);
    } catch (InvalidArgumentException $e) {
        evalSubmitError($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        evalSubmitError($e->getMessage(), 500);
    }

    $evalId   = $result['eval_id'];
    $isUpdate = $result['is_update'];
    $ref      = 'EVAL-' . date('Y') . '-' . str_pad((string)$evalId, 6, '0', STR_PAD_LEFT);

    // ── Check if all eval days now complete → trigger certificate ──────────
    $stmtDone = $conn->prepare("SELECT day_number FROM otrs_evaluations WHERE participant_id = :pid AND training_id = :tid");
    $stmtDone->execute([':pid' => $participantId, ':tid' => $trainingId]);
    $submittedNow    = array_map('intval', array_column($stmtDone->fetchAll(PDO::FETCH_ASSOC), 'day_number'));
    $allDaysComplete = empty($evalDays) || !array_diff($evalDays, $submittedNow);

    if (!$allDaysComplete || $smeOnly) {
        $remaining = array_values(array_diff($evalDays, $submittedNow));
        evalSubmitSuccess([
            'cert_pending'   => true,
            'eval_day'       => $evalDay,
            'reference'      => $ref,
            'is_update'      => $isUpdate,
            'remaining_days' => $remaining,
            'submitted_at'   => date('Y-m-d H:i:s'),
            'message'        => $smeOnly
                ? 'Attendance and facilitation evaluation recorded. Please return after the training concludes to complete the general evaluation.'
                : 'Evaluation for Day ' . $evalDay . ' submitted. Please return to complete the remaining evaluation day(s) to receive your certificate.',
        ]);
    }

    // ── Generate certificate ───────────────────────────────────────────────
    $certSvc = new CertificateService($conn);
    $cert    = null;
    try {
        $cert = $certSvc->generate($trainingId, $participantId);
    } catch (RuntimeException $e) {
        error_log('[submit_evaluation] Cert generation failed: ' . $e->getMessage());
    }

    $evalSvc->linkCertificate($evalId, $cert ? (int)$cert['id'] : 0);

    $claimToken = null;
    $certNumber = null;
    if ($cert && !$cert['is_claimed']) {
        $tokenSvc   = new TokenService($conn);
        $claimToken = $tokenSvc->issue((int)$cert['id'], $participantId, $trainingId, $evalId);
        $certNumber = $cert['certificate_number'];
    }

    evalSubmitSuccess([
        'cert_pending' => false,
        'eval_day'     => $evalDay,
        'reference'    => $ref,
        'is_update'    => $isUpdate,
        'submitted_at' => date('Y-m-d H:i:s'),
        'claim_token'  => $claimToken,
        'cert_number'  => $certNumber,
        'expires_in'   => $claimToken ? CLAIM_TOKEN_TTL : null,
        'message'      => $isUpdate ? 'Evaluation updated successfully.' : 'Evaluation submitted successfully.',
    ]);

} catch (PDOException $e) {
    error_log('[submit_evaluation] ' . $e->getMessage());
    evalSubmitError('A database error occurred. Please try again.', 500);
}
