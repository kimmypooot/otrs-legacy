<?php
/**
 * update_hrd_calendar.php
 * -----------------------
 * Updates an existing custom event in otrs_hrd_calendar.
 * Only custom events (is_synced_training = 0) may be updated.
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

// ── Input sanitisation ────────────────────────────────────────────────────────
$id             = (int)($_POST['id']             ?? 0);
$title          = trim($_POST['title']           ?? '');
$description    = trim($_POST['description']     ?? '');
$start          = trim($_POST['start']           ?? '');
$end            = trim($_POST['end']             ?? '');
$allDay         = !empty($_POST['all_day']) ? 1 : 0;
$activityType   = trim($_POST['activity_type']   ?? 'event');
$recurrenceType = trim($_POST['recurrence_type'] ?? 'none');

// ── Validation ────────────────────────────────────────────────────────────────
$errors = [];

if ($id <= 0)       $errors[] = 'Invalid event ID.';
if ($title === '')  $errors[] = 'Title is required.';
if ($start === '')  $errors[] = 'Start date is required.';
if ($end   === '')  $errors[] = 'End date is required.';
if ($start !== '' && $end !== '' && strtotime($start) > strtotime($end)) {
    $errors[] = 'End date must be on or after start date.';
}

$allowedTypes = ['training', 'event', 'leave', 'holiday', 'other'];
if (!in_array($activityType, $allowedTypes, true)) $activityType = 'event';

$allowedRecurrence = ['none', 'monthly', 'quarterly', 'annually'];
if (!in_array($recurrenceType, $allowedRecurrence, true)) $recurrenceType = 'none';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Normalise datetimes ───────────────────────────────────────────────────────
if ($allDay) {
    $start = date('Y-m-d', strtotime($start)) . ' 00:00:00';
    $end   = date('Y-m-d', strtotime($end))   . ' 23:59:59';
} else {
    $start = date('Y-m-d H:i:s', strtotime($start));
    $end   = date('Y-m-d H:i:s', strtotime($end));
}

// ── Update ────────────────────────────────────────────────────────────────────
try {
    // Only allow update on custom events (not auto-synced trainings)
    $stmt = $conn->prepare("
        UPDATE `otrs_hrd_calendar`
        SET
            `title`              = :title,
            `description`        = :description,
            `start`              = :start,
            `end`                = :end,
            `all_day`            = :all_day,
            `recurrence_type`    = :recurrence_type,
            `recurrence_exceptions` = NULL,
            `activity_type`      = :activity_type,
            `updated_at`         = NOW()
        WHERE  `id`                = :id
          AND  `is_synced_training` = 0
          AND  `status`            != 'deleted'
    ");

    $stmt->execute([
        ':id'              => $id,
        ':title'           => $title,
        ':description'     => $description ?: null,
        ':start'           => $start,
        ':end'             => $end,
        ':all_day'         => $allDay,
        ':recurrence_type' => $recurrenceType,
        ':activity_type'   => $activityType,
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Event not found, already deleted, or is a read-only training event.',
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Event updated successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
