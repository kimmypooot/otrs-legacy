<?php
/**
 * add_hrd_calendar.php
 * --------------------
 * Inserts a new custom event into otrs_hrd_calendar.
 * Called via POST from the Add Event modal.
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
$title          = trim($_POST['title']           ?? '');
$description    = trim($_POST['description']     ?? '');
$start          = trim($_POST['start']           ?? '');
$end            = trim($_POST['end']             ?? '');
$allDay         = !empty($_POST['all_day']) ? 1 : 0;
$activityType   = trim($_POST['activity_type']   ?? 'event');
$recurrenceType = trim($_POST['recurrence_type'] ?? 'none');

// ── Validation ────────────────────────────────────────────────────────────────
$errors = [];

if ($title === '') {
    $errors[] = 'Title is required.';
}
if ($start === '') {
    $errors[] = 'Start date is required.';
}
if ($end === '') {
    $errors[] = 'End date is required.';
}
if ($start !== '' && $end !== '' && strtotime($start) > strtotime($end)) {
    $errors[] = 'End date must be on or after the start date.';
}

$allowedTypes = ['training', 'event', 'leave', 'holiday', 'other'];
if (!in_array($activityType, $allowedTypes, true)) {
    $activityType = 'event';
}

$allowedRecurrence = ['none', 'monthly', 'quarterly', 'annually'];
if (!in_array($recurrenceType, $allowedRecurrence, true)) {
    $recurrenceType = 'none';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Convert datetime-local → MySQL datetime ────────────────────────────────────
// If allDay, store only the date portion at midnight
if ($allDay) {
    $start = date('Y-m-d', strtotime($start)) . ' 00:00:00';
    $end   = date('Y-m-d', strtotime($end))   . ' 23:59:59';
} else {
    $start = date('Y-m-d H:i:s', strtotime($start));
    $end   = date('Y-m-d H:i:s', strtotime($end));
}

// ── Insert ────────────────────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        INSERT INTO `otrs_hrd_calendar`
            ( `title`, `description`, `start`, `end`, `all_day`,
              `recurrence_type`, `activity_type`, `is_synced_training`, `status`, `created_at` )
        VALUES
            ( :title, :description, :start, :end, :all_day,
              :recurrence_type, :activity_type, 0, 'active', NOW() )
    ");

    $stmt->execute([
        ':title'           => $title,
        ':description'     => $description ?: null,
        ':start'           => $start,
        ':end'             => $end,
        ':all_day'         => $allDay,
        ':recurrence_type' => $recurrenceType,
        ':activity_type'   => $activityType,
    ]);

    $newId = $conn->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Event saved successfully.',
        'id'      => (int)$newId,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
