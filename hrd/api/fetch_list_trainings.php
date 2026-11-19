<?php
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

try {
    $draw   = isset($_POST['draw'])            ? (int)$_POST['draw']           : 1;
    $start  = isset($_POST['start'])           ? max(0, (int)$_POST['start'])  : 0;
    $length = isset($_POST['length'])          ? max(1, (int)$_POST['length']) : 10;
    $search = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';

    $totalRecords = (int)$conn
        ->query("SELECT COUNT(*) FROM otrs_training_names WHERE status = 'active'")
        ->fetchColumn();

    $whereClauses = ["status = 'active'"];
    $params       = [];

    if ($search !== '') {
        $whereClauses[] = 'name LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);

    $filteredStmt = $conn->prepare(
        "SELECT COUNT(*) FROM otrs_training_names {$whereSQL}"
    );
    $filteredStmt->execute($params);
    $filteredRecords = (int)$filteredStmt->fetchColumn();

    $params[':start']  = $start;
    $params[':length'] = $length;

    $dataStmt = $conn->prepare("
        SELECT id, name, default_target, default_level, default_description
        FROM otrs_training_names
        {$whereSQL}
        ORDER BY name ASC
        LIMIT :start, :length
    ");

    foreach ([':start', ':length'] as $intKey) {
        $dataStmt->bindValue($intKey, $params[$intKey], PDO::PARAM_INT);
    }
    if ($search !== '') {
        $dataStmt->bindValue(':search', $params[':search'], PDO::PARAM_STR);
    }

    $dataStmt->execute();
    $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data'            => $data,
    ]);

} catch (PDOException $e) {
    error_log('fetch_list_trainings.php PDO error: ' . $e->getMessage());
    echo json_encode(['error' => 'A database error occurred.']);
}
