<?php
/**
 * hrd/api/fetch_participants_db.php
 * Returns the deduplicated participant list for index_participants.php.
 * Deduplication: per (fname, lname) pair, keeps the row with MAX(id).
 * Enriched with training_count and cert_count per participant.
 */
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            p.id,
            CONCAT_WS(' ',
                TRIM(p.fname),
                NULLIF(CONCAT(LEFT(TRIM(p.mname),1),'.'), '.'),
                TRIM(p.lname)
            )                                   AS full_name,
            p.suffix,
            p.position_title                    AS positiontitle,
            p.mobile_no                         AS mobileno,
            p.email,
            p.agency_name,
            p.location,
            COUNT(DISTINCT r.id)                AS training_count,
            COUNT(DISTINCT c.id)                AS cert_count
        FROM otrs_participants p
        INNER JOIN (
            SELECT fname, lname, MAX(id) AS max_id
            FROM   otrs_participants
            GROUP  BY fname, lname
        ) latest ON p.id = latest.max_id
        LEFT JOIN otrs_registrations r
            ON r.participant_id = p.id
            AND r.registration_status NOT IN ('Cancelled')
        LEFT JOIN otrs_certificates c
            ON c.participant_id = p.id
            AND c.status = 'active'
        GROUP BY p.id
        ORDER BY p.lname, p.fname
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data    = [];
    $counter = 1;
    foreach ($rows as $row) {
        $sfx       = !empty(trim($row['suffix'] ?? '')) ? ', ' . trim($row['suffix']) : '';
        $fullName  = htmlspecialchars($row['full_name'] . $sfx, ENT_QUOTES, 'UTF-8');
        $trainCount = (int)$row['training_count'];
        $certCount  = (int)$row['cert_count'];

        $trainBadge = $trainCount > 0
            ? "<span class=\"badge\" style=\"background:#0a58ca\">{$trainCount}</span>"
            : "<span class=\"badge bg-secondary\">0</span>";

        $certBadge = $certCount > 0
            ? "<span class=\"badge\" style=\"background:#198754\">{$certCount}</span>"
            : "<span class=\"badge bg-secondary\">0</span>";

        $data[] = [
            'id'             => $counter++,
            'pid'            => (int)$row['id'],
            'full_name'      => $fullName,
            'positiontitle'  => htmlspecialchars($row['positiontitle'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'mobileno'       => htmlspecialchars($row['mobileno'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'email'          => htmlspecialchars($row['email'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'agency_name'    => htmlspecialchars($row['agency_name'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'location'       => htmlspecialchars($row['location'] ?? '—', ENT_QUOTES, 'UTF-8'),
            'training_count' => $trainBadge,
            'cert_count'     => $certBadge,
            'action'         => '<button class="btn btn-primary btn-sm view-participant-btn"'
                              . ' data-pid="' . (int)$row['id'] . '"'
                              . ' data-name="' . $fullName . '"'
                              . ' title="View Training History">'
                              . '<i class="bi bi-clock-history"></i></button>',
        ];
    }

    echo json_encode(['data' => $data]);

} catch (PDOException $e) {
    error_log('fetch_participants_db.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load data.']);
}
