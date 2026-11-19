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

$trainingId    = (int)($_POST['trainingId'] ?? 0);
$inviteSlugRaw = trim($_POST['invite_slug'] ?? '');

if ($trainingId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'A valid training ID is required.']);
    exit;
}

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

try {
    $stmt = $conn->prepare('SELECT id, is_invite_only FROM otrs_trainings WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $trainingId]);
    $training = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$training) {
        echo json_encode(['status' => 'error', 'message' => 'Training not found.']);
        exit;
    }
    if (empty($training['is_invite_only'])) {
        echo json_encode(['status' => 'error', 'message' => 'This training is not invite-only.']);
        exit;
    }

    $customSlug = otrsSlugify($inviteSlugRaw);
    if ($inviteSlugRaw !== '' && ($customSlug === '' || strlen($customSlug) < 3 || strlen($customSlug) > 64)) {
        echo json_encode(['status' => 'error', 'message' => 'Custom link must be 3-64 characters (letters, numbers, hyphens only).']);
        exit;
    }

    $slugTaken = static function (string $slug) use ($conn, $trainingId): bool {
        $chk = $conn->prepare('SELECT id FROM otrs_trainings WHERE invite_token = :t AND id != :id LIMIT 1');
        $chk->execute([':t' => $slug, ':id' => $trainingId]);
        return (bool) $chk->fetch();
    };

    if ($customSlug !== '') {
        if ($slugTaken($customSlug)) {
            echo json_encode(['status' => 'error', 'message' => 'That custom link is already in use. Please choose another.']);
            exit;
        }
        $newToken = $customSlug;
    } else {
        do {
            $candidate = otrsRandomSlug();
        } while ($slugTaken($candidate));
        $newToken = $candidate;
    }

    $upd = $conn->prepare('UPDATE otrs_trainings SET invite_token = :token WHERE id = :id');
    $upd->execute([':token' => $newToken, ':id' => $trainingId]);

    echo json_encode([
        'status'       => 'success',
        'invite_token' => $newToken,
        'invite_link'  => 'https://cscro8.com/otrs/index.php?invite=' . $newToken,
    ]);

} catch (PDOException $e) {
    error_log('regenerate_invite_link.php PDO error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
}
