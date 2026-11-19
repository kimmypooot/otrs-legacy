<?php
/**
 * delete_hrd_calendar.php
 * -----------------------
 * Soft-deletes a custom event from otrs_hrd_calendar.
 * Training events (is_synced_training = 1) are protected and cannot be deleted here.
 *
 * POST params:
 *   id  — the calendar event ID (integer)
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');

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

$id             = (int)($_POST['id']              ?? 0);
$deleteType     = trim($_POST['deleteType']       ?? 'all');   // 'single' | 'future' | 'all'
$occurrenceDate = trim($_POST['occurrence_date']  ?? '');      // Y-m-d of the clicked occurrence

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid event ID.']);
    exit;
}

try {
    // Fetch the event to validate
    $fetchStmt = $conn->prepare("
        SELECT id, title, `start`, is_synced_training, recurrence_type, recurrence_exceptions
        FROM   `otrs_hrd_calendar`
        WHERE  id = :id AND status != 'deleted'
    ");
    $fetchStmt->execute([':id' => $id]);
    $event = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found or already deleted.']);
        exit;
    }

    // Protect synced training events from being deleted here
    if ((int)$event['is_synced_training'] === 1) {
        echo json_encode([
            'success' => false,
            'message' => 'Training events are read-only. Manage them via the Training List.',
        ]);
        exit;
    }

    // ── Delete single occurrence: add date to exceptions JSON ────────────────
    if ($deleteType === 'single'
        && !empty($occurrenceDate)
        && $event['recurrence_type'] !== 'none'
    ) {
        $exceptions = [];
        if (!empty($event['recurrence_exceptions'])) {
            $decoded = json_decode($event['recurrence_exceptions'], true);
            if (is_array($decoded)) {
                $exceptions = $decoded;
            }
        }
        if (!in_array($occurrenceDate, $exceptions, true)) {
            $exceptions[] = $occurrenceDate;
        }

        $excStmt = $conn->prepare("
            UPDATE `otrs_hrd_calendar`
            SET    recurrence_exceptions = :exc,
                   updated_at            = NOW()
            WHERE  id                    = :id
              AND  is_synced_training    = 0
        ");
        $excStmt->execute([':exc' => json_encode($exceptions), ':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Occurrence removed.']);
        exit;
    }

    // ── Delete entire series (or a one-off event): soft-delete ───────────────
    $delStmt = $conn->prepare("
        UPDATE `otrs_hrd_calendar`
        SET    status     = 'deleted',
               updated_at = NOW()
        WHERE  id         = :id
          AND  is_synced_training = 0
    ");
    $delStmt->execute([':id' => $id]);

    echo json_encode(['success' => true, 'message' => 'Event deleted successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
