<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

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

$trainingId = trim($_POST['trainingId'] ?? '');

if ($trainingId === '' || !ctype_digit($trainingId)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing training ID.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            ot.id,
            ot.name,
            ot.location,
            ot.registration_link          AS link,
            ot.mode,
            CAST(ot.fee AS CHAR)          AS fee,
            ot.training_status            AS status_trng,
            ot.status,
            ot.target_audience            AS target,
            ot.level,
            ot.slots,
            ot.no_hrs,
            ot.description,
            ot.start_date,
            ot.end_date,
            ot.registration_deadline      AS deadline,
            ot.type                       AS training_type,
            ot.force_display,
            ot.is_invite_only,
            ot.invite_token,
            ot.created_at,
            ot.updated_at,
            COALESCE(
              (SELECT COUNT(*) FROM otrs_registrations r
               WHERE r.training_id = ot.id
                 AND r.registration_status NOT IN ('cancelled','not qualified')),
              0
            ) AS enrolled_count,
            (SELECT COUNT(*) FROM otrs_training_suspensions s
             WHERE s.training_id = ot.id) AS suspended_count
        FROM otrs_trainings ot
        WHERE ot.id = :id
        LIMIT 1
    ");

    $stmt->execute([':id' => (int)$trainingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Training not found.']);
        exit;
    }

    $row['start_date'] = ($row['start_date'] === '0000-00-00' || $row['start_date'] === null)
        ? '' : $row['start_date'];

    $row['end_date'] = ($row['end_date'] === '0000-00-00' || $row['end_date'] === null)
        ? '' : $row['end_date'];

    $row['deadline'] = ($row['deadline'] === '0000-00-00' || $row['deadline'] === null)
        ? '' : $row['deadline'];

    $row['fee']           = (float)$row['fee'];
    $row['force_display'] = (int)$row['force_display'];

    $stmtDates = $conn->prepare(
        "SELECT training_date FROM otrs_training_dates WHERE training_id = :id ORDER BY training_date ASC"
    );
    $stmtDates->execute([':id' => (int)$trainingId]);
    $row['training_dates'] = array_column($stmtDates->fetchAll(PDO::FETCH_ASSOC), 'training_date');

    echo json_encode($row);

} catch (PDOException $e) {
    error_log('fetch_data.php PDO error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
