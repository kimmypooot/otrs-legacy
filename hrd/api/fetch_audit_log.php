<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$action      = trim($_GET['action']      ?? '');
$entity      = trim($_GET['entity']      ?? '');
$trainingId  = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT) ?: null;
$dateFrom    = trim($_GET['date_from']   ?? '');
$dateTo      = trim($_GET['date_to']     ?? '');
$search      = trim($_GET['search']      ?? '');

$where  = [];
$params = [];

if ($action !== '') {
    $where[]              = 'l.action = :action';
    $params[':action']    = $action;
}
if ($entity !== '') {
    $where[]              = 'l.entity = :entity';
    $params[':entity']    = $entity;
}
if ($trainingId) {
    $where[]              = 'l.training_id = :tid';
    $params[':tid']       = $trainingId;
}
if ($dateFrom !== '') {
    $where[]              = 'DATE(l.performed_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]              = 'DATE(l.performed_at) <= :date_to';
    $params[':date_to']   = $dateTo;
}
if ($search !== '') {
    $where[]              = '(l.participant_name LIKE :q1 OR l.performed_by LIKE :q2 OR l.details LIKE :q3)';
    $params[':q1']        = "%{$search}%";
    $params[':q2']        = "%{$search}%";
    $params[':q3']        = "%{$search}%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $stmt = $conn->prepare("
        SELECT l.id, l.action, l.entity, l.entity_id,
               l.training_id, t.name AS training_name,
               l.participant_name, l.details,
               l.performed_by, l.performed_at, l.ip_address
        FROM   otrs_audit_log l
        LEFT JOIN otrs_trainings t ON t.id = l.training_id
        {$whereClause}
        ORDER  BY l.performed_at DESC
        LIMIT  500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    $i = 1;
    foreach ($rows as $r) {
        $actionLabel = str_replace('_', ' ', ucfirst($r['action']));
        $data[] = [
            'num'              => $i++,
            'action'           => htmlspecialchars($actionLabel),
            'action_raw'       => $r['action'],
            'entity'           => htmlspecialchars($r['entity']),
            'entity_id'        => $r['entity_id'],
            'training_name'    => htmlspecialchars($r['training_name'] ?? '—'),
            'participant_name' => htmlspecialchars($r['participant_name'] ?? '—'),
            'details'          => htmlspecialchars($r['details'] ?? ''),
            'performed_by'     => htmlspecialchars($r['performed_by'] ?? '—'),
            'performed_at'     => $r['performed_at'],
            'ip_address'       => htmlspecialchars($r['ip_address'] ?? '—'),
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (PDOException $e) {
    error_log('fetch_audit_log: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load audit log.']);
}
