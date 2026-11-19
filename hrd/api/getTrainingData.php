<?php
// getTrainingData.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!isset($_POST['trainingId'])) {
    echo json_encode(['error' => 'No training ID provided']);
    exit;
}

$trainingId = filter_var($_POST['trainingId'], FILTER_VALIDATE_INT);
if (!$trainingId) {
    echo json_encode(['error' => 'Invalid training ID']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT ot.id,
               ot.name,
               ot.start_date,
               ot.end_date,
               ot.registration_link AS link,
               ot.location,
               ot.mode,
               ot.fee
        FROM otrs_trainings ot
        WHERE ot.id = :id
        LIMIT 1
    ");
    $stmt->bindParam(':id', $trainingId, PDO::PARAM_INT);
    $stmt->execute();
    $trainingData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($trainingData) {
        echo json_encode($trainingData);
    } else {
        echo json_encode(['error' => 'Training not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
