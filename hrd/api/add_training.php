<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

// ── 1. Collect & sanitise input ───────────────────────────────────────────────
$name          = trim($_POST['name']          ?? '');
$location      = trim($_POST['location']      ?? '');
$start_date    = trim($_POST['start_date']    ?? '');
$end_date      = trim($_POST['end_date']      ?? '');
$link          = trim($_POST['link']          ?? '');
$deadline      = trim($_POST['deadline']      ?? '');
$mode          = trim($_POST['mode']          ?? '');
$target        = trim($_POST['target']        ?? '');
$level         = trim($_POST['level']         ?? '');
$description   = trim($_POST['description']   ?? '');
$training_type = trim($_POST['training_type'] ?? 'Public-Offered');
$inviteOnly    = !empty($_POST['is_invite_only']) && $training_type === 'Public-Offered';
$inviteSlugRaw = trim($_POST['invite_slug'] ?? '');

$trainingDatesRaw = $_POST['training_dates'] ?? [];
$trainingDates    = [];
if (is_array($trainingDatesRaw) && !empty($trainingDatesRaw)) {
    foreach ($trainingDatesRaw as $d) {
        $d = trim((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) &&
            checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
            $trainingDates[$d] = $d; // dedupe via key
        }
    }
    $trainingDates = array_values($trainingDates);
    sort($trainingDates);
}

// Custom mode: derive start/end from the picked dates, overriding the plain inputs.
if (!empty($trainingDates)) {
    $start_date = $trainingDates[0];
    $end_date   = $trainingDates[count($trainingDates) - 1];
}

$slots  = max(0, (int)   ($_POST['slots']  ?? 0));
$no_hrs = max(0, (int)   ($_POST['no_hrs'] ?? 0));
$fee    = max(0, (float) str_replace(',', '', $_POST['fee'] ?? '0'));

if ($training_type === 'Free') {
    $fee = 0.00;
}

// ── Invite-only slug ──────────────────────────────────────────────────────────
// HRD may type a memorable custom slug (e.g. "hr-leaders-2026"); left blank,
// a short random one is generated instead. Either way it's stored in the same
// invite_token column used by the public-facing gate.
function otrsSlugify(string $raw): string
{
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

function otrsRandomSlug(int $length = 8): string
{
    // No ambiguous characters (0/O, 1/l/I) — easier to read back over phone/email.
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $slug = '';
    for ($i = 0; $i < $length; $i++) {
        $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $slug;
}

$customSlug = $inviteOnly ? otrsSlugify($inviteSlugRaw) : '';

// ── 2. Whitelist validation ───────────────────────────────────────────────────
$validTypes  = ['Public-Offered', 'Free', 'Congress-Conducted'];
$validModes  = ['Face to Face', 'Online', 'Hybrid'];
$validLevels = ['Leadership and Management', 'Foundation', 'Technical'];

$errors = [];

if ($inviteOnly && $inviteSlugRaw !== '' && ($customSlug === '' || strlen($customSlug) < 3 || strlen($customSlug) > 64)) {
    $errors[] = 'Custom link must be 3-64 characters (letters, numbers, hyphens only).';
}
if (empty($name))                              $errors[] = 'Training name is required.';
if (empty($location))                          $errors[] = 'Location is required.';
if (empty($start_date))                        $errors[] = 'Start date is required.';
if (empty($end_date))                          $errors[] = 'End date is required.';
if (!empty($link) && !filter_var($link, FILTER_VALIDATE_URL)) $errors[] = 'Registration URL must be valid (must start with http:// or https://).';
if (empty($deadline))                          $errors[] = 'Registration deadline is required.';
if (empty($target))                            $errors[] = 'Target participants is required.';
if (empty($description))                       $errors[] = 'Description is required.';
if ($slots < 1)                                $errors[] = 'Slots must be at least 1.';
if (!in_array($training_type, $validTypes))    $errors[] = 'Invalid training classification.';
if (!in_array($mode, $validModes))             $errors[] = 'Invalid training mode.';
if (!in_array($level, $validLevels))           $errors[] = 'Invalid curriculum area.';

$startDt = $endDt = $deadlineDt = null;
try {
    $startDt    = new DateTime($start_date);
    $endDt      = new DateTime($end_date);
    $deadlineDt = new DateTime($deadline);

    if ($endDt < $startDt)      $errors[] = 'End date cannot be earlier than start date.';
    if ($deadlineDt > $startDt) $errors[] = 'Deadline must be on or before the start date.';
} catch (Exception $e) {
    $errors[] = 'One or more dates are invalid.';
}

if (!empty($errors)) {
    echo json_encode(['status' => 'error', 'message' => implode(' ', $errors)]);
    exit;
}

// ── 3. Database operation ─────────────────────────────────────────────────────
try {
    // Duplicate check
    $dup = $conn->prepare(
        "SELECT id FROM otrs_trainings
         WHERE name = :name AND start_date = :start_date AND end_date = :end_date
         LIMIT 1"
    );
    $dup->execute([':name' => $name, ':start_date' => $start_date, ':end_date' => $end_date]);

    if ($dup->fetch()) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'A training with the same name and date range already exists.',
        ]);
        exit;
    }

    $inviteToken = null;
    if ($inviteOnly) {
        $slugTaken = static function (string $slug) use ($conn): bool {
            $chk = $conn->prepare('SELECT id FROM otrs_trainings WHERE invite_token = :t LIMIT 1');
            $chk->execute([':t' => $slug]);
            return (bool) $chk->fetch();
        };

        if ($customSlug !== '') {
            if ($slugTaken($customSlug)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'That custom link is already in use. Please choose another.',
                ]);
                exit;
            }
            $inviteToken = $customSlug;
        } else {
            do {
                $candidate = otrsRandomSlug();
            } while ($slugTaken($candidate));
            $inviteToken = $candidate;
        }
    }

    $sql = "
        INSERT INTO otrs_trainings
            (type, name, start_date, end_date, registration_deadline, registration_link,
             location, fee, mode, target_audience, level, slots, no_hrs,
             description, training_status, status, is_invite_only, invite_token)
        VALUES
            (:type, :name, :start_date, :end_date, :deadline, :link,
             :location, :fee, :mode, :target, :level, :slots, :no_hrs,
             :description, 'upcoming', 'active', :is_invite_only, :invite_token)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':type'           => $training_type,
        ':name'           => $name,
        ':start_date'     => $start_date,
        ':end_date'       => $end_date,
        ':deadline'       => $deadline,
        ':link'           => $link,
        ':location'       => $location,
        ':fee'            => $fee,
        ':mode'           => $mode,
        ':target'         => $target,
        ':level'          => $level,
        ':slots'          => $slots,
        ':no_hrs'         => $no_hrs,
        ':description'    => $description,
        ':is_invite_only' => $inviteOnly ? 1 : 0,
        ':invite_token'   => $inviteToken,
    ]);

    $newId = (int) $conn->lastInsertId();

    // Fallback: lastInsertId() can return 0 if AUTO_INCREMENT is not set on the column.
    // Query the row directly so the response always carries a valid ID.
    if ($newId === 0) {
        $sel = $conn->prepare(
            "SELECT id FROM otrs_trainings
             WHERE name = :name AND start_date = :start AND end_date = :end
             ORDER BY id DESC LIMIT 1"
        );
        $sel->execute([':name' => $name, ':start' => $start_date, ':end' => $end_date]);
        $newId = (int) $sel->fetchColumn();
    }

    if ($newId > 0 && !empty($trainingDates)) {
        $insDate = $conn->prepare(
            "INSERT INTO otrs_training_dates (training_id, training_date, day_number)
             VALUES (:tid, :date, :dn)"
        );
        foreach ($trainingDates as $i => $d) {
            $insDate->execute([':tid' => $newId, ':date' => $d, ':dn' => $i + 1]);
        }
    }

    if ($newId > 0) {
        $response = [
            'status'  => 'success',
            'message' => 'Training published successfully.',
            'id'      => $newId,
        ];
        if ($inviteOnly) {
            $response['invite_link'] = 'https://cscro8.com/otrs/index.php?invite=' . $inviteToken;
        }
        echo json_encode($response);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Training was saved but could not retrieve its ID.']);
    }

} catch (PDOException $e) {
    error_log('add_training.php PDO error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
}
