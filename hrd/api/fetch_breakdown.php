<?php
/**
 * fetch_breakdown.php
 * PHP 7.2+ compatible.
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$GENERATIONS = [
    'Gen Z'         => [24, 28],
    'Millennials'   => [29, 43],
    'Gen X'         => [44, 59],
    'Baby Boomers'  => [60, 64],
    'Not Indicated' => [null, null],
];

function classifyGeneration($age, $generations)
{
    $age = (int)$age;
    if ($age === 0) return 'Not Indicated';
    foreach ($generations as $label => $range) {
        if ($range[0] === null) continue;
        if ($age >= $range[0] && $age <= $range[1]) return $label;
    }
    return 'Not Indicated';
}

function classifyGender($raw)
{
    $g = strtolower(trim((string)$raw));
    if ($g === 'male')                    return 'male';
    if ($g === 'female')                  return 'female';
    if (strpos($g, 'lgbt') !== false)     return 'lgbtqia';
    return 'not_indicated';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST requests only.']);
    exit;
}

$trainingId = isset($_POST['training_id']) ? (int)trim($_POST['training_id']) : 0;

if ($trainingId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid training_id is required.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age,
            p.gender,
            p.position_level,
            p.sector,
            r.payment_status      AS status,
            r.registration_status
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE r.training_id = :training_id
          AND r.registration_status != 'cancelled'
    ");
    $stmt->execute([':training_id' => $trainingId]);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allowedLower = ['paid', 'promissory note', 'pending'];

    $participants = array_values(array_filter($allRows, function ($p) use ($allowedLower) {
        return in_array(strtolower(trim((string)($p['status'] ?? ''))), $allowedLower, true);
    }));

    $positionLevels = [];
    $sectors        = [];

    $agg = [];
    foreach (array_keys($GENERATIONS) as $gen) {
        $agg[$gen] = [
            'male'            => 0,
            'female'          => 0,
            'lgbtqia'         => 0,
            'not_indicated'   => 0,
            'position_levels' => [],
            'sectors'         => [],
        ];
    }

    foreach ($participants as $p) {
        $gender   = classifyGender($p['gender']);
        $posLevel = trim((string)($p['position_level'] ?? '')) ?: 'Not Indicated';
        $sector   = trim((string)($p['sector']         ?? '')) ?: 'Not Indicated';
        $gen      = classifyGeneration($p['age'], $GENERATIONS);

        $positionLevels[$posLevel] = true;
        $sectors[$sector]          = true;

        $agg[$gen][$gender]++;
        $agg[$gen]['position_levels'][$posLevel] = ($agg[$gen]['position_levels'][$posLevel] ?? 0) + 1;
        $agg[$gen]['sectors'][$sector]            = ($agg[$gen]['sectors'][$sector]           ?? 0) + 1;
    }

    ksort($positionLevels);
    ksort($sectors);
    $plKeys  = array_keys($positionLevels);
    $secKeys = array_keys($sectors);

    $outputRows = [];
    $grandTotal = 0;
    $colTotals  = [
        'male'            => 0,
        'female'          => 0,
        'lgbtqia'         => 0,
        'not_indicated'   => 0,
        'position_levels' => array_fill_keys($plKeys,  0),
        'sectors'         => array_fill_keys($secKeys, 0),
        'total'           => 0,
    ];

    foreach (array_keys($GENERATIONS) as $gen) {
        $data     = $agg[$gen];
        $rowTotal = $data['male'] + $data['female'] + $data['lgbtqia'] + $data['not_indicated'];
        $grandTotal += $rowTotal;

        $plRow = [];
        foreach ($plKeys as $pl) {
            $count      = isset($data['position_levels'][$pl]) ? $data['position_levels'][$pl] : 0;
            $plRow[$pl] = $count;
            $colTotals['position_levels'][$pl] += $count;
        }

        $secRow = [];
        foreach ($secKeys as $sec) {
            $count        = isset($data['sectors'][$sec]) ? $data['sectors'][$sec] : 0;
            $secRow[$sec] = $count;
            $colTotals['sectors'][$sec] += $count;
        }

        $colTotals['male']          += $data['male'];
        $colTotals['female']        += $data['female'];
        $colTotals['lgbtqia']       += $data['lgbtqia'];
        $colTotals['not_indicated'] += $data['not_indicated'];
        $colTotals['total']         += $rowTotal;

        $outputRows[] = [
            'generation'      => $gen,
            'age_range'       => $GENERATIONS[$gen][0] !== null
                ? $GENERATIONS[$gen][0] . '–' . $GENERATIONS[$gen][1] . ' yrs'
                : 'N/A',
            'male'            => $data['male'],
            'female'          => $data['female'],
            'lgbtqia'         => $data['lgbtqia'],
            'not_indicated'   => $data['not_indicated'],
            'position_levels' => $plRow,
            'sectors'         => $secRow,
            'total'           => $rowTotal,
            'pct'             => 0.0,
        ];
    }

    foreach ($outputRows as &$row) {
        $row['pct'] = $grandTotal > 0
            ? round(($row['total'] / $grandTotal) * 100, 1)
            : 0.0;
    }
    unset($row);

    echo json_encode([
        'columns' => ['position_levels' => $plKeys, 'sectors' => $secKeys],
        'rows'    => $outputRows,
        'totals'  => $colTotals,
        'meta'    => [
            'grand_total'       => $grandTotal,
            'participant_count' => count($participants),
        ],
    ]);

} catch (PDOException $e) {
    error_log('[fetch_breakdown.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
