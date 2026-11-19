<?php
/**
 * hrd/api/fetch_attendance.php
 * GET ?training_id=N
 *
 * Response shape:
 * {
 *   status: 'success',
 *   total_days: N,
 *   training_dates: ['Y-m-d', ...],
 *   eval_days: [
 *     { day_number: N, date: 'Y-m-d'|null, sme_names: ['Name', ...] }
 *   ],
 *   participants: [
 *     { registration_id, participant_id, name, agency, position, location,
 *       payment_status,
 *       attendance:       { 1: {status, notes}, 2: {...}, ... },
 *       days_present:     N,
 *       total_days:       N,
 *       eval_submissions: [1, 3, ...]   ← day_numbers already submitted
 *     }
 *   ]
 * }
 *
 * eval_days rules:
 *   - An "evaluation day" is the LAST day for each SME.
 *   - SME with no day assignments → last day = total training days.
 *   - Multiple SMEs can share the same eval day.
 *   - If no SMEs are assigned, eval_days is empty.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/training_dates.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid training ID.']);
    exit;
}

try {
    // ── Training dates ─────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT id FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    if (!$stmtT->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Training not found.']);
        exit;
    }

    $trainingDates = getTrainingDateStrings($conn, $trainingId);
    $totalDays     = count($trainingDates);

    // ── Evaluation days: last day per SME ──────────────────────────
    // SME with no assigned days → last day = $totalDays
    $evalDaysMap = []; // day_number → [sme_names]
    if ($totalDays > 0) {
        $stmtSme = $conn->prepare("
            SELECT ts.sme_name,
                   COALESCE(MAX(std.day_number), :total_days) AS eval_day
            FROM   otrs_training_smes ts
            LEFT JOIN otrs_sme_training_days std ON std.training_sme_id = ts.id
            WHERE  ts.training_id = :tid
            GROUP BY ts.id, ts.sme_name
            ORDER BY eval_day ASC, ts.sort_order ASC
        ");
        $stmtSme->execute([':total_days' => $totalDays, ':tid' => $trainingId]);
        foreach ($stmtSme->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = (int)$row['eval_day'];
            $evalDaysMap[$d][] = $row['sme_name'];
        }
        ksort($evalDaysMap);
    }

    $evalDays = [];
    foreach ($evalDaysMap as $dayNum => $smeNames) {
        $evalDays[] = [
            'day_number' => $dayNum,
            'date'       => $trainingDates[$dayNum - 1] ?? null,
            'sme_names'  => $smeNames,
        ];
    }

    // ── Participants ───────────────────────────────────────────────
    $stmtP = $conn->prepare("
        SELECT
            r.id AS registration_id,
            p.id AS participant_id,
            TRIM(CONCAT(p.lname, ', ', p.fname,
                        IF(p.mname != '', CONCAT(' ', LEFT(p.mname,1), '.'), ''),
                        IF(p.suffix IS NOT NULL AND p.suffix != '', CONCAT(', ', p.suffix), ''))) AS name,
            p.agency_name AS agency,
            p.position_title AS position,
            p.location,
            r.payment_status
        FROM otrs_registrations r
        JOIN otrs_participants p ON p.id = r.participant_id
        WHERE r.training_id = :tid
          AND r.registration_status NOT IN ('Cancelled', 'cancelled')
          AND LOWER(r.payment_status) IN ('paid', 'promissory note')
        ORDER BY p.lname, p.fname
    ");
    $stmtP->execute([':tid' => $trainingId]);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    if (empty($participants)) {
        echo json_encode([
            'status'         => 'success',
            'total_days'     => $totalDays,
            'training_dates' => $trainingDates,
            'eval_days'      => $evalDays,
            'participants'   => [],
        ]);
        exit;
    }

    // ── Attendance map ─────────────────────────────────────────────
    $stmtA = $conn->prepare("
        SELECT registration_id, day_number, status, notes, source
        FROM otrs_attendance
        WHERE training_id = :tid
    ");
    $stmtA->execute([':tid' => $trainingId]);
    $attendanceMap = [];
    foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $attendanceMap[(int)$a['registration_id']][(int)$a['day_number']] = [
            'status' => $a['status'],
            'notes'  => $a['notes'],
            'source' => $a['source'],
        ];
    }

    // ── Eval submissions map (participant_id → [day_numbers]) ──────
    $stmtEval = $conn->prepare("
        SELECT participant_id, day_number FROM otrs_evaluations WHERE training_id = :tid
    ");
    $stmtEval->execute([':tid' => $trainingId]);
    $evalByParticipant = [];
    foreach ($stmtEval->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $evalByParticipant[(int)$er['participant_id']][] = (int)$er['day_number'];
    }

    // ── Enrich participants ────────────────────────────────────────
    foreach ($participants as &$p) {
        $regId          = (int)$p['registration_id'];
        $paxId          = (int)$p['participant_id'];
        $p['attendance'] = $attendanceMap[$regId] ?? [];

        $present = 0;
        foreach ($p['attendance'] as $day) {
            if ($day['status'] === 'present') $present++;
        }
        $p['days_present']     = $present;
        $p['total_days']       = $totalDays;
        $p['eval_submissions'] = $evalByParticipant[$paxId] ?? [];
    }
    unset($p);

    echo json_encode([
        'status'         => 'success',
        'total_days'     => $totalDays,
        'training_dates' => $trainingDates,
        'eval_days'      => $evalDays,
        'participants'   => $participants,
    ]);

} catch (PDOException $e) {
    error_log('[fetch_attendance] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch attendance.']);
}
