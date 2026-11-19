<?php
/**
 * hrd/api/export_attendance.php
 * GET ?training_id=N
 * Streams a UTF-8 CSV of the attendance sheet (paid + promissory note only).
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/training_dates.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    http_response_code(422);
    exit('Invalid training ID.');
}

try {
    // ── Training info ──────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT name FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        http_response_code(404);
        exit('Training not found.');
    }

    $trainingDates = getTrainingDateStrings($conn, $trainingId);
    $totalDays     = count($trainingDates);

    // ── Participants (paid + promissory note only) ─────────────────
    $stmtP = $conn->prepare("
        SELECT
            r.id AS registration_id,
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

    // ── Attendance map ─────────────────────────────────────────────
    $stmtA = $conn->prepare("
        SELECT registration_id, day_number, status
        FROM otrs_attendance
        WHERE training_id = :tid
    ");
    $stmtA->execute([':tid' => $trainingId]);
    $attMap = [];
    foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $attMap[(int)$a['registration_id']][(int)$a['day_number']] = $a['status'];
    }

    // ── Stream CSV ─────────────────────────────────────────────────
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($training['name'], 0, 40));
    $filename  = 'Attendance_' . $safeName . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fputs($out, "\xEF\xBB\xBF");

    // ── Header row ─────────────────────────────────────────────────
    $header = ['#', 'Name', 'Agency', 'Position', 'Office/Location', 'Payment Status'];
    for ($i = 0; $i < $totalDays; $i++) {
        $dn  = $i + 1;
        $dt  = isset($trainingDates[$i]) ? ' (' . date('M j, Y', strtotime($trainingDates[$i])) . ')' : '';
        $header[] = 'Day ' . $dn . $dt;
    }
    $header[] = 'Days Present';
    $header[] = 'Total Days';
    fputcsv($out, $header);

    // ── Data rows ──────────────────────────────────────────────────
    $statusLabel = [
        'present' => 'Present',
        'absent'  => 'Absent',
        'excused' => 'Excused',
    ];

    foreach ($participants as $idx => $p) {
        $regId = (int)$p['registration_id'];
        $row   = [
            $idx + 1,
            $p['name'],
            $p['agency']   ?? '',
            $p['position'] ?? '',
            $p['location'] ?? '',
            ucwords(strtolower($p['payment_status'])),
        ];

        $present = 0;
        for ($i = 0; $i < $totalDays; $i++) {
            $dn     = $i + 1;
            $status = $attMap[$regId][$dn] ?? 'not_marked';
            if ($status === 'present') $present++;
            $row[] = $statusLabel[$status] ?? '—';
        }
        $row[] = $present;
        $row[] = $totalDays;
        fputcsv($out, $row);
    }

    fclose($out);

} catch (PDOException $e) {
    error_log('[export_attendance] ' . $e->getMessage());
    http_response_code(500);
    exit('Export failed.');
}
