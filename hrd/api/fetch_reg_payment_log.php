<?php
ob_start();
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$enrollmentId = filter_input(INPUT_POST, 'enrollment_id', FILTER_VALIDATE_INT);
if (!$enrollmentId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid enrollment ID.']);
    exit;
}

try {
    /* ── Registration + participant info ── */
    $stmt = $conn->prepare("
        SELECT r.id,
               COALESCE(r.registered_at, r.created_at) AS registered_at,
               r.payment_status,
               r.or_no,
               r.or_date,
               r.collecting_officer,
               CONCAT(
                   p.fname,
                   IF(p.mname IS NOT NULL AND p.mname <> '', CONCAT(' ', LEFT(p.mname, 1), '.'), ''),
                   ' ', p.lname,
                   IF(p.suffix IS NOT NULL AND p.suffix <> '', CONCAT(', ', p.suffix), '')
               ) AS participant_name,
               p.location
        FROM   otrs_registrations r
        JOIN   otrs_participants   p ON p.id = r.participant_id
        WHERE  r.id = :id
        LIMIT  1
    ");
    $stmt->execute([':id' => $enrollmentId]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        echo json_encode(['status' => 'error', 'message' => 'Enrollment record not found.']);
        exit;
    }

    /* ── Payment status change history ── */
    $payStmt = $conn->prepare("
        SELECT action, old_status, new_status, notes,
               updated_by AS actor,
               updated_at AS event_at
        FROM   otrs_payment_logs
        WHERE  registration_id = :id
        ORDER  BY updated_at ASC, id ASC
    ");
    $payStmt->execute([':id' => $enrollmentId]);
    $payLogs = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Tag each log entry and attach OR details for Paid entries */
    $orDateFmt = (!empty($reg['or_date']) && $reg['or_date'] !== '0000-00-00')
        ? (new DateTime($reg['or_date']))->format('M j, Y')
        : null;
    foreach ($payLogs as &$pl) {
        if ($pl['action'] === 'Participant Info Updated') {
            $pl['event_type'] = 'profile_update';
        } else {
            $pl['event_type'] = 'payment';
            if (strtolower($pl['new_status'] ?? '') === 'paid') {
                $pl['or_no']              = $reg['or_no']             ?: null;
                $pl['or_date']            = $orDateFmt;
                $pl['collecting_officer'] = $reg['collecting_officer'] ?: null;
            }
        }
    }
    unset($pl);

    /* Derive initial status from oldest payment log */
    $initialStatus = count($payLogs) > 0
        ? ($payLogs[0]['old_status'] ?? $reg['payment_status'])
        : $reg['payment_status'];

    /* ── Cancellation requests & reviews ── */
    $cancelStmt = $conn->prepare("
        SELECT id, status, remarks,
               requested_by, requested_by_location, requested_at,
               reviewed_by, reviewed_at, review_remarks
        FROM   otrs_cancellation_requests
        WHERE  enrollment_id = :eid
        ORDER  BY requested_at ASC
    ");
    $cancelStmt->execute([':eid' => $enrollmentId]);

    $cancelEvents = [];
    foreach ($cancelStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $reqLoc = !empty($cr['requested_by_location'])
            ? ' (' . strtoupper($cr['requested_by_location']) . ')' : '';

        $cancelEvents[] = [
            'event_type'     => 'cancellation',
            'action'         => 'Cancellation Requested',
            'actor'          => $cr['requested_by'] . $reqLoc,
            'event_at'       => $cr['requested_at'],
            'remarks'        => $cr['remarks'],
            'request_status' => $cr['status'],
        ];

        if (!empty($cr['reviewed_at']) && !empty($cr['reviewed_by'])) {
            $verb = $cr['status'] === 'approved' ? 'Approved' : 'Rejected';
            $cancelEvents[] = [
                'event_type'     => 'cancellation_review',
                'action'         => "Cancellation {$verb}",
                'actor'          => $cr['reviewed_by'],
                'event_at'       => $cr['reviewed_at'],
                'remarks'        => $cr['review_remarks'],
                'request_status' => $cr['status'],
            ];
        }
    }

    /* ── Replacement requests ── */
    $replStmt = $conn->prepare("
        SELECT rr.replacement_reason      AS remarks,
               rr.replaced_by            AS actor,
               rr.replaced_by_location,
               rr.replaced_at            AS event_at,
               CONCAT(
                   op.fname,
                   IF(op.mname IS NOT NULL AND op.mname <> '', CONCAT(' ', LEFT(op.mname, 1), '.'), ''),
                   ' ', op.lname,
                   IF(op.suffix IS NOT NULL AND op.suffix <> '', CONCAT(', ', op.suffix), '')
               ) AS original_name,
               CONCAT(
                   np.fname,
                   IF(np.mname IS NOT NULL AND np.mname <> '', CONCAT(' ', LEFT(np.mname, 1), '.'), ''),
                   ' ', np.lname,
                   IF(np.suffix IS NOT NULL AND np.suffix <> '', CONCAT(', ', np.suffix), '')
               ) AS new_name
        FROM   otrs_replacement_requests rr
        JOIN   otrs_participants op ON op.id = rr.original_participant_id
        JOIN   otrs_participants np ON np.id = rr.new_participant_id
        WHERE  rr.enrollment_id = :eid
        ORDER  BY rr.replaced_at ASC
    ");
    $replStmt->execute([':eid' => $enrollmentId]);

    $replEvents = [];
    foreach ($replStmt->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        $byLoc = !empty($rr['replaced_by_location'])
            ? ' (' . strtoupper($rr['replaced_by_location']) . ')' : '';
        $replEvents[] = [
            'event_type'    => 'replacement',
            'action'        => 'Participant Replaced',
            'actor'         => $rr['actor'] . $byLoc,
            'event_at'      => $rr['event_at'],
            'remarks'       => $rr['remarks'],
            'original_name' => $rr['original_name'],
            'new_name'      => $rr['new_name'],
        ];
    }

    /* ── Merge and sort chronologically ── */
    $allEvents = array_merge($payLogs, $cancelEvents, $replEvents);
    usort($allEvents, function ($a, $b) {
        return strcmp($a['event_at'] ?? '', $b['event_at'] ?? '');
    });

    echo json_encode([
        'status'           => 'success',
        'participant_name' => $reg['participant_name'],
        'location'         => $reg['location'],
        'registered_at'    => $reg['registered_at'],
        'payment_status'   => $reg['payment_status'],
        'initial_status'   => $initialStatus,
        'logs'             => $allEvents,
    ]);

} catch (PDOException $e) {
    error_log('[fetch_reg_payment_log] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
exit;
