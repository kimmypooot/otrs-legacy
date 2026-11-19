<?php
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
            ot.name      AS training_name,
            ot.start_date AS training_date,
            SUM(CASE WHEN p.gender = 'Male'   AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 24 AND 28 THEN 1 ELSE 0 END) AS male_gen_z,
            SUM(CASE WHEN p.gender = 'Male'   AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 29 AND 43 THEN 1 ELSE 0 END) AS male_millennial,
            SUM(CASE WHEN p.gender = 'Male'   AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 44 AND 59 THEN 1 ELSE 0 END) AS male_gen_x,
            SUM(CASE WHEN p.gender = 'Male'   AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 60 AND 64 THEN 1 ELSE 0 END) AS male_babyboomers,
            SUM(CASE WHEN p.gender = 'Male'   AND (p.dob IS NULL OR p.dob = '0000-00-00') THEN 1 ELSE 0 END)                 AS male_not_indicated,
            SUM(CASE WHEN p.gender = 'Female' AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 24 AND 28 THEN 1 ELSE 0 END) AS female_gen_z,
            SUM(CASE WHEN p.gender = 'Female' AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 29 AND 43 THEN 1 ELSE 0 END) AS female_millennial,
            SUM(CASE WHEN p.gender = 'Female' AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 44 AND 59 THEN 1 ELSE 0 END) AS female_gen_x,
            SUM(CASE WHEN p.gender = 'Female' AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 60 AND 64 THEN 1 ELSE 0 END) AS female_babyboomers,
            SUM(CASE WHEN p.gender = 'Female' AND (p.dob IS NULL OR p.dob = '0000-00-00') THEN 1 ELSE 0 END)                 AS female_not_indicated
        FROM otrs_trainings ot
        LEFT JOIN otrs_registrations r ON r.training_id = ot.id AND r.payment_status = 'paid'
        LEFT JOIN otrs_participants  p ON r.participant_id = p.id
        WHERE ot.training_status IN ('completed', 'ongoing')
        GROUP BY ot.id, ot.name, ot.start_date
        ORDER BY ot.start_date DESC
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        echo json_encode($results);
    } else {
        echo json_encode(['error' => 'No completed and paid training data found.']);
    }
} catch (PDOException $e) {
    error_log('fetch_gender_age_breakdown: ' . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
