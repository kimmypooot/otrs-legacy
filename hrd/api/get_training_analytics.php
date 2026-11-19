<?php
/**
 * get_training_analytics.php
 * OTRS — Training Breakdown Analytics Endpoint
 *
 * POST params: training_id (int)
 * Returns:     JSON with five breakdown sections
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Access denied.']));
}

$trainingId = filter_input(INPUT_POST, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    exit(json_encode(['success' => false, 'message' => 'Invalid or missing training_id']));
}

function otrs_fmt_date($s, $e)
{
    if (empty($s) || $s === '0000-00-00') return '—';
    $sd = new DateTime($s);
    $ed = (!empty($e) && $e !== '0000-00-00') ? new DateTime($e) : clone $sd;

    if ($sd->format('Y-m-d') === $ed->format('Y-m-d'))
        return $sd->format('F j, Y');
    if ($sd->format('Y') !== $ed->format('Y'))
        return $sd->format('M j, Y') . ' – ' . $ed->format('M j, Y');
    if ($sd->format('m') !== $ed->format('m'))
        return $sd->format('M j') . ' – ' . $ed->format('M j, Y');

    return $sd->format('F j') . '–' . $ed->format('j, Y');
}

function otrs_zero()
{
    return ['m' => 0, 'f' => 0, 'o' => 0, 'total' => 0];
}

try {
    $pdo = $conn;
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    /* ==============================================================
     *  A.  TRAINING INFO
     * ============================================================== */
    $stTr = $pdo->prepare("
        SELECT ot.id, ot.name, ot.start_date, ot.end_date, ot.no_hrs,
               ot.fee, ot.mode, ot.location, ot.type AS training_type
        FROM otrs_trainings ot
        WHERE ot.id = :id
        LIMIT 1
    ");
    $stTr->execute([':id' => $trainingId]);
    $tr = $stTr->fetch();

    if (!$tr) {
        exit(json_encode(['success' => false, 'message' => 'Training not found']));
    }

    $trainingInfo = [
        'id'            => (int)  $tr['id'],
        'name'          =>        $tr['name'],
        'date'          => otrs_fmt_date($tr['start_date'], $tr['end_date']),
        'no_hrs'        => (int)  $tr['no_hrs'],
        'fee'           => (float)$tr['fee'],
        'mode'          =>        $tr['mode'],
        'location'      =>        $tr['location'],
        'training_type' =>        $tr['training_type'],
    ];

    $bp      = [':tid' => $trainingId];
    $isFree  = ((float)$tr['fee'] === 0.0 || $tr['training_type'] === 'Free');
    $baseWhere = "r.training_id = :tid
                  AND r.registration_status != 'cancelled'
                  AND r.payment_status IN ('paid','promissory note','free')";

    /* ==============================================================
     *  B.  PAX BREAKDOWN
     * ============================================================== */
    $stPax = $pdo->prepare("
        SELECT
            CASE
                WHEN p.gender = 'Male'   THEN 'Male'
                WHEN p.gender = 'Female' THEN 'Female'
                ELSE                          'Others'
            END                                                              AS g,
            SUM(p.civil_servant = 'CS')                                      AS cs_count,
            SUM(COALESCE(p.civil_servant,'') = 'Non-CS'
                AND p.sector IN ('NGA','GOCC','SUC','LWD','LGU'))            AS nc_gov,
            SUM(COALESCE(p.civil_servant,'') = 'Non-CS'
                AND p.sector NOT IN ('NGA','GOCC','SUC','LWD','LGU'))        AS nc_other,
            SUM(p.civil_servant = 'CS' AND p.pwd = 'Yes')                   AS pwd_cs,
            COUNT(*)                                                         AS row_total
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE {$baseWhere}
        GROUP BY g
    ");
    $stPax->execute($bp);
    $paxRaw = $stPax->fetchAll();

    $emptyPax = ['cs' => 0, 'nc_gov' => 0, 'nc_other' => 0, 'pwd_cs' => 0, 'total' => 0];
    $pm       = ['Male' => $emptyPax, 'Female' => $emptyPax, 'Others' => $emptyPax];
    $pt       = $emptyPax;

    foreach ($paxRaw as $r) {
        $pm[$r['g']] = [
            'cs'       => (int)$r['cs_count'],
            'nc_gov'   => (int)$r['nc_gov'],
            'nc_other' => (int)$r['nc_other'],
            'pwd_cs'   => (int)$r['pwd_cs'],
            'total'    => (int)$r['row_total'],
        ];
        $pt['cs']       += (int)$r['cs_count'];
        $pt['nc_gov']   += (int)$r['nc_gov'];
        $pt['nc_other'] += (int)$r['nc_other'];
        $pt['pwd_cs']   += (int)$r['pwd_cs'];
        $pt['total']    += (int)$r['row_total'];
    }

    $paxBreakdown = [
        'rows' => [
            array_merge(['label' => 'Male'],   $pm['Male']),
            array_merge(['label' => 'Female'], $pm['Female']),
            array_merge(['label' => 'Others'], $pm['Others']),
        ],
        'total' => array_merge(['label' => 'TOTAL'], $pt),
    ];

    /* ==============================================================
     *  C.  POSITION LEVEL BREAKDOWN (CS Graduates only)
     * ============================================================== */
    $positionOrder = [
        'First Level',
        'Second Level (Rank-and-File)',
        'Second Level (Non-Supervisory)',
        'Second Level (Supervisory)',
        'Second Level (Executive/Managerial)',
        'Third Level',
    ];

    $stPos = $pdo->prepare("
        SELECT
            p.position_level,
            SUM(p.gender = 'Male')                    AS m,
            SUM(p.gender = 'Female')                  AS f,
            SUM(p.gender NOT IN ('Male','Female'))     AS o,
            COUNT(*)                                   AS tot
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE {$baseWhere}
          AND p.civil_servant = 'CS'
          AND p.position_level IN (
              'First Level',
              'Second Level (Rank-and-File)',
              'Second Level (Non-Supervisory)',
              'Second Level (Supervisory)',
              'Second Level (Executive/Managerial)',
              'Third Level'
          )
        GROUP BY p.position_level
    ");
    $stPos->execute($bp);
    $posMap = array_column($stPos->fetchAll(), null, 'position_level');

    $posRows = [];
    $posT    = otrs_zero();

    foreach ($positionOrder as $lv) {
        $r = $posMap[$lv] ?? ['m' => 0, 'f' => 0, 'o' => 0, 'tot' => 0];

        $row = [
            'level' => $lv,
            'm'     => (int)$r['m'],
            'f'     => (int)$r['f'],
            'o'     => (int)$r['o'],
            'total' => (int)$r['tot'],
        ];
        $posRows[]      = $row;
        $posT['m']     += $row['m'];
        $posT['f']     += $row['f'];
        $posT['o']     += $row['o'];
        $posT['total'] += $row['total'];
    }

    $positionLevel = [
        'rows'  => $posRows,
        'total' => array_merge(['level' => 'TOTAL'], $posT),
    ];

    /* ==============================================================
     *  D.  SECTOR BREAKDOWN (CS Graduates by sector)
     * ============================================================== */
    $govSectors = ['NGA', 'GOCC', 'SUC', 'LWD', 'LGU'];

    $stCsAll = $pdo->prepare("
        SELECT
            SUM(p.gender = 'Male')                    AS m,
            SUM(p.gender = 'Female')                  AS f,
            SUM(p.gender NOT IN ('Male','Female'))     AS o,
            COUNT(*)                                   AS tot
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE {$baseWhere}
          AND p.civil_servant = 'CS'
    ");
    $stCsAll->execute($bp);
    $csAll = $stCsAll->fetch();

    $stSec = $pdo->prepare("
        SELECT
            p.sector,
            SUM(p.gender = 'Male')                    AS m,
            SUM(p.gender = 'Female')                  AS f,
            SUM(p.gender NOT IN ('Male','Female'))     AS o,
            COUNT(*)                                   AS tot
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE {$baseWhere}
          AND p.civil_servant = 'CS'
          AND p.sector IN ('NGA','GOCC','SUC','LWD','LGU')
        GROUP BY p.sector
    ");
    $stSec->execute($bp);
    $secMap = array_column($stSec->fetchAll(), null, 'sector');

    $secRows = [];
    $secT    = otrs_zero();

    foreach ($govSectors as $sec) {
        $r = $secMap[$sec] ?? ['m' => 0, 'f' => 0, 'o' => 0, 'tot' => 0];

        $row = [
            'sector' => $sec,
            'm'      => (int)$r['m'],
            'f'      => (int)$r['f'],
            'o'      => (int)$r['o'],
            'total'  => (int)$r['tot'],
        ];
        $secRows[]      = $row;
        $secT['m']     += $row['m'];
        $secT['f']     += $row['f'];
        $secT['o']     += $row['o'];
        $secT['total'] += $row['total'];
    }

    $sectorBreakdown = [
        'cs_total' => [
            'label' => 'CS Trained',
            'm'     => (int)$csAll['m'],
            'f'     => (int)$csAll['f'],
            'o'     => (int)$csAll['o'],
            'total' => (int)$csAll['tot'],
        ],
        'rows'  => $secRows,
        'total' => array_merge(['sector' => 'TOTAL'], $secT),
    ];

    /* ==============================================================
     *  E.  COLLECTION SUMMARY
     * ============================================================== */
    $stColl = $pdo->prepare("
        SELECT
            SUM(r.payment_status = 'paid')                                       AS paid,
            SUM(r.payment_status = 'promissory note')                            AS prom,
            SUM(r.payment_status = 'pending')                                    AS pending,
            SUM(r.payment_status = 'free')                                       AS free_status,
            SUM(r.registration_status = 'cancelled')                             AS cancelled,
            SUM(r.registration_status = 'not qualified')                         AS not_qual,
            SUM(r.registration_status NOT IN ('cancelled','not qualified'))       AS active_total,
            COUNT(*)                                                              AS grand_total
        FROM otrs_registrations r
        WHERE r.training_id = :tid
    ");
    $stColl->execute([':tid' => $trainingId]);
    $cr = $stColl->fetch();

    $collectionSummary = [
        'is_free'       => $isFree,
        'fee'           => (float)$tr['fee'],
        'paid'          => (int)$cr['paid'],
        'promissory'    => (int)$cr['prom'],
        'pending'       => (int)$cr['pending'],
        'cancelled'     => (int)$cr['cancelled'],
        'not_qualified' => (int)$cr['not_qual'],
        'free_pax'      => (int)$cr['free_status'],
        'active_total'  => (int)$cr['active_total'],
        'grand_total'   => (int)$cr['grand_total'],
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'training_info'      => $trainingInfo,
            'pax_breakdown'      => $paxBreakdown,
            'position_level'     => $positionLevel,
            'sector_breakdown'   => $sectorBreakdown,
            'collection_summary' => $collectionSummary,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
