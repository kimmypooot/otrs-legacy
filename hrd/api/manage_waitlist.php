<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$wlId   = filter_input(INPUT_POST, 'wl_id', FILTER_VALIDATE_INT);
$action = trim($_POST['action'] ?? '');

if (!$wlId || !in_array($action, ['promote', 'withdraw'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
    exit;
}

$by = $_SESSION['username'] ?? $_SESSION['name'] ?? 'system';

try {
    // Fetch waitlist entry
    $stmt = $conn->prepare("
        SELECT w.*, p.fname, p.mname, p.lname, p.agency_name, p.email,
               CONCAT_WS(' ', TRIM(p.fname), NULLIF(CONCAT(LEFT(TRIM(p.mname),1),'.'),'.'), TRIM(p.lname)) AS display_name
        FROM otrs_waitlist w
        JOIN otrs_participants p ON p.id = w.participant_id
        WHERE w.id = :id AND w.status = 'waiting'
        LIMIT 1
    ");
    $stmt->execute([':id' => $wlId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        echo json_encode(['status' => 'error', 'message' => 'Waitlist entry not found or already processed.']);
        exit;
    }

    $conn->beginTransaction();

    if ($action === 'withdraw') {
        $conn->prepare("UPDATE otrs_waitlist SET status='withdrawn' WHERE id=:id")
             ->execute([':id' => $wlId]);

        // Re-number remaining entries
        $conn->prepare("
            UPDATE otrs_waitlist SET position = position - 1
            WHERE training_id = :tid AND status = 'waiting' AND position > :pos
        ")->execute([':tid' => (int)$entry['training_id'], ':pos' => (int)$entry['position']]);

        $conn->commit();

        otrsAuditLog($conn, 'waitlist_withdrawn', 'waitlist', $wlId,
            (int)$entry['training_id'], $entry['display_name'],
            null, $by, $_SERVER['REMOTE_ADDR'] ?? null
        );

        echo json_encode(['status' => 'success', 'message' => htmlspecialchars($entry['display_name'], ENT_QUOTES) . ' removed from waitlist.']);
        exit;
    }

    // action === 'promote': check available slots
    $slotsStmt = $conn->prepare("SELECT slots, `type` FROM otrs_trainings WHERE id=:id LIMIT 1");
    $slotsStmt->execute([':id' => (int)$entry['training_id']]);
    $training = $slotsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$training || (int)$training['slots'] <= 0) {
        $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'No available slots. Cannot promote.']);
        exit;
    }

    $payStatus = strtolower($training['type'] ?? '') === 'free' ? 'free' : 'pending';

    // Create registration
    $conn->prepare("
        INSERT INTO otrs_registrations
            (participant_id, training_id, registration_status, payment_status, registered_at, created_at, updated_at)
        VALUES (:pid, :tid, 'confirmed', :pstatus, NOW(), NOW(), NOW())
    ")->execute([
        ':pid'     => (int)$entry['participant_id'],
        ':tid'     => (int)$entry['training_id'],
        ':pstatus' => $payStatus,
    ]);

    // Decrement slot
    $conn->prepare("UPDATE otrs_trainings SET slots = slots - 1 WHERE id=:id AND slots > 0")
         ->execute([':id' => (int)$entry['training_id']]);

    // Mark promoted
    $conn->prepare("UPDATE otrs_waitlist SET status='promoted', promoted_at=NOW() WHERE id=:id")
         ->execute([':id' => $wlId]);

    // Shift remaining positions
    $conn->prepare("
        UPDATE otrs_waitlist SET position = position - 1
        WHERE training_id = :tid AND status = 'waiting' AND position > :pos
    ")->execute([':tid' => (int)$entry['training_id'], ':pos' => (int)$entry['position']]);

    $conn->commit();

    otrsAuditLog($conn, 'waitlist_promoted', 'waitlist', $wlId,
        (int)$entry['training_id'], $entry['display_name'],
        json_encode(['promoted_by' => $by]),
        $by, $_SERVER['REMOTE_ADDR'] ?? null
    );

    echo json_encode([
        'status'        => 'success',
        'message'       => htmlspecialchars($entry['display_name'], ENT_QUOTES) . ' has been promoted and registered.',
        'promoted_name' => htmlspecialchars($entry['display_name'], ENT_QUOTES),
    ]);

} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[manage_waitlist] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error.']);
}
