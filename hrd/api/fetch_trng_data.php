<?php
/**
 * hrd/api/fetch_trng_data.php
 * Returns training history for a single participant (by participant_id).
 * Also returns summary stats: total_trainings, total_certs, payment_breakdown.
 *
 * POST: pid (participant ID)
 */
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$pid = (int)($_POST['pid'] ?? 0);
if ($pid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant ID.']);
    exit;
}

try {
    // Participant profile
    $stmt = $conn->prepare("
        SELECT
            CONCAT_WS(' ',
                TRIM(fname),
                NULLIF(CONCAT(LEFT(TRIM(mname),1),'.'), '.'),
                TRIM(lname)
            )           AS full_name,
            suffix,
            position_title,
            mobile_no,
            email,
            agency_name,
            location
        FROM otrs_participants
        WHERE id = :pid
        LIMIT 1
    ");
    $stmt->execute([':pid' => $pid]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'Participant not found.']);
        exit;
    }

    $sfx = !empty(trim($profile['suffix'] ?? '')) ? ', ' . trim($profile['suffix']) : '';
    $profile['display_name'] = $profile['full_name'] . $sfx;

    // Training history
    $stmt = $conn->prepare("
        SELECT
            r.id                AS reg_id,
            ot.name             AS training,
            ot.start_date,
            ot.end_date,
            ot.training_status,
            r.payment_status,
            r.registration_status,
            r.registered_at,
            c.certificate_number,
            c.status            AS cert_status
        FROM   otrs_registrations r
        JOIN   otrs_trainings ot   ON ot.id = r.training_id
        LEFT JOIN otrs_certificates c
            ON  c.participant_id = :pid1
            AND c.training_id    = r.training_id
            AND c.status         = 'active'
        WHERE  r.participant_id = :pid2
        ORDER  BY r.registered_at DESC
    ");
    $stmt->execute([':pid1' => $pid, ':pid2' => $pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary counters
    $paymentBreakdown = [];
    $certCount        = 0;
    $completedCount   = 0;

    $trainings = [];
    foreach ($rows as $row) {
        // Date display
        $displayDate = '';
        if (!empty($row['start_date']) && $row['start_date'] !== '0000-00-00') {
            $s = new DateTime($row['start_date']);
            $e = (!empty($row['end_date']) && $row['end_date'] !== '0000-00-00')
               ? new DateTime($row['end_date']) : $s;
            if ($s == $e)
                $displayDate = $s->format('F j, Y');
            elseif ($s->format('Y') !== $e->format('Y'))
                $displayDate = $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
            elseif ($s->format('m') !== $e->format('m'))
                $displayDate = $s->format('M j') . ' – ' . $e->format('M j, Y');
            else
                $displayDate = $s->format('F j') . '–' . $e->format('j, Y');
        }

        $payStatus = strtolower(trim($row['payment_status'] ?? ''));
        $paymentBreakdown[$payStatus] = ($paymentBreakdown[$payStatus] ?? 0) + 1;

        if (!empty($row['certificate_number'])) $certCount++;
        if (strtolower($row['training_status'] ?? '') === 'completed') $completedCount++;

        $trainings[] = [
            'training'             => $row['training'],
            'display_date'         => $displayDate,
            'training_status'      => $row['training_status'],
            'payment_status'       => $row['payment_status'],
            'registration_status'  => $row['registration_status'],
            'registered_at'        => $row['registered_at'],
            'certificate_number'   => $row['certificate_number'] ?? null,
        ];
    }

    echo json_encode([
        'success'  => true,
        'profile'  => [
            'display_name'   => htmlspecialchars($profile['display_name'], ENT_QUOTES, 'UTF-8'),
            'position_title' => htmlspecialchars($profile['position_title'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'agency_name'    => htmlspecialchars($profile['agency_name'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'mobile_no'      => htmlspecialchars($profile['mobile_no'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'email'          => htmlspecialchars($profile['email'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'location'       => htmlspecialchars($profile['location'] ?? '—', ENT_QUOTES, 'UTF-8'),
        ],
        'summary'  => [
            'total_trainings'  => count($rows),
            'completed'        => $completedCount,
            'total_certs'      => $certCount,
            'payment_breakdown'=> $paymentBreakdown,
        ],
        'trainings' => $trainings,
    ]);

} catch (PDOException $e) {
    error_log('fetch_trng_data.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load training history.']);
}
