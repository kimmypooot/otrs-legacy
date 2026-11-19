<?php
/**
 * fetch_hrd_calendar.php
 * ---------------------
 * Returns FullCalendar-compatible JSON events array.
 * Sources:
 *   1. otrs_hrd_calendar  → custom events (meetings, leaves, holidays, etc.)
 *   2. otrs_trainings      → live training events (read-only on calendar)
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/training_dates.php';

header('Content-Type: application/json');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// ── Date range from FullCalendar ──────────────────────────────────────────────
$rangeStart = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$rangeEnd   = isset($_GET['end'])   ? $_GET['end']   : date('Y-m-t');

// Sanitise — FullCalendar sends ISO 8601 strings
$rangeStart = preg_replace('/[^0-9\-T:Z\+]/', '', $rangeStart);
$rangeEnd   = preg_replace('/[^0-9\-T:Z\+]/', '', $rangeEnd);

// Convert to plain Y-m-d H:i:s for MySQL comparison
$dtStart = date('Y-m-d H:i:s', strtotime($rangeStart));
$dtEnd   = date('Y-m-d H:i:s', strtotime($rangeEnd));

try {
    $events = [];

    // ── 1. Custom events from otrs_hrd_calendar ───────────────────────────────
    // Fetch non-recurring events that overlap the range, plus ALL recurring
    // events (we expand occurrences in PHP regardless of base start date).
    $sql = "
        SELECT  id, title, description,
                `start`, `end`, all_day,
                activity_type, recurrence_type, recurrence_exceptions,
                training_id, is_synced_training
        FROM    otrs_hrd_calendar
        WHERE   status = 'active'
          AND (
              (recurrence_type = 'none' AND `start` <= :rangeEnd AND `end` >= :rangeStart)
              OR recurrence_type IN ('monthly', 'quarterly', 'annually')
          )
        ORDER BY `start` ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':rangeStart' => $dtStart, ':rangeEnd' => $dtEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        foreach (expandRecurringEvent($row, $dtStart, $dtEnd) as $ev) {
            $events[] = $ev;
        }
    }

    // ── 2. Live trainings from otrs_trainings ────────────────────────────────
    $sqlTrng = "
        SELECT  t.id, t.name,
                t.start_date, t.end_date,
                t.location, t.mode, t.fee, t.slots,
                t.training_status, t.status, t.no_hrs,
                t.type AS training_type,
                (SELECT COUNT(*) FROM otrs_registrations r
                 WHERE  r.training_id = t.id
                   AND  r.registration_status != 'cancelled'
                ) AS enrolled
        FROM    otrs_trainings t
        WHERE   t.status != 'deleted'
          AND   t.start_date != '0000-00-00'
          AND   t.start_date IS NOT NULL
          AND   t.start_date <= :rangeEndDate
          AND   t.end_date   >= :rangeStartDate
        ORDER BY t.start_date ASC
    ";
    $stmtT = $conn->prepare($sqlTrng);
    $stmtT->execute([
        ':rangeStartDate' => substr($dtStart, 0, 10),
        ':rangeEndDate'   => substr($dtEnd,   0, 10),
    ]);
    $trainings = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    foreach ($trainings as $t) {
        foreach (buildTrainingEvents($t, $conn) as $event) {
            $events[] = $event;
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}


// ── HELPERS ───────────────────────────────────────────────────────────────────

/**
 * Build a FullCalendar event object for a custom otrs_hrd_calendar row.
 * $occurrenceDate: Y-m-d string identifying this specific occurrence (empty for one-off events).
 */
function buildCustomEvent(array $row, string $occurrenceDate = ''): array
{
    $isAllDay = (bool)$row['all_day'];

    $colorMap = [
        'training' => '#1565C0',
        'event'    => '#2196F3',
        'leave'    => '#FF9800',
        'holiday'  => '#4CAF50',
        'other'    => '#9E9E9E',
    ];
    $activityType   = $row['activity_type']   ?? 'other';
    $recurrenceType = $row['recurrence_type'] ?? 'none';
    $color = $colorMap[$activityType] ?? '#9E9E9E';

    // Make each occurrence have a unique FullCalendar id to avoid deduplication
    $fcId = $occurrenceDate
        ? 'custom_' . $row['id'] . '_' . str_replace('-', '', $occurrenceDate)
        : 'custom_' . $row['id'];

    return [
        'id'       => $fcId,
        'title'    => $row['title'],
        'start'    => $isAllDay ? substr($row['start'], 0, 10) : $row['start'],
        'end'      => $isAllDay ? substr($row['end'],   0, 10) : $row['end'],
        'allDay'   => $isAllDay,
        'color'    => $color,
        'textColor'=> '#ffffff',
        'extendedProps' => [
            'source'          => 'custom',
            'db_id'           => (int)$row['id'],
            'description'     => $row['description'],
            'activity_type'   => $activityType,
            'recurrence_type' => $recurrenceType,
            'occurrence_date' => $occurrenceDate,
            'training_id'     => $row['training_id'],
        ],
    ];
}

/**
 * Expand a recurring event into individual occurrences within [$rangeStart, $rangeEnd].
 * Returns an array of FullCalendar event objects.
 */
function expandRecurringEvent(array $row, string $rangeStart, string $rangeEnd): array
{
    $recurrenceType = $row['recurrence_type'] ?? 'none';

    if ($recurrenceType === 'none' || empty($recurrenceType)) {
        return [buildCustomEvent($row)];
    }

    $exceptions = [];
    if (!empty($row['recurrence_exceptions'])) {
        $decoded = json_decode($row['recurrence_exceptions'], true);
        if (is_array($decoded)) {
            $exceptions = $decoded;
        }
    }

    switch ($recurrenceType) {
        case 'monthly':   $interval = new DateInterval('P1M'); break;
        case 'quarterly': $interval = new DateInterval('P3M'); break;
        case 'annually':  $interval = new DateInterval('P1Y'); break;
        default:          return [buildCustomEvent($row)];
    }

    $baseStart    = new DateTime($row['start']);
    $baseEnd      = new DateTime($row['end']);
    $duration     = $baseStart->diff($baseEnd);
    $rangeStartDt = new DateTime($rangeStart);
    $rangeEndDt   = new DateTime($rangeEnd);

    $events   = [];
    $occStart = clone $baseStart;
    $maxIter  = 120; // ~10 years of monthly — safety cap

    for ($i = 0; $i < $maxIter; $i++) {
        // Once we've passed the range, stop
        if ($occStart > $rangeEndDt) {
            break;
        }

        $occEnd     = clone $occStart;
        $occEnd->add($duration);
        $occDateStr = $occStart->format('Y-m-d');

        // Only include if this occurrence overlaps the requested range
        if ($occEnd >= $rangeStartDt && !in_array($occDateStr, $exceptions, true)) {
            $occRow          = $row;
            $occRow['start'] = $occStart->format('Y-m-d H:i:s');
            $occRow['end']   = $occEnd->format('Y-m-d H:i:s');
            $events[]        = buildCustomEvent($occRow, $occDateStr);
        }

        $occStart->add($interval);
    }

    return $events;
}

/**
 * Build one or more FullCalendar event objects for an otrs_trainings row.
 * Training events are READ-ONLY on the calendar (cannot be edited here).
 * Returns one continuous-block event for legacy (range) trainings, or one
 * single-day event per date for custom (non-consecutive) trainings.
 */
function buildTrainingEvents(array $t, PDO $conn): array
{
    if (empty($t['start_date']) || $t['start_date'] === '0000-00-00') {
        return [];
    }

    $trainingId  = (int)$t['id'];
    $customDates = getTrainingDateStrings($conn, $trainingId);

    // A single-date custom training and a single-date legacy training render
    // identically, so only branch into multi-event mode when there's more
    // than one non-consecutive date to actually split on.
    $useCustomEvents = count($customDates) > 1
        && (strtotime($customDates[count($customDates) - 1]) - strtotime($customDates[0]))
           !== (count($customDates) - 1) * 86400;

    $statusLower = strtolower(trim($t['training_status'] ?? ''));
    $statusColorMap = [
        'ongoing'   => '#1565C0',
        'completed' => '#546E7A',
        'cancelled' => '#E53935',
    ];
    $statusColor = isset($statusColorMap[$statusLower]) ? $statusColorMap[$statusLower] : '#2E7D32';
    if (strtolower($t['status'] ?? '') === 'cancelled') {
        $statusColor = '#E53935';
    }

    $displayDate = buildDisplayDate($t['start_date'], $t['end_date']);
    $feeLabel    = ((float)$t['fee'] === 0.0) ? 'Free' : '₱' . number_format((float)$t['fee'], 2);
    $enrolled    = (int)($t['enrolled'] ?? 0);
    $slots       = (int)($t['slots']    ?? 0);
    $slotInfo    = $slots > 0 ? "{$enrolled}/{$slots} enrolled" : 'Open';

    $baseProps = [
        'source'        => 'training',
        'activity_type' => 'training',
        'training_id'   => $trainingId,
        'display_date'  => $displayDate,
        'location'      => $t['location'],
        'mode'          => $t['mode'],
        'fee'           => $feeLabel,
        'slots'         => $slotInfo,
        'status_trng'   => $t['training_status'],
        'training_type' => $t['training_type'],
        'no_hrs'        => $t['no_hrs'],
    ];

    if (!$useCustomEvents) {
        $endRaw = (!empty($t['end_date']) && $t['end_date'] !== '0000-00-00')
                  ? date('Y-m-d', strtotime($t['end_date'] . ' +1 day'))
                  : $t['start_date'];

        return [[
            'id'            => 'training_' . $trainingId,
            'title'         => $t['name'],
            'start'         => $t['start_date'],
            'end'           => $endRaw,
            'allDay'        => true,
            'color'         => $statusColor,
            'textColor'     => '#ffffff',
            'extendedProps' => $baseProps,
        ]];
    }

    $events = [];
    foreach ($customDates as $i => $date) {
        $events[] = [
            'id'            => 'training_' . $trainingId . '_' . ($i + 1),
            'title'         => $t['name'],
            'start'         => $date,
            'end'           => date('Y-m-d', strtotime($date . ' +1 day')),
            'allDay'        => true,
            'color'         => $statusColor,
            'textColor'     => '#ffffff',
            'extendedProps' => $baseProps,
        ];
    }
    return $events;
}

/**
 * Format a human-readable date range from start_date / end_date.
 */
function buildDisplayDate(string $startRaw, string $endRaw): string
{
    if (empty($startRaw) || $startRaw === '0000-00-00') return '—';
    $s = new DateTime($startRaw);
    if (empty($endRaw) || $endRaw === '0000-00-00' || $startRaw === $endRaw) {
        return $s->format('F j, Y');
    }
    $e = new DateTime($endRaw);
    if ($s->format('Y') !== $e->format('Y'))
        return $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
    if ($s->format('m') !== $e->format('m'))
        return $s->format('M j') . ' – ' . $e->format('M j, Y');
    return $s->format('F j') . '–' . $e->format('j, Y');
}
