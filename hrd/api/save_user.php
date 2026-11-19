<?php
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    die('Access denied.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    die('Invalid CSRF token.');
}

$userId      = filter_var($_POST['userId'] ?? null, FILTER_VALIDATE_INT);
$newUsername = trim($_POST['username'] ?? '');
$name        = trim($_POST['name']     ?? '');
$email       = trim($_POST['email']    ?? '');

if (!$userId || $newUsername === '' || $name === '') {
    die('Error: Missing required fields.');
}

ini_set('log_errors', 1);

try {
    $stmt = $conn->prepare(
        'UPDATE users_portal SET username = :newUsername, name = :name, email = :email WHERE id = :userId'
    );
    $stmt->bindParam(':newUsername', $newUsername);
    $stmt->bindParam(':name',        $name);
    $stmt->bindParam(':email',       $email);
    $stmt->bindParam(':userId',      $userId, PDO::PARAM_INT);
    $stmt->execute();

    echo 'success';
} catch (PDOException $e) {
    error_log($e->getMessage());
    echo 'Error: Failed to save user details. Please try again.';
}
?>
