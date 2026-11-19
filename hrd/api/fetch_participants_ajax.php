<?php
// api/fetch_participants_ajax.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $trainingId = filter_input(INPUT_POST, 'trainingId', FILTER_VALIDATE_INT);
    $location   = trim($_POST['location'] ?? '');
    $filter     = trim($_POST['filter'] ?? 'All');

    if (!$trainingId) {
        throw new Exception('Missing or invalid Training ID');
    }

    $query = "SELECT
                p.id,
                p.fname,
                p.mname,
                p.lname,
                p.suffix,
                p.email,
                p.mobile_no         AS mobileno,
                p.gender,
                p.pwd,
                p.dob,
                p.civil_status      AS civilstatus,
                p.salary_grade,
                p.employment_status,
                p.position_title    AS positiontitle,
                p.position_level,
                p.civil_servant,
                p.agency_unit,
                p.agency_name,
                p.sector,
                p.geo,
                p.location,
                p.agency_address,
                p.home_address,
                p.food_restrictions,
                p.radio_personal    AS radioPersonal,
                r.id                AS reg_id,
                r.payment_status    AS status,
                r.registration_status,
                r.training_status   AS status_training,
                r.or_no             AS orno,
                r.or_date           AS ordate,
                r.collecting_officer,
                r.remarks,
                r.proof_file,
                r.radio_charge      AS radioCharge,
                r.radio_cert        AS radioCert,
                r.registered_at     AS timereg,
                r.prime_hrm_discount,
                IFNULL(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), 0) AS age,
                c.certificate_number    AS cert_number,
                c.status                AS cert_status,
                el_ack.sent_at          AS email_ack_sent,
                el_adv.sent_at          AS email_adv_sent,
                el_zoom.sent_at         AS email_zoom_sent
              FROM otrs_participants p
              JOIN otrs_registrations r ON r.participant_id = p.id
              LEFT JOIN otrs_certificates c
                ON  c.participant_id = p.id
                AND c.training_id    = r.training_id
                AND c.status         = 'active'
              LEFT JOIN (
                SELECT registration_id, MAX(sent_at) AS sent_at
                FROM   otrs_email_logs WHERE email_type = 'ack'
                GROUP  BY registration_id
              ) el_ack  ON el_ack.registration_id  = r.id
              LEFT JOIN (
                SELECT registration_id, MAX(sent_at) AS sent_at
                FROM   otrs_email_logs WHERE email_type = 'advisory'
                GROUP  BY registration_id
              ) el_adv  ON el_adv.registration_id  = r.id
              LEFT JOIN (
                SELECT registration_id, MAX(sent_at) AS sent_at
                FROM   otrs_email_logs WHERE email_type = 'zoom_link'
                GROUP  BY registration_id
              ) el_zoom ON el_zoom.registration_id = r.id
              WHERE r.training_id = :training_id";

    $params = [':training_id' => $trainingId];

    if ($location !== '') {
        $query .= " AND p.location = :location";
        $params[':location'] = $location;
    }

    if ($filter === 'cancelled') {
        $query .= " AND r.registration_status = 'cancelled'";
    } elseif ($filter !== 'All') {
        $query .= " AND r.payment_status = :status AND r.registration_status != 'cancelled'";
        $params[':status'] = $filter;
    }

    $query .= " ORDER BY r.registered_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ── Bulk-load registration/payment/cancellation/replacement logs ── */
    $regIds = array_column($rows, 'reg_id');
    $logsByReg      = [];
    $lastLogAtByReg = [];

    if (!empty($regIds)) {
        $inClause = implode(',', array_fill(0, count($regIds), '?'));
        $fmtDate  = fn($s) => $s ? (new DateTime($s))->format('M j, Y g:i A') : '';
        $trackLatest = function ($regId, $eventAt) use (&$lastLogAtByReg) {
            if ($eventAt && (!isset($lastLogAtByReg[$regId]) || $eventAt > $lastLogAtByReg[$regId])) {
                $lastLogAtByReg[$regId] = $eventAt;
            }
        };

        $payStmt = $conn->prepare("
            SELECT registration_id, action, old_status, new_status, updated_by AS actor, updated_at AS event_at
            FROM   otrs_payment_logs
            WHERE  registration_id IN ($inClause)
            ORDER  BY updated_at ASC, id ASC
        ");
        $payStmt->execute($regIds);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $logsByReg[$l['registration_id']][] = sprintf(
                '%s: %s (%s -> %s) by %s',
                $fmtDate($l['event_at']), $l['action'], $l['old_status'] ?: '—', $l['new_status'], $l['actor']
            );
            $trackLatest($l['registration_id'], $l['event_at']);
        }

        $cancelStmt = $conn->prepare("
            SELECT enrollment_id, status, remarks, requested_by, requested_by_location, requested_at,
                   reviewed_by, reviewed_at, review_remarks
            FROM   otrs_cancellation_requests
            WHERE  enrollment_id IN ($inClause)
            ORDER  BY requested_at ASC
        ");
        $cancelStmt->execute($regIds);
        foreach ($cancelStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $loc = $l['requested_by_location'] ? ' (' . strtoupper($l['requested_by_location']) . ')' : '';
            $logsByReg[$l['enrollment_id']][] = sprintf(
                '%s: Cancellation Requested by %s%s%s',
                $fmtDate($l['requested_at']), $l['requested_by'], $loc,
                $l['remarks'] ? ' - ' . $l['remarks'] : ''
            );
            $trackLatest($l['enrollment_id'], $l['requested_at']);
            if (!empty($l['reviewed_at']) && !empty($l['reviewed_by'])) {
                $verb = $l['status'] === 'approved' ? 'Approved' : 'Rejected';
                $logsByReg[$l['enrollment_id']][] = sprintf(
                    '%s: Cancellation %s by %s%s',
                    $fmtDate($l['reviewed_at']), $verb, $l['reviewed_by'],
                    $l['review_remarks'] ? ' - ' . $l['review_remarks'] : ''
                );
                $trackLatest($l['enrollment_id'], $l['reviewed_at']);
            }
        }

        $replStmt = $conn->prepare("
            SELECT rr.enrollment_id, rr.replacement_reason AS remarks, rr.replaced_by AS actor,
                   rr.replaced_by_location, rr.replaced_at AS event_at,
                   CONCAT(op.fname, ' ', op.lname) AS original_name,
                   CONCAT(np.fname, ' ', np.lname) AS new_name
            FROM   otrs_replacement_requests rr
            JOIN   otrs_participants op ON op.id = rr.original_participant_id
            JOIN   otrs_participants np ON np.id = rr.new_participant_id
            WHERE  rr.enrollment_id IN ($inClause)
            ORDER  BY rr.replaced_at ASC
        ");
        $replStmt->execute($regIds);
        foreach ($replStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $loc = $l['replaced_by_location'] ? ' (' . strtoupper($l['replaced_by_location']) . ')' : '';
            $logsByReg[$l['enrollment_id']][] = sprintf(
                '%s: Participant Replaced (%s -> %s) by %s%s%s',
                $fmtDate($l['event_at']), $l['original_name'], $l['new_name'], $l['actor'], $loc,
                $l['remarks'] ? ' - ' . $l['remarks'] : ''
            );
            $trackLatest($l['enrollment_id'], $l['event_at']);
        }
    }

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'id'                  => $row['id'],
            'reg_id'              => $row['reg_id'],
            'fname'               => htmlspecialchars($row['fname']),
            'mname'               => htmlspecialchars($row['mname']),
            'lname'               => htmlspecialchars($row['lname']),
            'suffix'              => htmlspecialchars($row['suffix'] ?? ''),
            'email'               => htmlspecialchars($row['email']),
            'mobileno'            => htmlspecialchars($row['mobileno']),
            'gender'              => htmlspecialchars($row['gender']),
            'pwd'                 => htmlspecialchars($row['pwd'] ?? 'No'),
            'age'                 => (int) $row['age'],
            'civilstatus'         => htmlspecialchars($row['civilstatus']),
            'salary_grade'        => htmlspecialchars($row['salary_grade'] ?? ''),
            'employment_status'   => htmlspecialchars($row['employment_status'] ?? ''),
            'positiontitle'       => htmlspecialchars($row['positiontitle']),
            'position_level'      => htmlspecialchars($row['position_level'] ?? ''),
            'agency_unit'         => htmlspecialchars($row['agency_unit'] ?? ''),
            'agency_name'         => htmlspecialchars($row['agency_name']),
            'sector'              => htmlspecialchars($row['sector'] ?? ''),
            'geo'                 => htmlspecialchars($row['geo'] ?? ''),
            'location'            => htmlspecialchars($row['location']),
            'agency_address'      => htmlspecialchars($row['agency_address'] ?? ''),
            'home_address'        => htmlspecialchars($row['home_address'] ?? ''),
            'food_restrictions'   => htmlspecialchars($row['food_restrictions'] ?? ''),
            'radioPersonal'       => htmlspecialchars($row['radioPersonal'] ?? ''),
            'status'              => $row['status'],
            'registration_status' => $row['registration_status'],
            'status_training'     => $row['status_training'],
            'orno'                => htmlspecialchars($row['orno'] ?? ''),
            'ordate'              => htmlspecialchars($row['ordate'] ?? ''),
            'collecting_officer'  => htmlspecialchars($row['collecting_officer'] ?? ''),
            'remarks'             => htmlspecialchars($row['remarks'] ?? ''),
            'proof_file'          => $row['proof_file'],
            'radioCharge'         => htmlspecialchars($row['radioCharge'] ?? ''),
            'radioCert'           => htmlspecialchars($row['radioCert'] ?? ''),
            'timereg'             => $row['timereg'],
            'prime_hrm_discount'  => (int) ($row['prime_hrm_discount'] ?? 0),
            'cert_number'         => $row['cert_number'] ?? null,
            'cert_status'         => $row['cert_status'] ?? null,
            'email_ack_sent'      => $row['email_ack_sent']  ?? null,
            'email_adv_sent'      => $row['email_adv_sent']  ?? null,
            'email_zoom_sent'     => $row['email_zoom_sent'] ?? null,
            'log_summary'         => htmlspecialchars(implode(' | ', $logsByReg[$row['reg_id']] ?? [])),
            'last_log_at'         => $lastLogAtByReg[$row['reg_id']] ?? null,
        ];
    }

    echo json_encode([
        'success'         => true,
        'data'            => $data,
        'recordsTotal'    => count($data),
        'recordsFiltered' => count($data),
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
