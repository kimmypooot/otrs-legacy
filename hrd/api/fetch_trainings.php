<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $isDataTables = isset($_POST['draw']);

    if ($isDataTables) {
        $draw    = isset($_POST['draw'])   ? (int)$_POST['draw']              : 1;
        $start   = isset($_POST['start'])  ? max(0, (int)$_POST['start'])     : 0;
        $length  = isset($_POST['length']) ? max(1, (int)$_POST['length'])    : 10;
        $search  = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';
        $orderCol = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 0;
        $orderDir = isset($_POST['order'][0]['dir'])    ? $_POST['order'][0]['dir']          : 'asc';
        $status   = isset($_POST['status']) ? $_POST['status'] : 'active';

        $columns = [
            0 => 'ot.id',
            1 => 'ot.name',
            2 => 'ot.start_date',
            3 => 'ot.location',
            4 => 'ot.mode',
            5 => 'ot.training_status',
            6 => 'ot.fee',
            7 => 'ot.target_audience',
            8 => 'ot.level',
            9 => 'ot.slots',
            10 => 'ot.status',
        ];

        $orderColName = isset($columns[$orderCol]) ? $columns[$orderCol] : 'ot.id';
        $orderDir     = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $whereClauses = ['ot.status = :status'];
        $params       = [':status' => $status];

        if ($search !== '') {
            $whereClauses[] = "(ot.name LIKE :search
                OR ot.location LIKE :search
                OR ot.mode LIKE :search
                OR ot.training_status LIKE :search
                OR ot.target_audience LIKE :search
                OR ot.level LIKE :search
                OR ot.fee LIKE :search
                OR ot.description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);

        $baseJoin = "FROM otrs_trainings ot";

        $totalStmt = $conn->prepare("SELECT COUNT(*) FROM otrs_trainings WHERE status = :status");
        $totalStmt->execute([':status' => $status]);
        $totalRecords = (int)$totalStmt->fetchColumn();

        $filteredStmt = $conn->prepare("SELECT COUNT(*) {$baseJoin} {$whereSQL}");
        $filteredStmt->execute($params);
        $filteredRecords = (int)$filteredStmt->fetchColumn();

        $dataSQL = "
            SELECT
                ot.id,
                ot.name,
                ot.start_date,
                ot.end_date,
                ot.location,
                ot.mode,
                ot.training_status      AS status_trng,
                ot.status,
                ot.fee,
                ot.target_audience      AS target,
                ot.level,
                ot.slots,
                ot.description,
                ot.registration_link    AS link,
                ot.registration_deadline AS deadline,
                ot.type                 AS training_type
            {$baseJoin}
            {$whereSQL}
            ORDER BY {$orderColName} {$orderDir}
            LIMIT :lstart, :llength
        ";

        $dataStmt = $conn->prepare($dataSQL);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':lstart',  $start,  PDO::PARAM_INT);
        $dataStmt->bindValue(':llength', $length, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $counter = $start + 1;
        $formatted = [];
        foreach ($rows as $row) {
            $row['row_num'] = $counter++;
            $formatted[]    = $row;
        }

        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data'            => $formatted,
        ]);

    } else {
        // Simple list fallback
        $stmt = $conn->prepare(
            "SELECT id, name, start_date, end_date
             FROM otrs_trainings
             ORDER BY start_date DESC"
        );
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
