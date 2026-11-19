<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['data' => []]);
    exit;
}

$backupDir = __DIR__ . '/../db_backup';
$data = [];

if (is_dir($backupDir)) {
    foreach (scandir($backupDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $itemPath = $backupDir . '/' . $item;
        if (is_file($itemPath) && pathinfo($itemPath, PATHINFO_EXTENSION) === 'sql') {
            $data[] = [
                'file'     => $item,
                'modified' => date('Y-m-d H:i:s', filemtime($itemPath)),
                'download' => 'db_backup/' . $item,
            ];
        }
    }
}

echo json_encode(['data' => $data]);
?>
