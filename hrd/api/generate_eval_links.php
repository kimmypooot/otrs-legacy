<?php
/**
 * hrd/api/generate_eval_links.php
 * GET ?training_id=N[&day=N]
 * Returns signed evaluation form URLs for all confirmed participants.
 * If day is omitted, returns links for all eval days.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, must-revalidate');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
$filterDay  = filter_input(INPUT_GET, 'day', FILTER_VALIDATE_INT);

if (!$trainingId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid training ID.']);
    exit;
}

function evalFormSig(int $regId, int $dayNum, string $email, string $regDate): string {
    $key = $email . substr($regDate, 0, 10);
    return substr(hash_hmac('sha256', "{$regId}:{$dayNum}", $key), 0, 32);
}

function baseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Resolve to the otrs/ directory URL
    $script = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
    return rtrim("{$scheme}://{$host}{$script}", '/');
}

try {
    // Training dates
    $stmtT = $conn->prepare("SELECT start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Training not found.']);
        exit;
    }

    // Compute total days
    $sd = new DateTime($training['start_date']);
    $ed = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
        ? new DateTime($training['end_date']) : clone $sd;
    $totalDays = (int)$ed->diff($sd)->days + 1;

    // Compute eval days (last day per SME)
    $stmtSme = $conn->prepare("
        SELECT COALESCE(MAX(std.day_number), :total_days) AS eval_day
        FROM   otrs_training_smes ts
        LEFT JOIN otrs_sme_training_days std ON std.training_sme_id = ts.id
        WHERE  ts.training_id = :tid
        GROUP BY ts.id
    ");
    $stmtSme->execute([':total_days' => $totalDays, ':tid' => $trainingId]);
    $evalDays = array_unique(array_map('intval', $stmtSme->fetchAll(PDO::FETCH_COLUMN)));
    sort($evalDays);

    if ($filterDay && !in_array($filterDay, $evalDays, true)) {
        echo json_encode(['status' => 'error', 'message' => "Day {$filterDay} is not an evaluation day."]);
        exit;
    }

    $targetDays = $filterDay ? [$filterDay] : $evalDays;

    // Only paid and promissory note participants attend the training
    $stmtP = $conn->prepare("
        SELECT r.id AS reg_id, r.participant_id,
               TRIM(CONCAT(p.lname, ', ', p.fname)) AS name,
               p.email,
               DATE(r.created_at) AS reg_date,
               r.payment_status
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
          AND  r.registration_status NOT IN ('Cancelled', 'cancelled')
          AND  LOWER(r.payment_status) IN ('paid', 'promissory note')
        ORDER BY p.lname, p.fname
    ");
    $stmtP->execute([':tid' => $trainingId]);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Check existing submissions
    $stmtSub = $conn->prepare("SELECT participant_id, day_number FROM otrs_evaluations WHERE training_id = :tid");
    $stmtSub->execute([':tid' => $trainingId]);
    $submitted = []; // participant_id → [day_numbers]
    foreach ($stmtSub->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $submitted[(int)$s['participant_id']][] = (int)$s['day_number'];
    }

    $base = baseUrl();
    $result = [];

    foreach ($participants as $p) {
        $links = [];
        foreach ($targetDays as $dn) {
            $sig = evalFormSig((int)$p['reg_id'], $dn, $p['email'], $p['reg_date']);
            $url = "{$base}/evaluation_form.php?reg_id={$p['reg_id']}&day={$dn}&sig={$sig}";
            $alreadyDone = in_array($dn, $submitted[(int)$p['participant_id']] ?? [], true);
            $links[] = [
                'day_number' => $dn,
                'url'        => $url,
                'submitted'  => $alreadyDone,
            ];
        }
        $allSubmitted = !empty($links) && count(array_filter($links, fn($l) => $l['submitted'])) === count($links);
        $result[] = [
            'reg_id'         => (int)$p['reg_id'],
            'name'           => $p['name'],
            'email'          => $p['email'],
            'payment_status' => $p['payment_status'],
            'all_submitted'  => $allSubmitted,
            'links'          => $links,
        ];
    }

    echo json_encode([
        'status'    => 'success',
        'eval_days' => $targetDays,
        'data'      => $result,
    ]);

} catch (PDOException $e) {
    error_log('[generate_eval_links] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to generate links.']);
}
