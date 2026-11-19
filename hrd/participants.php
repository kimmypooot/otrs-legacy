<?php
// imis/otrs/hrd/participants.php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/inc/training_dates.php';

$location   = isset($_GET['location']) ? $_GET['location'] : '';
$trainingId = isset($_GET['id'])       ? $_GET['id']       : '';
$filter     = isset($_GET['filter'])   ? $_GET['filter']   : 'All';

$locationMap = [
    ''      => 'CSC RO VIII - Human Resource Division',
    'hrd'   => 'CSC RO VIII - Human Resource Division',
    'lfoi'  => 'CSC Field Office - Leyte I',
    'lfoii' => 'CSC Field Office - Leyte II',
    'bfo'   => 'CSC Field Office - Biliran',
    'wlso'  => 'CSC Satellite Office - Western Leyte',
    'slfo'  => 'CSC Field Office - Southern Leyte',
    'sfo'   => 'CSC Field Office - Samar',
    'esfo'  => 'CSC Field Office - Eastern Samar',
    'nsfo'  => 'CSC Field Office - Northern Samar',
    'ro6'   => 'CSC Regional Office VI',
    'ro7'   => 'CSC Regional Office VII',
];
$locationText = isset($locationMap[$location]) ? $locationMap[$location] : 'CSC RO VIII';

try {
    $stmt = $conn->prepare("
        SELECT ot.*, ot.type AS training_type
        FROM otrs_trainings ot
        WHERE ot.id = :id
        LIMIT 1
    ");
    $stmt->bindParam(':id', $trainingId);
    $stmt->execute();
    $training = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$training) die('Invalid training ID or Training not found.');

    /* Proof-of-payment upload count: uploaded but NOT yet marked Paid/Free */
    $popSql = "
        SELECT COUNT(*)
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id         = :tid
          AND  r.proof_file          IS NOT NULL
          AND  r.proof_file          != ''
          AND  r.payment_status      = 'pending'
          AND  r.registration_status NOT IN ('cancelled','not qualified')
    ";
    $popParams = [':tid' => (int)$trainingId];
    if (!empty($location)) {
        $popSql .= " AND UPPER(p.location) = UPPER(:loc)";
        $popParams[':loc'] = $location;
    }
    $stmtPop = $conn->prepare($popSql);
    $stmtPop->execute($popParams);
    $popCount = (int)$stmtPop->fetchColumn();

    /* ── Payment status counts (respects location filter) ── */
    $countsSql = "
        SELECT r.payment_status, r.registration_status
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
    ";
    $countsParams = [':tid' => (int)$trainingId];
    if (!empty($location)) {
        $countsSql .= " AND UPPER(p.location) = UPPER(:loc)";
        $countsParams[':loc'] = $location;
    }
    $stmtCounts = $conn->prepare($countsSql);
    $stmtCounts->execute($countsParams);
    $counts = ['paid' => 0, 'pending' => 0, 'promissory note' => 0, 'free' => 0, 'cancelled' => 0, 'not qualified' => 0];
    $payNorm = [
        'paid'            => 'paid',
        'pending'         => 'pending',
        'promissory note' => 'promissory note',
        'free'            => 'free',
        'cancelled'       => 'cancelled',
        'not qualified'   => 'not qualified',
    ];
    foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $reg = strtolower(trim($cr['registration_status'] ?? ''));
        $pay = strtolower(trim($cr['payment_status']     ?? ''));
        if ($reg === 'cancelled')      { $counts['cancelled']++; }
        elseif ($reg === 'not qualified') { $counts['not qualified']++; }
        elseif (isset($payNorm[$pay])) { $counts[$payNorm[$pay]]++; }
    }

    $displayDate = '';
    if (!empty($training['start_date']) && $training['start_date'] != '0000-00-00') {
        $start = new DateTime($training['start_date']);
        $end   = (!empty($training['end_date']) && $training['end_date'] != '0000-00-00') ? new DateTime($training['end_date']) : $start;
        if ($start == $end)                                $displayDate = $start->format('F j, Y');
        elseif ($start->format('Y') != $end->format('Y')) $displayDate = $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
        elseif ($start->format('m') != $end->format('m')) $displayDate = $start->format('M j') . ' - ' . $end->format('M j, Y');
        else                                               $displayDate = $start->format('F j') . '-' . $end->format('j, Y');
    }
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ── Evaluation Results data for the Evaluation Results tab ───────
   eval_days = last day per SME (same logic as fetch_attendance.php).
   Results are grouped so the tab can show a day-by-day filter.
   ─────────────────────────────────────────────────────────────────── */
function evalStars(float $avg, int $max = 5): string {
    $full  = (int)floor($avg);
    $half  = ($avg - $full) >= 0.5 ? 1 : 0;
    $empty = $max - $full - $half;
    $html  = str_repeat('<i class="bi bi-star-fill text-warning" style="font-size:.85rem;"></i>', $full);
    if ($half) $html .= '<i class="bi bi-star-half text-warning" style="font-size:.85rem;"></i>';
    $html .= str_repeat('<i class="bi bi-star text-secondary" style="font-size:.85rem;opacity:.35;"></i>', $empty);
    return $html;
}

$evalEvals      = [];
$evalSmes       = [];
$evalSmeRatings = [];
$evalAggregate  = [];
$evalReasons    = ['commitment' => [], 'confidence' => []];
$evalN          = 0;
$evalFullN      = 0;
$evalSmeOnlyN   = 0;
$evalDayLabels  = []; // day_number → label string for the filter dropdown

try {
    /* Compute eval day labels from SME assignments (same logic as fetch_attendance) */
    $stTraining = $conn->prepare("SELECT start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stTraining->execute([':id' => (int)$trainingId]);
    $tRow = $stTraining->fetch(PDO::FETCH_ASSOC);

    if ($tRow) {
        // Get total training days (custom dates if set, else range minus suspensions)
        $totalTrDays = count(getTrainingDateStrings($conn, (int)$trainingId));

        $stSmeEval = $conn->prepare("
            SELECT COALESCE(MAX(std.day_number), :total_days) AS eval_day
            FROM   otrs_training_smes ts
            LEFT JOIN otrs_sme_training_days std ON std.training_sme_id = ts.id
            WHERE  ts.training_id = :tid
            GROUP BY ts.id
        ");
        $stSmeEval->execute([':total_days' => $totalTrDays, ':tid' => (int)$trainingId]);
        foreach ($stSmeEval->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $evalDayLabels[(int)$d] = 'Day ' . (int)$d;
        }
        ksort($evalDayLabels);
    }

    $stE = $conn->prepare("
        SELECT e.*,
               CONCAT(p.lname, ', ', p.fname,
                      IF(p.mname <> '', CONCAT(' ', LEFT(p.mname,1), '.'), '')) AS participant_name,
               p.location AS p_location, p.agency_name
        FROM   otrs_evaluations e
        JOIN   otrs_participants p ON p.id = e.participant_id
        WHERE  e.training_id = :tid
        ORDER BY e.day_number ASC, e.submitted_at ASC
    ");
    $stE->execute([':tid' => (int)$trainingId]);
    $evalEvals = $stE->fetchAll(PDO::FETCH_ASSOC);
    $evalN = count($evalEvals);

    /* Tag facilitation-only (sme_only) rows — they use 0 as a sentinel for
       design/confidence/commitment fields. Aggregate scores must exclude these. */
    foreach ($evalEvals as &$ev) {
        $ev['is_sme_only'] = ((int)$ev['design_q1'] === 0 && (int)$ev['confidence_rating'] === 0);
    }
    unset($ev);
    $evalFullEvals = array_values(array_filter($evalEvals, fn($e) => !$e['is_sme_only']));
    $evalFullN     = count($evalFullEvals);
    $evalSmeOnlyN  = $evalN - $evalFullN;

    if ($evalFullN > 0) {
        $keys = [
            'confidence_rating','commitment_rating',
            'design_q1','design_q2','design_q3','design_q4',
            'materials_q1','materials_q2','materials_q3','materials_q4',
            'admin_q1','admin_q2','admin_q3','admin_q4',
        ];
        foreach ($keys as $k) {
            $evalAggregate[$k] = array_sum(array_column($evalFullEvals, $k)) / $evalFullN;
        }
        $evalAggregate['design_avg']    = ($evalAggregate['design_q1'] + $evalAggregate['design_q2'] + $evalAggregate['design_q3'] + $evalAggregate['design_q4']) / 4;
        $evalAggregate['materials_avg'] = ($evalAggregate['materials_q1'] + $evalAggregate['materials_q2'] + $evalAggregate['materials_q3'] + $evalAggregate['materials_q4']) / 4;
        $evalAggregate['admin_avg']     = ($evalAggregate['admin_q1'] + $evalAggregate['admin_q2'] + $evalAggregate['admin_q3'] + $evalAggregate['admin_q4']) / 4;

        $stSme = $conn->prepare("SELECT id, sme_name, designation FROM otrs_training_smes WHERE training_id = :tid ORDER BY sort_order");
        $stSme->execute([':tid' => (int)$trainingId]);
        $evalSmes = $stSme->fetchAll(PDO::FETCH_ASSOC);

        if ($evalSmes) {
            $stSR = $conn->prepare("
                SELECT sr.sme_id,
                       AVG(sr.q1_rating) AS avg_q1, AVG(sr.q2_rating) AS avg_q2,
                       AVG(sr.q3_rating) AS avg_q3, AVG(sr.q4_rating) AS avg_q4,
                       COUNT(*) AS rating_count
                FROM   otrs_evaluation_sme_ratings sr
                JOIN   otrs_evaluations e ON e.id = sr.evaluation_id
                WHERE  e.training_id = :tid
                GROUP BY sr.sme_id
            ");
            $stSR->execute([':tid' => (int)$trainingId]);
            foreach ($stSR->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $evalSmeRatings[(int)$r['sme_id']] = $r;
            }

            // Per-participant detail ratings per SME
            $stDet = $conn->prepare("
                SELECT sr.sme_id,
                       CONCAT(p.lname, ', ', p.fname,
                              IF(TRIM(p.mname) <> '', CONCAT(' ', LEFT(TRIM(p.mname),1), '.'), '')) AS participant_name,
                       p.agency_name,
                       p.location AS p_location,
                       e.day_number,
                       sr.q1_rating, sr.q2_rating, sr.q3_rating, sr.q4_rating,
                       sr.comments,
                       e.submitted_at
                FROM   otrs_evaluation_sme_ratings sr
                JOIN   otrs_evaluations e ON e.id = sr.evaluation_id
                JOIN   otrs_participants p ON p.id = e.participant_id
                WHERE  e.training_id = :tid
                ORDER BY sr.sme_id ASC, e.submitted_at ASC
            ");
            $stDet->execute([':tid' => (int)$trainingId]);
            $evalSmeDetailRatings = [];
            foreach ($stDet->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                $evalSmeDetailRatings[(int)$dr['sme_id']][] = $dr;
            }
        }

        /* Reasons are only stored on full evaluations (not sme_only rows) */
        $evalIds = array_column($evalEvals, 'id');
        $ph = implode(',', array_fill(0, count($evalIds), '?'));
        $stCom = $conn->prepare("SELECT reason_code, COUNT(*) AS cnt FROM otrs_evaluation_commitment_reasons WHERE evaluation_id IN ($ph) GROUP BY reason_code ORDER BY cnt DESC");
        $stCom->execute($evalIds);
        $evalReasons['commitment'] = $stCom->fetchAll(PDO::FETCH_ASSOC);

        $stCon = $conn->prepare("SELECT reason_code, COUNT(*) AS cnt FROM otrs_evaluation_confidence_reasons WHERE evaluation_id IN ($ph) GROUP BY reason_code ORDER BY cnt DESC");
        $stCon->execute($evalIds);
        $evalReasons['confidence'] = $stCon->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { /* non-fatal — tab will show empty state */ }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?php echo htmlspecialchars($training['name']); ?> — <?php echo $displayDate; ?></title>

    <link href="../assets/img/favicon.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.0.1/css/buttons.dataTables.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.min.css">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="assets/css/participants.css?v=10" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <!-- Tom-Select for SME assignment dropdown -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

    <style>
    /* ── SME / Attendance tab styles ─────────────────────────── */
    .sme-card {
      border: 1.5px solid #e0f2fe; border-radius: 10px;
      padding: 1rem 1.1rem; margin-bottom: .75rem;
      background: #f0f9ff;
      display: flex; align-items: flex-start; gap: .9rem;
    }
    .sme-card-avatar {
      width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #0891b2, #0369a1);
      color: #fff; font-weight: 700; font-size: .9rem;
      display: flex; align-items: center; justify-content: center;
    }
    .sme-card-name  { font-weight: 700; font-size: .88rem; color: #0f172a; }
    .sme-card-desig { font-size: .76rem; color: #64748b; }
    .day-badge {
      display: inline-flex; align-items: center;
      background: #dbeafe; color: #1d4ed8;
      font-size: .65rem; font-weight: 700; padding: .15rem .45rem;
      border-radius: 4px; margin-right: 3px; margin-bottom: 3px;
    }
    .att-table th, .att-table td {
      padding: .35rem .5rem; font-size: .78rem; vertical-align: middle;
      white-space: nowrap;
    }
    .att-status-present { color: #15803d; font-weight: 700; }
    .att-status-absent  { color: #b91c1c; font-weight: 700; }
    .att-status-excused { color: #b45309; font-weight: 700; }
    .att-btn-group .btn { font-size: .7rem; padding: .2rem .5rem; }
    .att-summary-bar {
      height: 6px; border-radius: 3px; background: #e2e8f0;
      overflow: hidden; min-width: 60px;
    }
    .att-summary-fill { height: 100%; background: #16a34a; border-radius: 3px; transition: width .3s; }

    /* ── Registration & Payment Log Timeline ──────────────────── */
    .btn-pay-log {
        background: linear-gradient(135deg, #0f4c81, #1a6baa);
        color: #fff;
        border: none;
        padding: 3px 9px;
        border-radius: 6px;
        font-size: .72rem;
        font-weight: 600;
        white-space: nowrap;
        cursor: pointer;
        transition: opacity .15s;   
    }
    .btn-pay-log:hover { opacity: .82; color: #fff; }

    .pay-log-timeline { padding: 0; margin: 0; }
    .plt-item {
        display: flex;
        gap: 14px;
        padding-bottom: 22px;
        position: relative;
    }
    .plt-item:not(:last-child)::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 34px;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, #cbd5e1, transparent);
    }
    .plt-dot {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .85rem;
        z-index: 1;
        box-shadow: 0 1px 3px rgba(0,0,0,.12);
    }
    .plt-dot.reg         { background: #dbeafe; color: #1d4ed8; }
    .plt-dot.paid        { background: #dcfce7; color: #15803d; }
    .plt-dot.pending     { background: #fef9c3; color: #92400e; }
    .plt-dot.cancel      { background: #fee2e2; color: #991b1b; }
    .plt-dot.pn          { background: #e0f2fe; color: #0369a1; }
    .plt-dot.free        { background: #dbeafe; color: #1e40af; }
    .plt-dot.other       { background: #f1f5f9; color: #64748b; }
    .plt-dot.cancel-req  { background: #fff7ed; color: #c2410c; }
    .plt-dot.replace     { background: #f3e8ff; color: #7c3aed; }
    .plt-body  { flex: 1; min-width: 0; }
    .plt-title {
        font-size: .83rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
        line-height: 1.3;
    }
    .plt-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 12px;
        font-size: .74rem;
        color: #64748b;
        margin-top: 3px;
    }
    .plt-meta-item { display: flex; align-items: center; gap: 4px; }
    .plt-badge {
        display: inline-flex;
        align-items: center;
        padding: 1px 7px;
        border-radius: 4px;
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .02em;
        line-height: 1.6;
    }
    .plt-badge.paid    { background: #dcfce7; color: #15803d; }
    .plt-badge.pending { background: #fef9c3; color: #92400e; }
    .plt-badge.cancel  { background: #fee2e2; color: #991b1b; }
    .plt-badge.pn      { background: #e0f2fe; color: #0369a1; }
    .plt-badge.free    { background: #dbeafe; color: #1e40af; }
    .plt-badge.nq      { background: #f1f5f9; color: #475569; }
    .plt-badge.other   { background: #f1f5f9; color: #475569; }
    .plt-no-logs {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px 14px;
        font-size: .81rem;
        color: #64748b;
    }

    /* ── Proof-upload counter badge ───────────────────────────── */
    .pop-btn-wrap {
        position: relative;
        display: inline-flex;
    }
    .pop-count-badge {
        position: absolute;
        top: -7px;
        right: -7px;
        min-width: 18px;
        height: 18px;
        padding: 0 4px;
        background: #e63946;
        color: #fff;
        border-radius: 9px;
        font-size: .67rem;
        font-weight: 700;
        line-height: 18px;
        text-align: center;
        white-space: nowrap;
        pointer-events: none;
        box-shadow: 0 1px 4px rgba(230,57,70,.45);
    }

    /* ── Attendance matrix table ────────────────────────────────── */
    .att-matrix-table { border-collapse: collapse; }
    .att-fixed-th     { background: #f8fafc; }
    .att-group-th     { background: #e0f2fe; color: #0369a1; font-size: .75rem; border-bottom: 2px solid #bae6fd; }
    .eval-group-th    { background: #f3e8ff; color: #7c3aed; font-size: .75rem; border-bottom: 2px solid #ddd6fe; }
    .att-day-th       { background: #f0f9ff; font-size: .72rem; font-weight: 600; }
    .eval-day-th      { background: #faf5ff; font-size: .72rem; font-weight: 600; color: #6d28d9; }
    .att-sme-chip     { font-size: .58rem; color: #7c3aed; font-weight: 500; line-height: 1.3; }
    .att-day-cell     { text-align: center; vertical-align: middle; padding: .25rem !important; }
    .att-eval-cell    { text-align: center; vertical-align: middle; padding: .3rem !important; }
    .att-cell-inner   { display: flex; flex-direction: column; align-items: center; gap: 3px; }
    .att-cell-btns    { display: none; flex-direction: row; gap: 2px; }
    .att-day-cell:hover .att-cell-btns { display: flex; }
    .att-mark-btn     { border: none; border-radius: 4px; padding: 1px 5px; font-size: .65rem; cursor: pointer; line-height: 1.4; }
    .att-mark-p       { background: #d1fae5; color: #065f46; }
    .att-mark-p:hover { background: #16a34a; color: #fff; }
    .att-mark-a       { background: #fee2e2; color: #991b1b; }
    .att-mark-a:hover { background: #dc2626; color: #fff; }
    .att-mark-e       { background: #fef9c3; color: #854d0e; }
    .att-mark-e:hover { background: #d97706; color: #fff; }
    .att-st-present   { color: #15803d; font-weight: 700; font-size: .72rem; }
    .att-st-absent    { color: #b91c1c; font-weight: 700; font-size: .72rem; }
    .att-st-excused   { color: #b45309; font-weight: 700; font-size: .72rem; }
    .att-st-unmarked  { color: #cbd5e1; font-size: .72rem; }
    .att-eval-badge   { display: inline-flex; align-items: center; gap: 3px; padding: .12em .55em; border-radius: 20px; font-size: .67rem; font-weight: 700; white-space: nowrap; }
    .att-eval-badge.done    { background: #d1fae5; color: #065f46; }
    .att-eval-badge.pending { background: #fef9c3; color: #92400e; }
    .att-eval-badge.na      { background: #f1f5f9; color: #94a3b8; }

    /* ── Evaluation Results tab ─────────────────────────────────── */
    .score-card {
        padding: .9rem 1.1rem;
        display: flex; flex-direction: column; gap: .15rem;
    }
    .score-card .sc-label { font-size: .67rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; }
    .score-card .sc-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
    .score-card .sc-sub   { font-size: .78rem; color: #94a3b8; font-weight: 400; }
    .score-card .sc-bar   { height: 5px; border-radius: 3px; margin-top: .3rem; background: #e2e8f0; }
    .score-card .sc-fill  { height: 100%; border-radius: 3px; transition: width .4s; }
    .eval-open-text       { max-width: 150px; white-space: normal; word-break: break-word; font-size: .73rem; line-height:1.4; }
    .eval-sme-avg         { color: #7c3aed; font-weight: 700; }

    /* ── Participant table section group headers ──────────────── */
    .th-group {
        font-size: .65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        padding: .3rem .5rem !important;
        white-space: nowrap;
    }
    .th-group-personal     { background: #3B82F6 !important; color: #fff !important; border-bottom: 2px solid #2563EB !important; }
    .th-group-professional { background: #1D4ED8 !important; color: #fff !important; border-bottom: 2px solid #1E40AF !important; }
    .th-group-agency       { background: #0369A1 !important; color: #fff !important; border-bottom: 2px solid #075985 !important; }
    .th-group-enrollment   { background: #1E3A8A !important; color: #fff !important; border-bottom: 2px solid #1e3272 !important; }
    .th-group-filler       { background: #F1F5F9 !important; border-bottom: 2px solid #CBD5E1 !important; }
    #updateModal input[type="text"],
    #updateModal textarea { text-transform: uppercase; }

    /* ── Eval Links card layout ─────────────────────────────── */
    .el-pax-card {
      border: 1.5px solid #e2e8f0; border-radius: 10px;
      background: #fff; margin-bottom: .65rem; overflow: hidden;
      transition: box-shadow .15s;
    }
    .el-pax-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.07); }
    .el-card-header {
      display: flex; align-items: center; gap: .7rem;
      padding: .65rem .9rem; background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }
    .el-card-avatar {
      width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg,#1e3a5f,#0f6cbf);
      color: #fff; font-weight: 700; font-size: .76rem;
      display: flex; align-items: center; justify-content: center;
      user-select: none;
    }
    .el-card-name     { font-weight: 700; font-size: .82rem; color: #0f172a; }
    .el-card-email-txt{ font-size: .71rem; color: #64748b; }
    .el-card-badges   { margin-left: auto; display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
    .el-badge-complied{ background: #d1fae5; color: #065f46; font-size: .6rem; font-weight: 700; padding: .1em .45em; border-radius: 10px; white-space: nowrap; }
    .el-badge-paid    { background: #d1fae5; color: #065f46; font-size: .6rem; font-weight: 700; padding: .1em .45em; border-radius: 10px; }
    .el-badge-pn      { background: #fef9c3; color: #78350f; font-size: .6rem; font-weight: 700; padding: .1em .45em; border-radius: 10px; }
    .el-card-body     { display: flex; flex-wrap: wrap; gap: .65rem; padding: .6rem .9rem; }
    .el-links-section, .el-email-section { flex: 1 1 200px; }
    .el-section-label {
      font-size: .62rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .8px; color: #94a3b8; margin-bottom: .4rem;
    }
    .el-day-row  { display: flex; align-items: center; gap: .35rem; margin-bottom: .28rem; flex-wrap: wrap; }
    .el-day-label{ font-size: .7rem; color: #475569; font-weight: 600; min-width: 36px; }
    .el-copy-btn {
      font-size: .67rem; padding: .2rem .5rem; border: none; border-radius: 5px;
      cursor: pointer; display: inline-flex; align-items: center; gap: .28rem;
      background: #0f6cbf; color: #fff; transition: background .15s; white-space: nowrap;
    }
    .el-copy-btn:hover:not([disabled]) { background: #0a4e8a; }
    .el-copy-btn.copied    { background: #16a34a !important; }
    .el-copy-btn.el-submitted { background: #e2e8f0 !important; color: #94a3b8; cursor: not-allowed; }
    .el-submit-badge  { background: #d1fae5; color: #065f46; padding: .08em .4em; border-radius: 10px; font-size: .6rem; font-weight: 700; white-space: nowrap; }
    .el-pending-badge { background: #fef9c3; color: #854d0e; padding: .08em .4em; border-radius: 10px; font-size: .6rem; font-weight: 700; white-space: nowrap; }
    .el-send-day-btn, .el-send-all-btn {
      font-size: .67rem; padding: .2rem .5rem; border-radius: 5px;
      cursor: pointer; display: inline-flex; align-items: center; gap: .28rem;
      white-space: nowrap; transition: all .15s;
    }
    .el-send-day-btn { border: none; background: #059669; color: #fff; }
    .el-send-day-btn:hover { background: #047857; }
    .el-send-all-btn { border: 1.5px solid #059669; background: transparent; color: #059669; }
    .el-send-all-btn:hover { background: #059669; color: #fff; }
    .el-inline-form {
      margin-top: .42rem; padding: .5rem .65rem;
      background: #f0fdf4; border: 1px solid #bbf7d0;
      border-radius: 7px; display: none;
    }
    .el-inline-form.visible { display: block; }
    .el-inline-form-label{ font-size: .67rem; color: #065f46; font-weight: 600; margin-bottom: .28rem; }
    .el-inline-input {
      font-size: .77rem; padding: .26rem .5rem;
      border: 1px solid #86efac; border-radius: 5px; width: 100%; margin-bottom: .35rem;
      outline: none; transition: border-color .15s;
    }
    .el-inline-input:focus { border-color: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,.15); }
    .el-inline-input.is-invalid { border-color: #ef4444; }
    .el-inline-actions { display: flex; gap: .35rem; align-items: center; flex-wrap: wrap; }
    .el-inline-send-btn {
      font-size: .67rem; padding: .2rem .6rem; border: none; border-radius: 5px;
      background: #059669; color: #fff; cursor: pointer; font-weight: 600;
    }
    .el-inline-cancel-btn {
      font-size: .67rem; padding: .2rem .5rem;
      border: 1px solid #cbd5e1; border-radius: 5px;
      background: #fff; color: #475569; cursor: pointer;
    }
    .el-inline-result { margin-top: .35rem; font-size: .72rem; }
    .el-inline-result.el-ok  { color: #065f46; }
    .el-inline-result.el-err { color: #b91c1c; }
    @media (max-width:576px) {
      .el-card-body { flex-direction: column; }
      .el-links-section, .el-email-section { flex-basis: auto; }
    }
    </style>
</head>

<body>

    <?php imis_include('header') ?>
    <?php include 'inc/sidebar.php' ?>

    <main id="main" class="main">

        <!-- ── PAGE TITLE ──────────────────────────────────────────── -->
        <div class="pagetitle otrs-ph">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="otrs-ph-icon icon-blue"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <h1>List of Participants</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="index?location=<?php echo htmlspecialchars($location); ?>">Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="index_trainings?location=<?php echo htmlspecialchars($location); ?>">Training List</a>
                                </li>
                                <li class="breadcrumb-item"
                                    style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php echo htmlspecialchars($training['name']); ?>
                                </li>
                                <li class="breadcrumb-item active"><?php echo $displayDate; ?></li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <a href="index_trainings?location=<?php echo htmlspecialchars($location); ?>" class="otrs-btn-back">
                    <i class="bi bi-arrow-left-circle"></i> Go Back
                </a>
            </div>
        </div>
        <!-- ── END PAGE TITLE ──────────────────────────────────────── -->

        <section class="section">
            <div class="card">
                <div class="card-body p-4">

                    <!-- ── TRAINING INFO STRIP ── -->
                    <div class="training-strip">
                        <div class="strip-label">Training Details</div>
                        <div class="strip-title"><?php echo htmlspecialchars($training['name']); ?></div>
                        <div class="strip-meta">
                            <span class="strip-meta-item"><i class="bi bi-calendar3"></i> <?php echo $displayDate; ?></span>
                            <span class="strip-meta-item"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($training['location'] ?? '—'); ?></span>
                            <span class="strip-meta-item"><i class="bi bi-display"></i> <?php echo htmlspecialchars($training['mode']); ?></span>
                            <span class="strip-meta-item"><i class="bi bi-people"></i> <?php echo htmlspecialchars($training['slots']); ?> slots</span>
                            <span class="strip-meta-item"><i class="bi bi-cash"></i> ₱<?php echo htmlspecialchars($training['fee']); ?></span>
                            <span class="strip-meta-item"><i class="bi bi-building"></i> <?php echo htmlspecialchars($locationText); ?></span>
                        </div>
                    </div>

                    <!-- ── TOOLBAR 1 — Email, Proof of Payment, Filter ── -->
                    <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions &amp; Filters</p>

                    <div class="toolbar">
                        <div id="emailActions" class="d-flex align-items-center gap-2 flex-wrap">
                            <button id="sendEmailAck" class="btn-f btn-f-primary">
                                <i class="ri ri-mail-send-line"></i> Ack Letter
                            </button>
                            <button id="sendEmailZoom" class="btn-f btn-f-primary">
                                <i class="ri ri-mail-send-line"></i> Zoom Link
                            </button>
                            <button id="sendEmailAdvisory" class="btn-f btn-f-primary">
                                <i class="ri ri-mail-send-line"></i> Advisory
                            </button>
                        </div>

                        <div class="toolbar-divider"></div>

                        <span class="pop-btn-wrap">
                            <a href="proof_payment.php?id=<?php echo urlencode($_GET['id'] ?? ''); ?>"
                                class="btn-f"
                                style="background: #0077b6;"
                                title="Proof of Payment — <?php echo $popCount; ?> uploaded (pending review)">
                                <i class="bi bi-cash"></i> Proof of Payment
                            </a>
                            <?php if ($popCount > 0): ?>
                                <span class="pop-count-badge"><?php echo $popCount; ?></span>
                            <?php endif; ?>
                        </span>

                        <div class="toolbar-spacer"></div>

                        <button id="toggleColumns" class="btn-f btn-f-secondary" title="Toggle extended columns">
                            <i class="bi bi-layout-three-columns"></i> <span id="toggleColumnsLabel">Show All</span>
                        </button>
                        <span id="colToggleHint">Core view</span>

                        <div class="toolbar-divider"></div>

                        <div class="d-flex align-items-center gap-2">
                            <label for="filter" class="fw-bold mb-0"
                                style="font-size:.78rem;color:#012970;white-space:nowrap;">
                                <i class="bi bi-funnel me-1 text-primary"></i>Filter:
                            </label>
                            <select id="filter" class="filter-select">
                                <?php
                                $options = ['All', 'paid', 'pending', 'promissory note', 'free', 'cancelled', 'not qualified'];
                                foreach ($options as $opt) {
                                    $sel = ($filter === $opt) ? 'selected' : '';
                                    echo "<option value='$opt' $sel>$opt</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <!-- ── TOOLBAR 2 — Export, Bulk Update, Transfer ── -->
                    <div class="toolbar">
                        <button id="exportButton" class="btn-f btn-f-success">
                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                        </button>
                        <button class="btn-f btn-f-success"
                            data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                            <i class="bi bi-check-circle"></i> Update Status
                        </button>
                        <div class="toolbar-divider"></div>
                        <button id="transferTraining" class="btn-f btn-f-danger">
                            <i class="bi bi-clipboard-check"></i> Transfer
                        </button>
                        <button id="batchCancelBtn" class="btn-f btn-f-warning">
                            <i class="bi bi-x-circle"></i> Batch Cancellation
                        </button>

                        <?php
                        $trainingType = strtolower($training['training_type'] ?? '');
                        $allowedTypes = ['free', 'public-offered', 'congress-conducted'];
                        $canGenerateCertificates = ((float)$training['fee'] == 0.0) || in_array($trainingType, $allowedTypes);
                        if ($canGenerateCertificates):
                        ?>
                        <div class="toolbar-divider"></div>
                        <a href="generate_certificates.php?id=<?php echo (int)$trainingId; ?>"
                            class="btn-f"
                            style="background: linear-gradient(135deg, #5c0099, #7b00cc);"
                            title="Generate & Print Certificates for this Training">
                            <i class="bi bi-patch-check-fill"></i> Certificates
                        </a>
                        <?php endif; ?>

                        <div class="toolbar-divider"></div>
                        <button id="analyticsBtn"
                                class="btn-f"
                                style="background: linear-gradient(135deg,#0f4c81,#1a6baa);"
                                title="Generate Training Breakdown Analytics Report">
                            <i class="bi bi-bar-chart-line-fill"></i> Analytics
                        </button>

                        <div class="toolbar-divider"></div>
                        <button id="evalLinksBtn"
                                class="btn-f"
                                style="background: linear-gradient(135deg,#5b21b6,#7c3aed);"
                                title="Generate Evaluation Form Links for Participants">
                            <i class="bi bi-link-45deg"></i> Eval Links
                        </button>
                    </div>

                    <!-- ── SUMMARY PILLS ── -->
                    <div class="summary-pills">
                        <span class="summary-pill pill-paid">
                            <i class="bi bi-check-circle-fill"></i> Paid: <span id="pillCount-paid"><?php echo $counts['paid']; ?></span>
                        </span>
                        <span class="summary-pill pill-pending">
                            <i class="bi bi-hourglass-split"></i> Pending: <span id="pillCount-pending"><?php echo $counts['pending']; ?></span>
                        </span>
                        <span class="summary-pill pill-pn">
                            <i class="bi bi-journal-text"></i> Promissory Note: <span id="pillCount-pn"><?php echo $counts['promissory note']; ?></span>
                        </span>
                        <?php if (strtolower($training['training_type'] ?? '') === 'free'): ?>
                        <span class="summary-pill pill-free">
                            <i class="bi bi-gift"></i> Free: <span id="pillCount-free"><?php echo $counts['free']; ?></span>
                        </span>
                        <?php endif; ?>
                        <span class="summary-pill pill-cancelled">
                            <i class="bi bi-x-circle-fill"></i> Cancelled: <span id="pillCount-cancelled"><?php echo $counts['cancelled']; ?></span>
                        </span>
                        <span class="summary-pill pill-nq">
                            <i class="bi bi-slash-circle"></i> Not Qualified: <span id="pillCount-nq"><?php echo $counts['not qualified']; ?></span>
                        </span>
                        <span class="summary-pill pill-total">
                            <i class="bi bi-people"></i> Total: <span id="pillCount-total"><?php echo array_sum($counts); ?></span>
                        </span>
                    </div>

                    <!-- ── SECTION TABS (Participants / SMEs / Attendance) ── -->
                    <ul class="nav nav-tabs mt-3 mb-0" id="trainingTabs" role="tablist" style="border-bottom:2px solid #e2e8f0;">
                      <li class="nav-item">
                        <button class="nav-link active" id="tab-participants-btn" data-bs-toggle="tab"
                                data-bs-target="#tab-participants" type="button" role="tab">
                          <i class="bi bi-people me-1"></i> Participants
                        </button>
                      </li>
                      <li class="nav-item">
                        <button class="nav-link" id="tab-smes-btn" data-bs-toggle="tab"
                                data-bs-target="#tab-smes" type="button" role="tab">
                          <i class="bi bi-person-video3 me-1" style="color:#0891b2;"></i> SMEs
                          <span class="badge bg-secondary ms-1" id="smeBadge" style="font-size:.62rem;">0</span>
                        </button>
                      </li>
                      <li class="nav-item">
                        <button class="nav-link" id="tab-attendance-btn" data-bs-toggle="tab"
                                data-bs-target="#tab-attendance" type="button" role="tab"
                                onclick="loadAttendanceTab()">
                          <i class="bi bi-clipboard-check me-1" style="color:#059669;"></i> Attendance
                        </button>
                      </li>
                      <li class="nav-item">
                        <button class="nav-link" id="tab-eval-btn" data-bs-toggle="tab"
                                data-bs-target="#tab-eval" type="button" role="tab">
                          <i class="bi bi-bar-chart-line-fill me-1" style="color:#7c3aed;"></i> Evaluation Results
                          <?php if ($evalN > 0): ?>
                          <span class="badge ms-1" style="background:#7c3aed;font-size:.62rem;"><?php echo $evalN; ?></span>
                          <?php endif; ?>
                        </button>
                      </li>
                      <li class="nav-item">
                        <button class="nav-link" id="tab-waitlist-btn" data-bs-toggle="tab"
                                data-bs-target="#tab-waitlist" type="button" role="tab"
                                onclick="loadWaitlistTab()">
                          <i class="bi bi-hourglass-split me-1" style="color:#d97706;"></i> Waitlist
                          <span class="badge ms-1" id="waitlistBadge" style="background:#d97706;font-size:.62rem;display:none;">0</span>
                        </button>
                      </li>
                    </ul>

                    <div class="tab-content pt-3" id="trainingTabContent">

                      <!-- ── TAB: PARTICIPANTS ──────────────────────────────── -->
                      <div class="tab-pane fade show active" id="tab-participants" role="tabpanel">

                        <p class="section-label mt-0"><i class="bi bi-table me-1"></i> Participant Records</p>

                        <div id="tableContainer" class="table-container">
                            <div class="loading-overlay d-none">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table id="participantsList"
                                    class="table table-bordered table-hover table-striped w-100"
                                    style="font-size:12px;">
                                    <thead>
                                        <tr>
                                            <th class="text-center align-middle" style="width:36px;">
                                                <input type="checkbox" id="checkAll" style="transform:scale(1.5);">
                                            </th>
                                            <th class="text-center align-middle">Action</th>
                                            <th class="text-center align-middle">#</th>
                                            <th class="text-center align-middle">Name</th>
                                            <th class="text-center align-middle">Gender</th>
                                            <th class="text-center align-middle">Age</th>
                                            <th class="text-center align-middle">Civil Status</th>
                                            <th class="text-center align-middle">Email</th>
                                            <th class="text-center align-middle">Mobile</th>
                                            <th class="text-center align-middle">Position</th>
                                            <th class="text-center align-middle">Salary Grade</th>
                                            <th class="text-center align-middle">Employment Status</th>
                                            <th class="text-center align-middle">Pos. Level</th>
                                            <th class="text-center align-middle">FO</th>
                                            <th class="text-center align-middle">Home Address</th>
                                            <th class="text-center align-middle">Agency</th>
                                            <th class="text-center align-middle">Sector</th>
                                            <th class="text-center align-middle">Agency Address</th>
                                            <th class="text-center align-middle">Status</th>
                                            <th class="text-center align-middle">Certificate</th>
                                            <th class="text-center align-middle" title="A = Acknowledgment · D = Advisory<?php echo ($training['mode'] !== 'Face to Face') ? ' · Z = Zoom Link' : ''; ?>">Emails</th>
                                            <th class="text-center align-middle">OR No.</th>
                                            <th class="text-center align-middle">OR Date</th>
                                            <th class="text-center align-middle">Collecting Officer</th>
                                            <?php if ($training['mode'] === 'Face to Face') echo '<th class="text-center align-middle">Food Restrictions</th>'; ?>
                                            <th class="text-center align-middle">Remarks</th>
                                            <th class="text-center align-middle">Log</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>

                      </div><!-- /tab-participants -->

                      <!-- ── TAB: SMEs (Subject Matter Experts) ────────────── -->
                      <div class="tab-pane fade" id="tab-smes" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                          <p class="section-label mb-0"><i class="bi bi-person-video3 me-1" style="color:#0891b2;"></i> Assigned Subject Matter Experts (SMEs)</p>
                          <button class="btn btn-sm btn-primary d-flex align-items-center gap-1" id="btnAddTrainingSme">
                            <i class="bi bi-plus-lg"></i> Assign SME
                          </button>
                        </div>

                        <div id="smeListContainer">
                          <div class="text-center py-4 text-muted" id="smeLoadingMsg">
                            <div class="spinner-border spinner-border-sm me-2"></div> Loading SMEs…
                          </div>
                          <div id="smeListCards"></div>
                        </div>

                        <div class="alert alert-info d-flex gap-2 mt-3" style="font-size:.81rem;">
                          <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
                          <span>SMEs with <strong>no day assignments</strong> appear in the evaluation form for <strong>all training days</strong>.
                                Assign specific days to filter evaluation forms by attendance.</span>
                        </div>
                      </div><!-- /tab-smes -->

                      <!-- ── TAB: ATTENDANCE ────────────────────────────────── -->
                      <div class="tab-pane fade" id="tab-attendance" role="tabpanel">
                        <div id="attendanceTabBody">
                          <div class="text-center py-5 text-muted" id="attendanceLoadingMsg">
                            <div class="spinner-border text-success" role="status"></div>
                            <p class="mt-2">Loading attendance sheet…</p>
                          </div>
                        </div>
                      </div><!-- /tab-attendance -->

                      <!-- ── TAB: EVALUATION RESULTS ───────────────────────── -->
                      <div class="tab-pane fade" id="tab-eval" role="tabpanel">

                        <?php if ($evalN === 0): ?>
                        <div class="otrs-alert otrs-alert-info mt-2">
                          <i class="bi bi-info-circle-fill"></i>
                          No evaluations submitted yet for this training.
                        </div>

                        <?php elseif ($evalFullN === 0): ?>
                        <div class="otrs-alert otrs-alert-info mt-2">
                          <i class="bi bi-info-circle-fill"></i>
                          <?php echo $evalSmeOnlyN; ?> facilitation-only evaluation<?php echo $evalSmeOnlyN !== 1 ? 's' : ''; ?> submitted.
                          General program scores will be available once participants complete the full evaluation.
                        </div>

                        <?php else: ?>

                        <!-- Day filter -->
                        <?php if (count($evalDayLabels) > 1): ?>
                        <div class="d-flex align-items-center gap-2 mt-2 mb-3 flex-wrap">
                          <span style="font-size:.78rem;font-weight:600;color:#475569;">Filter by Eval Day:</span>
                          <div class="btn-group btn-group-sm" id="evalDayFilter">
                            <button class="btn btn-outline-secondary active" data-day="0" style="font-size:.75rem;">All Days</button>
                            <?php foreach ($evalDayLabels as $dn => $dlabel): ?>
                            <button class="btn btn-outline-secondary" data-day="<?php echo $dn; ?>" style="font-size:.75rem;"><?php echo htmlspecialchars($dlabel); ?></button>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <?php endif; ?>

                        <!-- Score summary cards -->
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 mt-2">
                          <div class="otrs-section-label mb-0">
                            <i class="bi bi-clipboard-data"></i> Overall Scores
                            <span class="otrs-count-badge" id="evalResponseCount">
                              <?php echo $evalFullN; ?> response<?php echo $evalFullN !== 1 ? 's' : ''; ?>
                              <?php if ($evalSmeOnlyN > 0): ?>
                              <span style="background:#fef3c7;color:#92400e;border-radius:10px;padding:.08em .45em;font-size:.7em;font-weight:600;margin-left:.3em;">+<?php echo $evalSmeOnlyN; ?> facilitation-only</span>
                              <?php endif; ?>
                            </span>
                          </div>
                          <div class="d-flex align-items-center gap-2 flex-wrap">
                            <a href="api/export_tabulation.php?training_id=<?php echo (int)$trainingId; ?>"
                               class="btn btn-sm btn-success d-flex align-items-center gap-1"
                               style="font-size:.75rem;" title="Download L1/L2 Tabulation Matrix (XLSX)">
                              <i class="bi bi-file-earmark-spreadsheet"></i> Tabulation Matrix
                            </a>
                            <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=overall"
                               class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                               style="font-size:.75rem;" title="Export Overall Scores to Excel/CSV">
                              <i class="bi bi-file-earmark-excel"></i> Export CSV
                            </a>
                          </div>
                        </div>
                        <div class="row g-2 mb-3">
                          <?php
                          $evalCards = [
                            ['label' => 'Confidence',     'value' => $evalAggregate['confidence_rating'], 'max' => 5, 'color' => '#0ea5e9'],
                            ['label' => 'Commitment',     'value' => $evalAggregate['commitment_rating'], 'max' => 5, 'color' => '#10b981'],
                            ['label' => 'Program Design', 'value' => $evalAggregate['design_avg'],        'max' => 5, 'color' => '#8b5cf6'],
                            ['label' => 'Materials',      'value' => $evalAggregate['materials_avg'],     'max' => 5, 'color' => '#f59e0b'],
                            ['label' => 'Administration', 'value' => $evalAggregate['admin_avg'],         'max' => 5, 'color' => '#ec4899'],
                          ];
                          foreach ($evalCards as $ec):
                            $pct = round($ec['value'] / $ec['max'] * 100);
                          ?>
                          <div class="col-sm-6 col-lg">
                            <div class="otrs-card h-100">
                              <div class="score-card">
                                <div class="sc-label"><?php echo $ec['label']; ?></div>
                                <div class="sc-value" style="color:<?php echo $ec['color']; ?>;">
                                  <?php echo number_format($ec['value'], 2); ?>
                                  <span class="sc-sub">/ <?php echo $ec['max']; ?></span>
                                </div>
                                <div><?php echo evalStars($ec['value']); ?></div>
                                <div class="sc-bar">
                                  <div class="sc-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $ec['color']; ?>;"></div>
                                </div>
                              </div>
                            </div>
                          </div>
                          <?php endforeach; ?>
                        </div>

                        <!-- SME / Facilitator ratings -->
                        <?php if ($evalSmes): ?>
                        <div class="otrs-card mb-3">
                          <div class="otrs-card-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                              <div class="otrs-ph-icon icon-purple" style="width:28px;height:28px;font-size:.8rem;"><i class="bi bi-person-workspace"></i></div>
                              <span class="fw-semibold" style="font-size:.85rem;color:#7c3aed;">Facilitator / SME Ratings</span>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                              <small style="font-size:.7rem;color:#94a3b8;">
                                <i class="bi bi-chevron-down me-1"></i>Click a row to see per-participant ratings
                              </small>
                              <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=sme"
                                 class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                                 style="font-size:.75rem;" title="Export SME Ratings to Excel/CSV">
                                <i class="bi bi-file-earmark-excel"></i> Export
                              </a>
                            </div>
                          </div>
                          <div class="table-responsive">
                            <table class="table table-hover otrs-table mb-0">
                              <thead>
                                <tr>
                                  <th style="width:22px;"></th>
                                  <th>Facilitator</th>
                                  <th>Designation</th>
                                  <th class="text-center" title="My knowledge about the topic has improved because of the expertise of the SME.">Q1 — Knowledge</th>
                                  <th class="text-center" title="The SME encouraged my interaction and participation.">Q2 — Interaction</th>
                                  <th class="text-center" title="The style of the SME kept me engaged throughout the session.">Q3 — Engagement</th>
                                  <th class="text-center" title="The SME discussed the topics based on my learning pace.">Q4 — Learning Pace</th>
                                  <th class="text-center">Overall Avg</th>
                                  <th class="text-center">Responses</th>
                                </tr>
                              </thead>
                              <tbody>
                              <?php foreach ($evalSmes as $esme):
                                $esid      = (int)$esme['id'];
                                $sr        = $evalSmeRatings[$esid] ?? null;
                                $avg       = $sr ? round(($sr['avg_q1'] + $sr['avg_q2'] + $sr['avg_q3'] + $sr['avg_q4']) / 4, 2) : null;
                                $eDetails  = $evalSmeDetailRatings[$esid] ?? [];
                                $hasEDet   = !empty($eDetails);
                              ?>
                                <!-- Aggregate row -->
                                <tr class="eval-sme-agg-row<?php echo $hasEDet ? ' eval-sme-expandable' : ''; ?>"
                                    data-esme-id="<?php echo $esid; ?>"
                                    style="cursor:<?php echo $hasEDet ? 'pointer' : 'default'; ?>;">
                                  <td class="text-center" style="padding-right:0;">
                                    <?php if ($hasEDet): ?>
                                    <i class="bi bi-chevron-right eval-sme-toggle-icon" style="font-size:.7rem;color:#7c3aed;transition:transform .2s;"></i>
                                    <?php endif; ?>
                                  </td>
                                  <td class="fw-semibold"><?php echo htmlspecialchars($esme['sme_name']); ?></td>
                                  <td style="color:#94a3b8;"><?php echo htmlspecialchars($esme['designation'] ?: '—'); ?></td>
                                  <?php if ($sr): ?>
                                  <td class="text-center"><?php echo evalStars((float)$sr['avg_q1']); ?><br><small class="eval-sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q1'], 2); ?></small></td>
                                  <td class="text-center"><?php echo evalStars((float)$sr['avg_q2']); ?><br><small class="eval-sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q2'], 2); ?></small></td>
                                  <td class="text-center"><?php echo evalStars((float)$sr['avg_q3']); ?><br><small class="eval-sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q3'], 2); ?></small></td>
                                  <td class="text-center"><?php echo evalStars((float)$sr['avg_q4']); ?><br><small class="eval-sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q4'], 2); ?></small></td>
                                  <td class="text-center">
                                    <span style="font-size:1.05rem;font-weight:700;color:#7c3aed;"><?php echo number_format($avg, 2); ?></span><br>
                                    <small style="font-size:.65rem;color:#94a3b8;">/ 5.00</small>
                                  </td>
                                  <td class="text-center">
                                    <?php if ($hasEDet): ?>
                                    <span style="background:#f3e8ff;color:#6d28d9;padding:.12em .52em;border-radius:12px;font-size:.68rem;font-weight:700;"><?php echo (int)$sr['rating_count']; ?></span>
                                    <?php else: ?>
                                    <span style="color:#94a3b8;"><?php echo (int)$sr['rating_count']; ?></span>
                                    <?php endif; ?>
                                  </td>
                                  <?php else: ?>
                                  <td colspan="6" class="text-center" style="color:#cbd5e1;font-style:italic;">No ratings submitted</td>
                                  <?php endif; ?>
                                </tr>

                                <!-- Per-participant detail row (hidden by default) -->
                                <?php if ($hasEDet): ?>
                                <tr class="eval-sme-detail-row d-none" id="eval-sme-detail-<?php echo $esid; ?>">
                                  <td colspan="9" style="padding:0;border-top:none;">
                                    <div style="background:#faf7ff;border-top:2px solid #e9d5ff;padding:.8rem 1.1rem .95rem;">
                                      <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6d28d9;margin-bottom:.55rem;">
                                        <i class="bi bi-person-lines-fill me-1"></i>Individual Ratings — <?php echo htmlspecialchars($esme['sme_name']); ?>
                                      </div>
                                      <div style="overflow-x:auto;">
                                      <table class="table table-sm mb-0" style="font-size:.75rem;background:#fff;border-radius:8px;overflow:hidden;">
                                        <thead>
                                          <tr style="background:#f3e8ff;">
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;">#</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;min-width:140px;">Participant</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;">Agency</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;text-align:center;">Day</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;text-align:center;" title="My knowledge about the topic has improved because of the expertise of the SME.">Q1</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;text-align:center;" title="The SME encouraged my interaction and participation.">Q2</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;text-align:center;" title="The style of the SME kept me engaged throughout the session.">Q3</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;text-align:center;" title="The SME discussed the topics based on my learning pace.">Q4</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;text-align:center;">Avg</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;min-width:170px;">Comment</th>
                                            <th style="font-size:.64rem;font-weight:700;color:#6d28d9;white-space:nowrap;">Submitted</th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($eDetails as $edi => $edr):
                                          $epAvg = ($edr['q1_rating'] + $edr['q2_rating'] + $edr['q3_rating'] + $edr['q4_rating']) / 4;
                                          $epSub = $edr['submitted_at'] ? date('M j, Y', strtotime($edr['submitted_at'])) : '—';
                                        ?>
                                        <tr data-eval-day="<?php echo (int)$edr['day_number']; ?>" style="border-bottom:1px solid #f3e8ff;">
                                          <td style="color:#94a3b8;"><?php echo $edi + 1; ?></td>
                                          <td class="fw-semibold text-nowrap"><?php echo htmlspecialchars($edr['participant_name']); ?></td>
                                          <td style="color:#64748b;max-width:150px;word-break:break-word;"><?php echo htmlspecialchars($edr['agency_name'] ?: '—'); ?></td>
                                          <td class="text-center"><span style="background:#f3e8ff;color:#6d28d9;padding:.1em .4em;border-radius:8px;font-size:.64rem;font-weight:700;">Day <?php echo (int)$edr['day_number']; ?></span></td>
                                          <?php foreach (['q1_rating','q2_rating','q3_rating','q4_rating'] as $eqk):
                                            $eqv = (int)$edr[$eqk];
                                            $eqBg = $eqv >= 4 ? '#d1fae5' : ($eqv == 3 ? '#fef9c3' : '#fee2e2');
                                            $eqTx = $eqv >= 4 ? '#065f46' : ($eqv == 3 ? '#92400e' : '#991b1b');
                                          ?>
                                          <td class="text-center"><span style="background:<?php echo $eqBg; ?>;color:<?php echo $eqTx; ?>;padding:.15em .5em;border-radius:8px;font-size:.75rem;font-weight:700;"><?php echo $eqv; ?></span></td>
                                          <?php endforeach; ?>
                                          <td class="text-center"><span style="font-size:.8rem;font-weight:700;color:#7c3aed;"><?php echo number_format($epAvg, 2); ?></span></td>
                                          <td style="color:#64748b;white-space:normal;max-width:190px;"><?php echo htmlspecialchars($edr['comments'] ?: '—'); ?></td>
                                          <td style="color:#94a3b8;white-space:nowrap;font-size:.68rem;"><?php echo $epSub; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                      </table>
                                      </div>
                                    </div>
                                  </td>
                                </tr>
                                <?php endif; ?>

                              <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                          <div style="padding:.5rem 1rem;font-size:.68rem;color:#94a3b8;border-top:1px solid #f1f5f9;">
                            <strong>Scale:</strong> 5 = Strongly Agree · 4 = Agree · 3 = Neither · 2 = Disagree · 1 = Strongly Disagree
                          </div>
                        </div>
                        <?php endif; ?>

                        <!-- Confidence & Commitment reasons -->
                        <?php if ($evalReasons['confidence'] || $evalReasons['commitment']): ?>
                        <div class="row g-3 mb-3">
                          <?php foreach (['confidence' => 'Confidence Reasons', 'commitment' => 'Commitment Reasons'] as $rKey => $rLabel):
                            if (!$evalReasons[$rKey]) continue; ?>
                          <div class="col-md-6">
                            <div class="otrs-card h-100">
                              <div class="otrs-card-top d-flex align-items-center justify-content-between gap-2">
                                <span class="fw-semibold" style="font-size:.85rem;"><?php echo $rLabel; ?></span>
                                <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=reasons"
                                   class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                                   style="font-size:.72rem;" title="Export Commitment &amp; Confidence Reasons to Excel/CSV">
                                  <i class="bi bi-file-earmark-excel"></i> Export
                                </a>
                              </div>
                              <div class="otrs-card-body">
                                <?php foreach ($evalReasons[$rKey] as $r):
                                  $rpct = $evalFullN > 0 ? round($r['cnt'] / $evalFullN * 100) : 0;
                                ?>
                                <div class="mb-2">
                                  <div class="d-flex justify-content-between mb-1" style="font-size:.8rem;">
                                    <span><?php echo htmlspecialchars(str_replace('_', ' ', $r['reason_code'])); ?></span>
                                    <span style="color:#94a3b8;"><?php echo $r['cnt']; ?> (<?php echo $rpct; ?>%)</span>
                                  </div>
                                  <div class="progress" style="height:5px;border-radius:3px;">
                                    <div class="progress-bar" style="width:<?php echo $rpct; ?>%;background:#7c3aed;"></div>
                                  </div>
                                </div>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          </div>
                          <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Individual responses DataTable (full evaluations only) -->
                        <div class="otrs-card">
                          <div class="otrs-card-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                              <div class="otrs-ph-icon icon-purple" style="width:28px;height:28px;font-size:.8rem;"><i class="bi bi-table"></i></div>
                              <span class="fw-semibold" style="font-size:.85rem;">Individual Responses</span>
                              <span style="background:#f3e8ff;color:#6d28d9;padding:.1em .55em;border-radius:12px;font-size:.68rem;font-weight:700;"><?php echo $evalFullN; ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                              <?php if ($evalSmeOnlyN > 0): ?>
                              <small style="font-size:.7rem;color:#94a3b8;"><i class="bi bi-info-circle me-1"></i>Facilitation-only submissions are shown in the SME Ratings section above.</small>
                              <?php endif; ?>
                              <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=individual"
                                 class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                                 style="font-size:.75rem;" title="Export Individual Responses to Excel/CSV">
                                <i class="bi bi-file-earmark-excel"></i> Export
                              </a>
                            </div>
                          </div>
                          <div class="table-responsive">
                            <table id="evalTableP" class="table table-sm table-hover otrs-table mb-0" style="font-size:.75rem;">
                              <thead>
                                <tr>
                                  <th class="text-center" style="width:32px;">#</th>
                                  <th style="min-width:130px;">Participant</th>
                                  <th style="min-width:110px;">Agency</th>
                                  <th class="text-center" style="width:58px;">Day</th>
                                  <th class="text-center" style="width:46px;" title="Confidence Rating">Conf.</th>
                                  <th class="text-center" style="width:52px;" title="Commitment Rating">Commit.</th>
                                  <th class="text-center" style="width:58px;" title="Program Design Average">Design</th>
                                  <th class="text-center" style="width:70px;" title="Materials &amp; Methodologies Average">Materials</th>
                                  <th class="text-center" style="width:50px;" title="Administration Average">Admin</th>
                                  <th style="width:130px;">Design Comment</th>
                                  <th style="width:130px;">Materials Comment</th>
                                  <th style="width:130px;">Admin Comment</th>
                                  <th style="width:140px;">Liked Most</th>
                                  <th style="width:140px;">Improvements</th>
                                  <th style="width:140px;">Recommendations</th>
                                  <th style="width:140px;">Future Topics</th>
                                  <th class="text-center" style="width:80px;">Submitted</th>
                                </tr>
                              </thead>
                              <tbody>
                              <?php foreach ($evalFullEvals as $ei => $ev):
                                $designAvg = ($ev['design_q1'] + $ev['design_q2'] + $ev['design_q3'] + $ev['design_q4']) / 4;
                                $matsAvg   = ($ev['materials_q1'] + $ev['materials_q2'] + $ev['materials_q3'] + $ev['materials_q4']) / 4;
                                $adminAvg  = ($ev['admin_q1'] + $ev['admin_q2'] + $ev['admin_q3'] + $ev['admin_q4']) / 4;
                                $subAt     = $ev['submitted_at'] ? date('M j, Y', strtotime($ev['submitted_at'])) : '—';
                                $dn        = (int)($ev['day_number'] ?? 1);
                              ?>
                              <tr data-eval-day="<?php echo $dn; ?>">
                                <td class="text-center"><?php echo $ei + 1; ?></td>
                                <td class="text-nowrap" style="font-weight:600;"><?php echo htmlspecialchars($ev['participant_name']); ?></td>
                                <td style="word-break:break-word;"><?php echo htmlspecialchars($ev['agency_name'] ?: '—'); ?></td>
                                <td class="text-center">
                                  <span style="background:#f3e8ff;color:#6d28d9;padding:.12em .5em;border-radius:12px;font-size:.66rem;font-weight:700;white-space:nowrap;">Day <?php echo $dn; ?></span>
                                </td>
                                <td class="text-center">
                                  <span style="background:<?php echo $ev['confidence_rating'] >= 4 ? '#d1fae5' : ($ev['confidence_rating'] == 3 ? '#fef9c3' : '#fee2e2'); ?>;color:<?php echo $ev['confidence_rating'] >= 4 ? '#065f46' : ($ev['confidence_rating'] == 3 ? '#92400e' : '#991b1b'); ?>;padding:.12em .45em;border-radius:8px;font-size:.73rem;font-weight:700;"><?php echo $ev['confidence_rating']; ?></span>
                                </td>
                                <td class="text-center">
                                  <span style="background:<?php echo $ev['commitment_rating'] >= 4 ? '#d1fae5' : ($ev['commitment_rating'] == 3 ? '#fef9c3' : '#fee2e2'); ?>;color:<?php echo $ev['commitment_rating'] >= 4 ? '#065f46' : ($ev['commitment_rating'] == 3 ? '#92400e' : '#991b1b'); ?>;padding:.12em .45em;border-radius:8px;font-size:.73rem;font-weight:700;"><?php echo $ev['commitment_rating']; ?></span>
                                </td>
                                <td class="text-center">
                                  <span style="background:<?php echo $designAvg >= 4 ? '#d1fae5' : ($designAvg >= 3 ? '#fef9c3' : '#fee2e2'); ?>;color:<?php echo $designAvg >= 4 ? '#065f46' : ($designAvg >= 3 ? '#92400e' : '#991b1b'); ?>;padding:.12em .45em;border-radius:8px;font-size:.73rem;font-weight:700;"><?php echo number_format($designAvg, 1); ?></span>
                                </td>
                                <td class="text-center">
                                  <span style="background:<?php echo $matsAvg >= 4 ? '#d1fae5' : ($matsAvg >= 3 ? '#fef9c3' : '#fee2e2'); ?>;color:<?php echo $matsAvg >= 4 ? '#065f46' : ($matsAvg >= 3 ? '#92400e' : '#991b1b'); ?>;padding:.12em .45em;border-radius:8px;font-size:.73rem;font-weight:700;"><?php echo number_format($matsAvg, 1); ?></span>
                                </td>
                                <td class="text-center">
                                  <span style="background:<?php echo $adminAvg >= 4 ? '#d1fae5' : ($adminAvg >= 3 ? '#fef9c3' : '#fee2e2'); ?>;color:<?php echo $adminAvg >= 4 ? '#065f46' : ($adminAvg >= 3 ? '#92400e' : '#991b1b'); ?>;padding:.12em .45em;border-radius:8px;font-size:.73rem;font-weight:700;"><?php echo number_format($adminAvg, 1); ?></span>
                                </td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['design_comment'] ?: '—'); ?></td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['materials_comment'] ?: '—'); ?></td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['admin_comment'] ?: '—'); ?></td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['liked_most'] ?: '—'); ?></td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['improvements'] ?: '—'); ?></td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['recommendations'] ?: '—'); ?></td>
                                <td class="eval-open-text"><?php echo htmlspecialchars($ev['future_topics'] ?: '—'); ?></td>
                                <td class="text-center text-nowrap" style="color:#94a3b8;"><?php echo $subAt; ?></td>
                              </tr>
                              <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        </div>

                        <?php endif; /* $evalN === 0 / $evalFullN === 0 / else */ ?>
                      </div><!-- /tab-eval -->

                      <!-- ── TAB: WAITLIST ──────────────────────────────────── -->
                      <div class="tab-pane fade" id="tab-waitlist" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                          <p class="section-label mb-0"><i class="bi bi-hourglass-split me-1" style="color:#d97706;"></i> Training Waitlist</p>
                          <button class="btn btn-sm btn-outline-secondary" onclick="loadWaitlistTab(true)">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                          </button>
                        </div>
                        <div id="waitlistTabBody">
                          <div class="text-center py-5 text-muted">
                            <div class="spinner-border text-warning" role="status"></div>
                            <p class="mt-2">Loading waitlist…</p>
                          </div>
                        </div>
                      </div><!-- /tab-waitlist -->

                    </div><!-- /tab-content -->

                </div><!-- /.card-body -->
            </div><!-- /.card -->
        </section>

    </main>

    <?php imis_include('footer_db') ?>

    <!-- ══════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════════ -->

    <!-- Update Participant -->
    <div id="updateModal" class="modal fade" tabindex="-1" aria-labelledby="updateModalLabel" aria-modal="true" role="dialog">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">

          <!-- ── HEADER ─────────────────────────────────────────────── -->
          <div class="modal-header border-0">
            <div class="modal-title-wrap flex-grow-1">
              <div class="modal-avatar" id="modalAvatar">?</div>
              <div class="modal-title-text">
                <h6 id="fullName">Loading participant…</h6>
                <small id="modalEnrollmentMeta">Enrollment record</small>
              </div>
            </div>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <!-- ── TAB NAV ─────────────────────────────────────────────── -->
          <div class="modal-tabs" role="tablist">
            <button class="modal-tab-btn active" data-tab="tab-personal" type="button" role="tab" aria-selected="true">
              <i class="bi bi-person-fill tab-icon"></i> Personal
              <span class="tab-badge" id="badge-personal"></span>
            </button>
            <button class="modal-tab-btn" data-tab="tab-professional" type="button" role="tab">
              <i class="bi bi-briefcase-fill tab-icon"></i> Professional
              <span class="tab-badge" id="badge-professional"></span>
            </button>
            <button class="modal-tab-btn" data-tab="tab-agency" type="button" role="tab">
              <i class="bi bi-building tab-icon"></i> Agency
              <span class="tab-badge" id="badge-agency"></span>
            </button>
            <button class="modal-tab-btn" data-tab="tab-enrollment" type="button" role="tab">
              <i class="bi bi-clipboard2-check-fill tab-icon"></i> Enrollment
              <span class="tab-badge" id="badge-enrollment"></span>
            </button>
          </div>

          <!-- ── BODY ───────────────────────────────────────────────── -->
          <div class="modal-body">

            <!-- Loading skeleton -->
            <div id="modalSkeleton" class="skel-wrap">
              <div class="skel-block skel-section-label mb-3"></div>
              <div class="skel-row skel-row-3">
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
              </div>
              <div class="skel-row skel-row-2">
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
              </div>
              <div class="skel-block skel-section-label mt-4 mb-3"></div>
              <div class="skel-row skel-row-2">
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
              </div>
              <div class="skel-row skel-row-3">
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
              </div>
            </div>

            <!-- ===== FORM (hidden while loading) ===== -->
            <form id="updateForm" novalidate style="display:none;">
              <input type="hidden" id="participantId" name="participantId">
              <input type="hidden" id="enrollmentId"  name="regId">
              <input type="hidden" id="status"        name="status">

              <!-- ── TAB 1: PERSONAL ───────────────────────────────── -->
              <div class="tab-pane-content active" id="tab-personal">

                <div class="form-section-label">
                  <i class="bi bi-person-vcard"></i> Identity
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-sm-4">
                    <label class="form-label">First Name <span class="req">*</span></label>
                    <input type="text" class="form-control" id="fname" name="fname"
                           placeholder="e.g. Juan" autocomplete="given-name">
                    <div class="invalid-feedback">First name is required.</div>
                  </div>
                  <div class="col-sm-2">
                    <label class="form-label">M.I.</label>
                    <input type="text" class="form-control text-center" id="mname" name="mname"
                           maxlength="2" placeholder="A" style="letter-spacing:.1em;">
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label">Last Name <span class="req">*</span></label>
                    <input type="text" class="form-control" id="lname" name="lname"
                           placeholder="e.g. dela Cruz" autocomplete="family-name">
                    <div class="invalid-feedback">Last name is required.</div>
                  </div>
                  <div class="col-sm-2">
                    <label class="form-label">Suffix</label>
                    <input type="text" class="form-control" id="suffix" name="suffix"
                           placeholder="Jr., Sr., III" maxlength="16">
                  </div>
                </div>

                <div class="form-section-label mt-3">
                  <i class="bi bi-person-lines-fill"></i> Demographics
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-sm-3">
                    <label class="form-label">Age</label>
                    <input type="number" class="form-control" id="age" name="age"
                           min="18" max="99" placeholder="35" readonly tabindex="-1">
                    <div class="invalid-feedback">Enter a valid age (18–99).</div>
                  </div>
                  <div class="col-sm-3">
                    <label class="form-label">Gender <span class="req">*</span></label>
                    <select class="form-select" id="gender" name="gender">
                      <option value="">— Select —</option>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                      <option value="Prefer not to say">Prefer not to say</option>
                    </select>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label">Civil Status</label>
                    <select class="form-select" id="civilstatus" name="civilstatus">
                      <option value="">— Select —</option>
                      <option value="Single">Single</option>
                      <option value="Married">Married</option>
                      <option value="Widowed/Widower">Widowed / Widower</option>
                      <option value="Separated">Separated</option>
                    </select>
                  </div>
                  <div class="col-sm-2">
                    <label class="form-label">PWD?</label>
                    <select class="form-select" id="pwd" name="pwd">
                      <option value="No">No</option>
                      <option value="Yes">Yes</option>
                    </select>
                  </div>
                </div>

                <div class="form-section-label mt-3">
                  <i class="bi bi-envelope-at-fill"></i> Contact
                </div>
                <div class="row g-3">
                  <div class="col-sm-7">
                    <label class="form-label">Email Address <span class="req">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text" style="border-radius:7px 0 0 7px;font-size:.82rem;border:1.5px solid #dce4ef;border-right:none;background:#f7f9fc;">
                        <i class="bi bi-envelope"></i>
                      </span>
                      <input type="email" class="form-control" id="email" name="email"
                             placeholder="juandelacruz@agency.gov.ph"
                             style="border-radius:0 7px 7px 0;" autocomplete="email">
                    </div>
                    <div class="invalid-feedback">Enter a valid email address.</div>
                  </div>
                  <div class="col-sm-5">
                    <label class="form-label">Mobile No.</label>
                    <div class="input-group">
                      <span class="input-group-text" style="border-radius:7px 0 0 7px;font-size:.82rem;border:1.5px solid #dce4ef;border-right:none;background:#f7f9fc;">
                        +63
                      </span>
                      <input type="tel" class="form-control" id="contactno" name="contactno"
                             maxlength="11" placeholder="09XXXXXXXXX"
                             style="border-radius:0 7px 7px 0;" autocomplete="tel">
                    </div>
                    <div class="invalid-feedback">Enter an 11-digit PH mobile number.</div>
                  </div>
                </div>

              </div><!-- /tab-personal -->

              <!-- ── TAB 2: PROFESSIONAL ───────────────────────────── -->
              <div class="tab-pane-content" id="tab-professional">

                <div class="form-section-label">
                  <i class="bi bi-award-fill"></i> Position Details
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-sm-8">
                    <label class="form-label">Position Title <span class="req">*</span></label>
                    <input type="text" class="form-control" id="positiontitle" name="positiontitle"
                           placeholder="e.g. Administrative Officer II">
                    <div class="invalid-feedback">Position title is required.</div>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label">Salary Grade</label>
                    <input type="text" class="form-control" id="salary_grade" name="salary_grade"
                           placeholder="e.g. SG-11" maxlength="12">
                  </div>
                </div>
                <div class="row g-3">
                  <div class="col-sm-4">
                    <label class="form-label">Position Level</label>
                    <select class="form-select" id="position_level" name="position_level">
                      <option value="">— Select —</option>
                      <option value="First Level">First Level</option>
                      <option value="Second Level (Non-Supervisory)">Second Level (Rank-and-File)</option>
                      <option value="Second Level (Supervisory)">Second Level (Supervisory)</option>
                      <option value="Second Level (Executive/Managerial)">Second Level (Executive/Managerial)</option>
                      <option value="Third Level">Third Level</option>
                      <option value="N/A">N/A</option>
                    </select>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label">Employment Status</label>
                    <select class="form-select" id="employment_status" name="employment_status">
                      <option value="">— Select —</option>
                      <option value="Permanent">Permanent</option>
                      <option value="Casual">Casual</option>
                      <option value="Contractual">Contractual</option>
                      <option value="Co-terminous">Co-terminous</option>
                      <option value="Elective">Elective</option>
                      <option value="Temporary">Temporary</option>
                      <option value="Job Order/Contract of Service">Job Order/Contract of Service</option>
                      <option value="Presidential Appointee">Presidential Appointee</option>
                      <option value="Others">Others</option>
                    </select>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label">Field Office (FO)</label>
                    <select class="form-select" id="location" name="location">
                      <option value="HRD">HRD — Central</option>
                      <option value="LFOI">LFOI — Leyte I</option>
                      <option value="LFOII">LFOII — Leyte II</option>
                      <option value="BFO">BFO — Biliran</option>
                      <option value="WLSO">WLSO — Western Leyte</option>
                      <option value="SLFO">SLFO — Southern Leyte</option>
                      <option value="SFO">SFO — Samar</option>
                      <option value="ESFO">ESFO — Eastern Samar</option>
                      <option value="NSFO">NSFO — Northern Samar</option>
                      <option value="RO6">RO VI</option>
                      <option value="RO7">RO VII</option>
                    </select>
                  </div>
                </div>

              </div><!-- /tab-professional -->

              <!-- ── TAB 3: AGENCY ─────────────────────────────────── -->
              <div class="tab-pane-content" id="tab-agency">

                <div class="form-section-label">
                  <i class="bi bi-building"></i> Agency Information
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-12">
                    <label class="form-label">Agency / Office Name <span class="req">*</span></label>
                    <input type="text" class="form-control" id="agency_name" name="agency_name"
                           placeholder="e.g. Department of Education — Division of Tacloban City">
                    <div class="invalid-feedback">Agency name is required.</div>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Agency Address</label>
                    <input type="text" class="form-control" id="agency_address" name="agency_address"
                           placeholder="Street, Municipality, Province">
                  </div>
                </div>

                <div class="form-section-label mt-2">
                  <i class="bi bi-house-fill"></i> Residence
                </div>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">Home Address</label>
                    <input type="text" class="form-control" id="home_address" name="home_address"
                           placeholder="Barangay, Municipality, Province">
                  </div>
                </div>

              </div><!-- /tab-agency -->

              <!-- ── TAB 4: ENROLLMENT ─────────────────────────────── -->
              <div class="tab-pane-content" id="tab-enrollment">

                <div class="form-section-label">
                  <i class="bi bi-cash-coin"></i> Payment Status
                </div>

                <div id="statusAlert" class="status-alert mb-3 d-none">
                  <i class="bi bi-info-circle-fill"></i>
                  <span id="statusAlertText"></span>
                </div>

                <div class="payment-status-row mb-3">
                  <input type="radio" class="payment-opt" id="opt-pending" name="statusRadio" value="pending">
                  <label for="opt-pending" class="payment-opt-label pending">
                    <i class="bi bi-hourglass-split"></i> Pending
                  </label>
                  <input type="radio" class="payment-opt" id="opt-paid" name="statusRadio" value="paid">
                  <label for="opt-paid" class="payment-opt-label paid">
                    <i class="bi bi-check-circle-fill"></i> Paid
                  </label>
                  <input type="radio" class="payment-opt" id="opt-pn" name="statusRadio" value="promissory note">
                  <label for="opt-pn" class="payment-opt-label pn">
                    <i class="bi bi-journal-text"></i> Promissory Note
                  </label>
                  <input type="radio" class="payment-opt" id="opt-free" name="statusRadio" value="free">
                  <label for="opt-free" class="payment-opt-label free">
                    <i class="bi bi-gift"></i> Free
                  </label>
                  <input type="radio" class="payment-opt" id="opt-cancelled" name="statusRadio" value="cancelled">
                  <label for="opt-cancelled" class="payment-opt-label cancel">
                    <i class="bi bi-x-circle-fill"></i> Cancelled
                  </label>
                  <input type="radio" class="payment-opt" id="opt-nq" name="statusRadio" value="not qualified">
                  <label for="opt-nq" class="payment-opt-label nq">
                    <i class="bi bi-slash-circle"></i> Not Qualified
                  </label>
                </div>

                <div class="mt-3 hidden" id="primeHrmGroup">
                  <div class="p-2 rounded" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #f59e0b;">
                    <div class="form-check form-switch mb-0">
                      <input class="form-check-input" type="checkbox" role="switch"
                             id="prime_hrm_discount" name="prime_hrm_discount" value="1"
                             style="width:2em;height:1.1em;">
                      <label class="form-check-label fw-semibold" for="prime_hrm_discount" style="font-size:.84rem;">
                        <i class="bi bi-award-fill text-warning me-1"></i> PRIME-HRM 20% Discount Applied
                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;vertical-align:middle;">PRIME-HRM</span>
                      </label>
                    </div>
                    <div style="font-size:.71rem;color:#92400e;margin-top:3px;margin-left:2.5em;">
                      Agency is PRIME-HRM accredited and availed the 20% registration fee discount.
                    </div>
                  </div>
                </div>

                <div id="orFieldsGroup">
                  <div class="form-section-label mt-3">
                    <i class="bi bi-receipt"></i> Official Receipt Details
                  </div>
                  <div class="row g-3 mb-3">
                    <div class="col-sm-3">
                      <label class="form-label">OR Number <span class="req">*</span></label>
                      <input type="text" class="form-control" id="orno" name="orno"
                             placeholder="OR-XXXXXX" maxlength="12">
                      <div class="invalid-feedback">OR Number is required.</div>
                    </div>
                    <div class="col-sm-3">
                      <label class="form-label">OR Date <span class="req">*</span></label>
                      <input type="date" class="form-control" id="ordate" name="ordate">
                      <div class="invalid-feedback">OR Date is required.</div>
                    </div>
                    <div class="col-sm-6">
                      <label class="form-label">Collecting Officer</label>
                      <select class="form-select" id="collecting_officer" name="collecting_officer">
                        <option value="">— Select Officer —</option>
                      </select>
                      <div class="field-locked-hint">
                        <i class="bi bi-info-circle"></i>
                        Officers are filtered by the selected Field Office.
                      </div>
                    </div>
                  </div>
                </div><!-- /orFieldsGroup -->

                <div class="form-section-label mt-1">
                  <i class="bi bi-mortarboard-fill"></i> Training Completion
                </div>
                <div class="row g-3">
                  <div class="col-sm-5">
                    <label class="form-label">Training Status</label>
                    <select class="form-select" id="status_training" name="status_training">
                      <option value="completed">Completed</option>
                      <option value="not completed">Not Completed</option>
                      <option value="For Compliance">For Compliance</option>
                    </select>
                  </div>
                  <div class="col-sm-7">
                    <label class="form-label">Remarks <span id="remarksRequired" style="display:none;color:#dc3545;font-size:.75rem;font-weight:600;margin-left:.3rem;">* required</span></label>
                    <textarea class="form-control" id="remarks" name="remarks"
                              rows="2" placeholder="Optional notes…"></textarea>
                  </div>
                </div>

                <?php if ($training['mode'] === 'Face to Face'): ?>
                <div class="row g-3 mt-1" id="foodRestrictionsRow">
                  <div class="col-12">
                    <label class="form-label">Food Restrictions / Dietary Needs</label>
                    <input type="text" class="form-control" id="food_restrictions" name="food_restrictions"
                           placeholder="e.g. Vegetarian, Halal, Allergic to seafood…" maxlength="254">
                  </div>
                </div>
                <?php endif; ?>

              </div><!-- /tab-enrollment -->

            </form>

            <!-- Progress dots -->
            <div class="tab-progress" id="tabProgress" style="display:none;">
              <div class="tab-dot active" data-for="tab-personal"></div>
              <div class="tab-dot" data-for="tab-professional"></div>
              <div class="tab-dot" data-for="tab-agency"></div>
              <div class="tab-dot" data-for="tab-enrollment"></div>
            </div>

          </div><!-- /modal-body -->

          <!-- ── FOOTER ──────────────────────────────────────────────── -->
          <div class="modal-footer">
            <span class="footer-hint" id="modalFooterHint">
              <i class="bi bi-shield-check me-1 text-success"></i>
              All changes are saved to the database immediately.
            </span>
            <button type="button" class="btn-modal-delete" id="deleteParticipantBtn">
              <i class="bi bi-trash me-1"></i> Delete
            </button>
            <button type="button" class="btn-modal-replace" id="replaceFromUpdateBtn">
              <i class="bi bi-arrow-left-right me-1"></i> Replace
            </button>
            <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
              <i class="bi bi-x me-1"></i> Close
            </button>
            <button type="button" class="btn-modal-save" id="updateParticipantBtn">
              <div class="spinner-border spinner-border-sm text-white" role="status"></div>
              <span class="save-label"><i class="bi bi-save me-1"></i> Save Changes</span>
            </button>
          </div>

        </div><!-- /modal-content -->
      </div><!-- /modal-dialog -->
    </div><!-- /updateModal -->

    <!-- Update Status (bulk) -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <div class="modal-title-wrap flex-grow-1">
                        <div class="modal-avatar"><i class="bi bi-check-circle-fill"></i></div>
                        <div class="modal-title-text">
                            <h6 class="mb-0">Update Training Status</h6>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <label class="form-label fw-bold mb-2" style="font-size:.85rem;">New Training Status:</label>
                    <select class="form-select" id="newStatusSelect" style="font-size:.85rem;">
                        <option value="completed">Completed</option>
                        <option value="not completed">Not Completed</option>
                        <option value="For Compliance">For Compliance</option>
                    </select>
                </div>
                <div class="modal-footer modal-footer-branded">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Close
                    </button>
                    <button type="button" class="btn-modal-save" id="updateSelectedBtn">
                        <i class="bi bi-check-circle me-1"></i> Update
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Zoom Link Email -->
    <div class="modal fade" id="zoomLinkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <div class="modal-title-wrap flex-grow-1">
                        <div class="modal-avatar"><i class="bi bi-camera-video-fill"></i></div>
                        <div class="modal-title-text">
                            <h6 class="mb-0">Email Zoom Link</h6>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Zoom Link:</label>
                        <input id="zoomLinkInput" class="form-control" type="text" placeholder="Paste link here...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Training Advisory (PDF):</label>
                        <input type="file" id="zoomPDF" class="form-control" accept="application/pdf">
                    </div>
                    <div>
                        <label class="form-label fw-bold">Zoom Background:</label>
                        <input type="file" id="zoomBg" class="form-control" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer modal-footer-branded">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn-modal-save" id="confirmSendEmailZoom">
                        <i class="bi bi-send me-1"></i> Send
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer Training -->
    <div class="modal fade" id="transferTrainingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <div class="modal-title-wrap flex-grow-1">
                        <div class="modal-avatar"><i class="bi bi-clipboard-check-fill"></i></div>
                        <div class="modal-title-text">
                            <h6 class="mb-0">Transfer Participant</h6>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="transferForm">
                        <input type="hidden" id="transferParticipantIds" name="participantIds">
                        <label class="form-label fw-bold mb-2">Transfer to Training:</label>
                        <select class="form-select" id="newTraining" name="newTrainingId">
                            <?php
                            $stmt = $conn->prepare("
                                SELECT id, name, start_date, end_date
                                FROM otrs_trainings
                                WHERE id != :id
                                AND training_status != 'cancelled'
                                ORDER BY name ASC
                            ");
                            $stmt->bindParam(':id', $trainingId);
                            $stmt->execute();

                            while ($t = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                if (!empty($t['start_date']) && $t['start_date'] !== '0000-00-00') {
                                    $start = new DateTime($t['start_date']);
                                    $end   = (!empty($t['end_date']) && $t['end_date'] !== '0000-00-00')
                                        ? new DateTime($t['end_date'])
                                        : $start;

                                    if ($t['start_date'] === $t['end_date']) {
                                        $dateLabel = $start->format('F j, Y');
                                    } elseif ($start->format('F Y') === $end->format('F Y')) {
                                        $dateLabel = $start->format('F j') . '–' . $end->format('j, Y');
                                    } elseif ($start->format('Y') === $end->format('Y')) {
                                        $dateLabel = $start->format('M j') . ' – ' . $end->format('M j, Y');
                                    } else {
                                        $dateLabel = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
                                    }
                                } else {
                                    $dateLabel = 'No date set';
                                }

                                echo '<option value="' . $t['id'] . '">'
                                    . htmlspecialchars($t['name']) . ' — ' . htmlspecialchars($dateLabel)
                                    . '</option>';
                            }
                            ?>
                        </select>
                    </form>
                </div>
                <div class="modal-footer modal-footer-branded">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Close
                    </button>
                    <button type="button" class="btn-modal-save" id="confirmTransfer">
                        <i class="bi bi-clipboard-check me-1"></i> Transfer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="modalToast"></div>

    <!-- Analytics -->
    <div class="modal fade" id="analyticsModal" tabindex="-1"
        data-bs-backdrop="static" data-bs-keyboard="false"
        aria-labelledby="analyticsModalLabel">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header text-white"
                    style="background: linear-gradient(135deg,#0f4c81,#1a6baa);">
                    <h5 class="modal-title" id="analyticsModalLabel">
                        <i class="bi bi-bar-chart-line-fill me-2"></i>
                        Training Breakdown Report
                    </h5>
                    <button type="button" class="btn-close btn-close-white"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="analyticsBody">
                    <div id="analyticsLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Generating…</span>
                        </div>
                        <p class="mt-3 text-muted fw-semibold">Generating analytics report…</p>
                    </div>
                    <div id="analyticsError" class="alert alert-danger d-none" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <span id="analyticsErrorMsg">Failed to load report.</span>
                    </div>
                    <div id="analyticsContent" class="d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            onclick="otrsAnalyticsPrint()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button type="button" class="btn btn-danger"
                            data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Eval Links Modal ──────────────────────────────────────── -->
    <div class="modal fade" id="evalLinksModal" tabindex="-1"
         data-bs-backdrop="static" data-bs-keyboard="false"
         aria-labelledby="evalLinksModalLabel">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header text-white"
                     style="background: linear-gradient(135deg,#1e3a5f,#0f6cbf);">
                    <div class="d-flex align-items-center gap-2 flex-grow-1 min-width-0">
                        <i class="bi bi-link-45deg fs-5"></i>
                        <div>
                            <h5 class="modal-title mb-0" id="evalLinksModalLabel">Evaluation Form Links</h5>
                            <small id="evalLinksSummary" style="opacity:.8;font-size:.72rem;"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3" id="evalLinksBody">
                    <div class="text-center py-5 text-muted" id="evalLinksLoading">
                        <div class="spinner-border" style="color:#0f6cbf;" role="status"></div>
                        <p class="mt-3 fw-semibold">Generating links…</p>
                    </div>
                    <div id="evalLinksError" class="alert alert-danger d-none"></div>
                    <div id="evalLinksContent" class="d-none"></div>
                </div>
                <div class="modal-footer gap-2">
                    <span style="font-size:.76rem;color:#64748b;flex:1;">
                        <i class="bi bi-people-fill me-1"></i>Only <strong>Paid</strong> and <strong>Promissory Note</strong> participants are shown.
                    </span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Participant Drilldown Modal ──────────────────────────── -->
    <div class="modal fade" id="drilldownModal" tabindex="-1"
         aria-labelledby="drilldownModalLabel" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header border-0">
                    <div class="modal-title-wrap flex-grow-1">
                        <div class="modal-avatar" id="drilldownAvatar">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div class="modal-title-text">
                            <h6 id="drilldownTitle">Participant List</h6>
                            <small id="drilldownSubtitle">Loading…</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3">

                    <div id="drilldownLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading…</span>
                        </div>
                        <p class="mt-3 text-muted fw-semibold">Fetching participants…</p>
                    </div>

                    <div id="drilldownError" class="alert alert-danger d-none" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <span id="drilldownErrorMsg">Failed to load participants.</span>
                    </div>

                    <div id="drilldownContent" class="d-none">
                        <div class="table-responsive">
                            <table id="drilldownTable"
                                   class="table table-bordered table-hover table-striped w-100"
                                   style="font-size:12px;">
                                <thead>
                                    <tr>
                                        <th class="text-center align-middle" style="width:40px;">#</th>
                                        <th class="align-middle">Name</th>
                                        <th class="text-center align-middle">Gender</th>
                                        <th class="align-middle">Position</th>
                                        <th class="text-center align-middle">SG</th>
                                        <th class="align-middle">Position Level</th>
                                        <th class="align-middle">Agency</th>
                                        <th class="align-middle">Sector</th>
                                        <th class="align-middle">Field Office</th>
                                        <th class="text-center align-middle">Status</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <div class="modal-footer modal-footer-branded">
                    <span class="footer-hint">
                        <i class="bi bi-people-fill me-1 text-primary"></i>
                        <span id="drilldownFooterCount">—</span>
                    </span>
                    <button type="button" id="drilldownExportBtn"
                            class="btn-modal-save d-none"
                            style="background:linear-gradient(135deg,#198754,#20a869);"
                            onclick="otrsExportDrilldown()">
                        <i class="bi bi-file-earmark-excel me-1"></i>
                        <span class="save-label">Export Excel</span>
                    </button>
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Close
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Registration & Payment Log Timeline Modal ──────────── -->
    <div class="modal fade" id="payLogModal" tabindex="-1" aria-labelledby="payLogModalLabel" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header border-0">
                    <div class="modal-title-wrap flex-grow-1">
                        <div class="modal-avatar" id="payLogAvatar">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="modal-title-text">
                            <h6 id="payLogName" class="mb-0">Loading…</h6>
                            <small id="payLogMeta">Registration &amp; Payment Log</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4" id="payLogBody" style="min-height:140px;">
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Loading log…
                    </div>
                </div>

                <div class="modal-footer modal-footer-branded">
                    <span class="footer-hint">
                        <i class="bi bi-shield-check me-1 text-success"></i>
                        Full audit trail for this enrollment.
                    </span>
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Close
                    </button>
                </div>

            </div>
        </div>
    </div>

    <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
        <i class="bi bi-arrow-up-short"></i>
    </a>

    <!-- ── JS dependencies ──────────────────────────────────── -->
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

    <!-- PHP-injected page config — must come before external scripts -->
    <script>
    const CONFIG = {
        trainingId:   <?php echo json_encode($trainingId); ?>,
        trainingMode: <?php echo json_encode($training['mode']); ?>,
        location:     <?php echo json_encode($location); ?>,
    };
    window.csrfToken = <?php echo json_encode($_SESSION['_csrf_token'] ?? ''); ?>;
    </script>

    <script src="assets/js/participants-main.js?v=26"></script>
    <script src="assets/js/participants-analytics.js?v=5"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script src="assets/js/participants-sme-attendance.js?v=7"></script>
    <script>
    (function () {
        var evalDt          = null;
        var evalSelectedDay = '0';

        function applyEvalDayFilter(day) {
            evalSelectedDay = day;

            // 1. Filter the Individual Responses DataTable (regex anchored to exact day number)
            if (evalDt) {
                evalDt.column(3).search(
                    day === '0' ? '' : '^Day\\s+' + day + '$',
                    true,   // regex
                    false   // smart-search off
                ).draw();
            }

            // 2. Filter SME per-participant detail rows by data-eval-day
            document.querySelectorAll('.eval-sme-detail-row').forEach(function(detailRow) {
                var esid      = detailRow.id.replace('eval-sme-detail-', '');
                var aggRow    = document.querySelector('.eval-sme-agg-row[data-esme-id="' + esid + '"]');
                var innerRows = detailRow.querySelectorAll('tbody tr[data-eval-day]');
                var visible   = 0;

                innerRows.forEach(function(tr) {
                    var match = (day === '0' || tr.dataset.evalDay === day);
                    tr.style.display = match ? '' : 'none';
                    if (match) visible++;
                });

                if (day === '0') {
                    // Restore "All Days" state: collapse detail rows, show all aggregate rows
                    if (aggRow) aggRow.style.display = '';
                    detailRow.classList.add('d-none');
                    var icon = aggRow ? aggRow.querySelector('.eval-sme-toggle-icon') : null;
                    if (icon) icon.style.transform = 'rotate(0deg)';
                } else {
                    // Specific day: hide SME aggregates that have no matches; auto-expand those that do
                    if (aggRow) aggRow.style.display = visible === 0 ? 'none' : '';
                    if (visible > 0) {
                        detailRow.classList.remove('d-none');
                        var icon2 = aggRow ? aggRow.querySelector('.eval-sme-toggle-icon') : null;
                        if (icon2) icon2.style.transform = 'rotate(90deg)';
                    } else {
                        detailRow.classList.add('d-none');
                    }
                }
            });
        }

        var evalTabBtn = document.getElementById('tab-eval-btn');
        if (evalTabBtn) {
            evalTabBtn.addEventListener('shown.bs.tab', function () {
                <?php if ($evalFullN > 0): ?>
                if (!evalDt) {
                    evalDt = $('#evalTableP').DataTable({
                        pageLength: 25,
                        order:      [[16, 'asc']],
                        autoWidth:  false,
                        columnDefs: [
                            { targets: 0,  width: '32px',  className: 'text-center' },
                            { targets: 3,  width: '58px',  className: 'text-center' },
                            { targets: 4,  width: '46px',  className: 'text-center' },
                            { targets: 5,  width: '52px',  className: 'text-center' },
                            { targets: 6,  width: '58px',  className: 'text-center' },
                            { targets: 7,  width: '70px',  className: 'text-center' },
                            { targets: 8,  width: '50px',  className: 'text-center' },
                            { targets: [9, 10, 11, 12, 13, 14, 15, 16], width: '130px', orderable: false },
                            { targets: 17, width: '80px',  className: 'text-center' }
                        ],
                        language: {
                            search: '', searchPlaceholder: 'Search…',
                            paginate: {
                                next:     '<i class="bi bi-chevron-right"></i>',
                                previous: '<i class="bi bi-chevron-left"></i>'
                            }
                        }
                    });
                    // Re-apply any day filter already selected before the tab was opened
                    if (evalSelectedDay !== '0') applyEvalDayFilter(evalSelectedDay);
                }
                <?php endif; ?>
            });
        }

        // Day filter button group
        var evalFilterEl = document.getElementById('evalDayFilter');
        if (evalFilterEl) {
            evalFilterEl.addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-day]');
                if (!btn) return;
                var day = btn.dataset.day;
                this.querySelectorAll('button').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                applyEvalDayFilter(day);
            });
        }

        // SME per-participant expand/collapse (manual toggle; auto-expand handled by applyEvalDayFilter)
        document.querySelectorAll('.eval-sme-expandable').forEach(function(row) {
            row.addEventListener('click', function() {
                var esid      = this.dataset.esmeId;
                var detailRow = document.getElementById('eval-sme-detail-' + esid);
                var icon      = this.querySelector('.eval-sme-toggle-icon');
                var isOpen    = !detailRow.classList.contains('d-none');
                detailRow.classList.toggle('d-none', isOpen);
                if (icon) icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
            });
        });

        // ── Eval Links button ──────────────────────────────────────
        var evalLinksModal = null;
        var evalLinksBodyEl = document.getElementById('evalLinksBody');

        // Delegated handler: copy, send-day, send-all, inline-send, inline-cancel
        evalLinksBodyEl.addEventListener('click', function(e) {
            // ── Copy link button
            var copyBtn = e.target.closest('.el-copy-btn');
            if (copyBtn && !copyBtn.disabled) {
                var url = copyBtn.dataset.url;
                var origHtml = copyBtn.innerHTML;
                function showCopied() {
                    copyBtn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
                    copyBtn.classList.add('copied');
                    setTimeout(function() { copyBtn.innerHTML = origHtml; copyBtn.classList.remove('copied'); }, 1800);
                }
                function showFail() {
                    copyBtn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Failed';
                    setTimeout(function() { copyBtn.innerHTML = origHtml; }, 2000);
                }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(url).then(showCopied).catch(function() {
                        var ta = document.createElement('textarea');
                        ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy') ? showCopied() : showFail(); } catch(ex) { showFail(); }
                        document.body.removeChild(ta);
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy') ? showCopied() : showFail(); } catch(ex) { showFail(); }
                    document.body.removeChild(ta);
                }
                return;
            }

            // ── Open inline email form (per-day or all-days)
            var sendBtn = e.target.closest('.el-send-day-btn, .el-send-all-btn');
            if (sendBtn) {
                var regId    = sendBtn.dataset.regId;
                var day      = sendBtn.dataset.day; // '1','2',… or 'all'
                var formEl   = document.getElementById('elForm-' + regId);
                var labelEl  = document.getElementById('elFormLabel-' + regId);
                var resultEl = document.getElementById('elFormResult-' + regId);
                labelEl.textContent = day === 'all'
                    ? 'Sending all evaluation day links to:'
                    : 'Sending Day ' + day + ' evaluation link to:';
                resultEl.innerHTML = '';
                resultEl.className = 'el-inline-result';
                formEl.dataset.day     = day;
                formEl.dataset.regId   = regId;
                formEl.dataset.sending = '0';
                var actionBtn = formEl.querySelector('.el-inline-send-btn');
                actionBtn.disabled = false;
                actionBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Send';
                formEl.classList.add('visible');
                var emailInp = document.getElementById('elFormEmail-' + regId);
                emailInp.classList.remove('is-invalid');
                emailInp.focus();
                return;
            }

            // ── Cancel inline form
            var cancelBtn = e.target.closest('.el-inline-cancel-btn');
            if (cancelBtn) {
                document.getElementById('elForm-' + cancelBtn.dataset.regId).classList.remove('visible');
                return;
            }

            // ── Execute inline send
            var inlineSend = e.target.closest('.el-inline-send-btn');
            if (inlineSend) {
                var regId    = inlineSend.dataset.regId;
                var formEl   = document.getElementById('elForm-' + regId);
                if (formEl.dataset.sending === '1') return;
                var emailInput = document.getElementById('elFormEmail-' + regId);
                var email      = emailInput.value.trim();
                var resultEl   = document.getElementById('elFormResult-' + regId);
                if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                    emailInput.classList.add('is-invalid');
                    emailInput.focus();
                    return;
                }
                emailInput.classList.remove('is-invalid');
                formEl.dataset.sending = '1';
                inlineSend.disabled = true;
                inlineSend.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
                var day = formEl.dataset.day;
                var payload = { training_id: CONFIG.trainingId, reg_id: parseInt(regId, 10), email: email };
                if (day !== 'all') payload.day_filter = parseInt(day, 10);
                fetch('api/send_eval_link_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken || '' },
                    body: JSON.stringify(payload)
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res.status === 'success') {
                        resultEl.className = 'el-inline-result el-ok';
                        resultEl.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + escHtml(res.message);
                        inlineSend.innerHTML = '<i class="bi bi-check-lg me-1"></i>Sent!';
                    } else {
                        resultEl.className = 'el-inline-result el-err';
                        resultEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' + escHtml(res.message || 'Failed to send.');
                        inlineSend.disabled = false;
                        inlineSend.innerHTML = '<i class="bi bi-send-fill me-1"></i>Retry';
                    }
                })
                .catch(function(){
                    resultEl.className = 'el-inline-result el-err';
                    resultEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Network error. Please try again.';
                    inlineSend.disabled = false;
                    inlineSend.innerHTML = '<i class="bi bi-send-fill me-1"></i>Retry';
                })
                .finally(function(){ formEl.dataset.sending = '0'; });
            }
        });

        // One-time search delegation — filters cards inside whatever tab is active
        evalLinksBodyEl.addEventListener('input', function(e) {
            if (e.target.id !== 'elSearch') return;
            var q = e.target.value.toLowerCase().trim();
            var activePane = evalLinksBodyEl.querySelector('.tab-pane.active');
            if (!activePane) return;
            activePane.querySelectorAll('.el-row').forEach(function(row) {
                var match = !q || row.dataset.name.includes(q) || row.dataset.email.includes(q);
                row.style.display = match ? '' : 'none';
            });
        });

        document.getElementById('evalLinksBtn').addEventListener('click', function () {
            if (!evalLinksModal) {
                evalLinksModal = new bootstrap.Modal(document.getElementById('evalLinksModal'));
            }
            document.getElementById('evalLinksLoading').classList.remove('d-none');
            document.getElementById('evalLinksError').classList.add('d-none');
            document.getElementById('evalLinksContent').classList.add('d-none');
            evalLinksModal.show();
            loadEvalLinks();
        });

        function loadEvalLinks() {
            var url = 'api/generate_eval_links.php?training_id=' + CONFIG.trainingId + '&_t=' + Date.now();
            fetch(url, { cache: 'no-store' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    document.getElementById('evalLinksLoading').classList.add('d-none');
                    if (res.status !== 'success') throw new Error(res.message || 'Failed to load links.');

                    if (!res.eval_days || !res.eval_days.length) {
                        document.getElementById('evalLinksContent').innerHTML =
                            '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>' +
                            'No evaluation days computed yet. Please assign SMEs and their day assignments first.</div>';
                        document.getElementById('evalLinksContent').classList.remove('d-none');
                        return;
                    }

                    // Summary counts
                    var paidCount     = res.data.filter(function(p){ return (p.payment_status||'').toLowerCase() === 'paid'; }).length;
                    var proCount      = res.data.length - paidCount;
                    var compliedCount = res.data.filter(function(p){ return !!p.all_submitted; }).length;
                    document.getElementById('evalLinksSummary').textContent =
                        res.data.length + ' participant' + (res.data.length !== 1 ? 's' : '') +
                        ' — Paid: ' + paidCount + ', Promissory: ' + proCount +
                        ' · ' + compliedCount + ' complied';

                    // Search bar
                    var searchBar = '<div class="mb-3"><input type="search" id="elSearch" class="form-control form-control-sm" placeholder="Search by name or email…" style="max-width:320px;"></div>';

                    // Day filter tabs
                    var dayTabs = '<ul class="nav nav-tabs mb-0" id="elDayTabs" role="tablist" style="flex-wrap:nowrap;overflow-x:auto;">';
                    dayTabs += '<li class="nav-item flex-shrink-0"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#elTabAll" type="button">All Days</button></li>';
                    res.eval_days.forEach(function(dn){
                        dayTabs += '<li class="nav-item flex-shrink-0"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#elTab' + dn + '" type="button">Day ' + dn + '</button></li>';
                    });
                    dayTabs += '</ul>';

                    function buildCards(participants, dayFilter) {
                        var cards = participants.map(function(p) {
                            var allDone = !!p.all_submitted;
                            var words   = (p.name || '').trim().split(/\s+/);
                            var initials = words.length >= 2
                                ? (words[0][0] + words[words.length - 1][0]).toUpperCase()
                                : (p.name || '?')[0].toUpperCase();
                            var ps = (p.payment_status || '').toLowerCase();
                            var payBadge = ps === 'paid'
                                ? '<span class="el-badge-paid">Paid</span>'
                                : '<span class="el-badge-pn">Promissory</span>';
                            var compliedBadge = allDone
                                ? '<span class="el-badge-complied"><i class="bi bi-check-circle-fill me-1"></i>Complied</span>'
                                : '';
                            var visibleLinks = dayFilter === null
                                ? p.links
                                : p.links.filter(function(lnk){ return lnk.day_number === dayFilter; });
                            if (!visibleLinks.length) return '';

                            var linkRows = visibleLinks.map(function(lnk) {
                                var safeUrl = lnk.url.replace(/"/g, '&quot;');
                                var subBadge = lnk.submitted
                                    ? '<span class="el-submit-badge"><i class="bi bi-check-circle-fill me-1"></i>Submitted</span>'
                                    : '<span class="el-pending-badge">Pending</span>';
                                var isDone = allDone || lnk.submitted;
                                var cpClass = isDone ? 'el-copy-btn el-submitted' : 'el-copy-btn';
                                var cpAttrs = isDone ? 'disabled' : 'data-url="' + safeUrl + '"';
                                var cpIcon  = isDone ? 'bi-clipboard-check' : 'bi-clipboard';
                                return '<div class="el-day-row">' +
                                    '<span class="el-day-label">Day ' + lnk.day_number + '</span>' +
                                    '<button class="' + cpClass + '" ' + cpAttrs + '>' +
                                        '<i class="bi ' + cpIcon + '"></i> Copy Link' +
                                    '</button>' +
                                    subBadge +
                                    '</div>';
                            }).join('');

                            var emailDayBtns = visibleLinks.map(function(lnk) {
                                return '<button class="el-send-day-btn" ' +
                                    'data-reg-id="' + p.reg_id + '" ' +
                                    'data-name="' + escHtml(p.name) + '" ' +
                                    'data-email="' + escHtml(p.email || '') + '" ' +
                                    'data-day="' + lnk.day_number + '">' +
                                    '<i class="bi bi-envelope"></i> Day ' + lnk.day_number +
                                    '</button>';
                            }).join('');
                            var emailAllBtn = visibleLinks.length > 1
                                ? '<button class="el-send-all-btn" ' +
                                    'data-reg-id="' + p.reg_id + '" ' +
                                    'data-day="all">' +
                                    '<i class="bi bi-envelope-fill"></i> All Days' +
                                    '</button>'
                                : '';

                            return '<div class="el-pax-card el-row" ' +
                                'data-name="' + escHtml(p.name).toLowerCase() + '" ' +
                                'data-email="' + escHtml(p.email || '').toLowerCase() + '">' +
                                '<div class="el-card-header">' +
                                    '<div class="el-card-avatar">' + initials + '</div>' +
                                    '<div class="flex-grow-1" style="min-width:0;">' +
                                        '<div class="el-card-name">' + escHtml(p.name) + '</div>' +
                                        '<div class="el-card-email-txt">' + escHtml(p.email || '—') + '</div>' +
                                    '</div>' +
                                    '<div class="el-card-badges">' + payBadge + compliedBadge + '</div>' +
                                '</div>' +
                                '<div class="el-card-body">' +
                                    '<div class="el-links-section">' +
                                        '<div class="el-section-label"><i class="bi bi-link-45deg me-1"></i>Evaluation Links</div>' +
                                        linkRows +
                                    '</div>' +
                                    '<div class="el-email-section">' +
                                        '<div class="el-section-label"><i class="bi bi-envelope me-1"></i>Send by Email</div>' +
                                        '<div class="d-flex flex-wrap gap-1 mb-1">' + emailDayBtns + emailAllBtn + '</div>' +
                                        '<div class="el-inline-form" id="elForm-' + p.reg_id + '">' +
                                            '<div class="el-inline-form-label" id="elFormLabel-' + p.reg_id + '"></div>' +
                                            '<input type="email" class="el-inline-input" id="elFormEmail-' + p.reg_id + '" ' +
                                                'value="' + escHtml(p.email || '') + '" placeholder="recipient@email.com">' +
                                            '<div class="el-inline-actions">' +
                                                '<button class="el-inline-send-btn" data-reg-id="' + p.reg_id + '">' +
                                                    '<i class="bi bi-send-fill me-1"></i>Send' +
                                                '</button>' +
                                                '<button class="el-inline-cancel-btn" data-reg-id="' + p.reg_id + '">Cancel</button>' +
                                            '</div>' +
                                            '<div class="el-inline-result" id="elFormResult-' + p.reg_id + '"></div>' +
                                        '</div>' +
                                    '</div>' +
                                '</div>' +
                            '</div>';
                        }).filter(Boolean).join('');
                        return cards || '<div class="text-center text-muted py-4" style="font-size:.82rem;"><i class="bi bi-inbox me-1"></i>No participants found.</div>';
                    }

                    var tabContent = '<div class="tab-content mt-3">';
                    tabContent += '<div class="tab-pane fade show active" id="elTabAll">' + buildCards(res.data, null) + '</div>';
                    res.eval_days.forEach(function(dn){
                        tabContent += '<div class="tab-pane fade" id="elTab' + dn + '">' + buildCards(res.data, dn) + '</div>';
                    });
                    tabContent += '</div>';

                    var content = document.getElementById('evalLinksContent');
                    content.innerHTML = searchBar + dayTabs + tabContent;
                    content.classList.remove('d-none');
                })
                .catch(function(e){
                    document.getElementById('evalLinksLoading').classList.add('d-none');
                    var errEl = document.getElementById('evalLinksError');
                    errEl.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(e.message);
                    errEl.classList.remove('d-none');
                });
        }

        function escHtml(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    </script>

    <script>
    // ── Waitlist Tab ──────────────────────────────────────────────────────
    let waitlistLoaded = false;
    function loadWaitlistTab(force) {
        if (waitlistLoaded && !force) return;
        waitlistLoaded = true;
        const tid = CONFIG.trainingId;
        const body = document.getElementById('waitlistTabBody');
        body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-warning"></div><p class="mt-2">Loading…</p></div>';

        fetch('api/fetch_waitlist.php?training_id=' + tid)
            .then(r => r.json())
            .then(json => {
                if (!json.success) { body.innerHTML = '<div class="alert alert-danger">Failed to load waitlist.</div>'; return; }
                const rows = json.data;
                const badge = document.getElementById('waitlistBadge');
                const waiting = rows.filter(r => r.status === 'waiting').length;
                if (waiting > 0) { badge.textContent = waiting; badge.style.display = ''; }
                else { badge.style.display = 'none'; }

                if (rows.length === 0) {
                    body.innerHTML = '<div class="otrs-alert otrs-alert-info mt-2"><i class="bi bi-info-circle-fill"></i> No one is currently on the waitlist for this training.</div>';
                    return;
                }

                const STATUS_CLS = { waiting: 'bg-warning text-dark', promoted: 'bg-success text-white', withdrawn: 'bg-secondary text-white' };
                let html = `<div class="table-responsive"><table class="table table-bordered table-hover table-striped otrs-table w-100" style="font-size:12.5px;">
                    <thead><tr>
                        <th class="text-center">#</th>
                        <th>Position</th>
                        <th>Participant</th>
                        <th>Agency</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Added By</th>
                        <th class="text-center">Added At</th>
                        <th class="text-center">Promoted At</th>
                        <th class="text-center">Action</th>
                    </tr></thead><tbody>`;

                rows.forEach((r, i) => {
                    const cls = STATUS_CLS[r.status] || 'bg-secondary text-white';
                    const promAt = r.promoted_at ? new Date(r.promoted_at).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                    const addedAt = r.added_at ? new Date(r.added_at).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                    const actions = r.status === 'waiting'
                        ? `<button class="btn btn-xs btn-success me-1" onclick="wlAction(${r.id},'promote')" title="Promote"><i class="bi bi-arrow-up-circle"></i></button>
                           <button class="btn btn-xs btn-outline-danger" onclick="wlAction(${r.id},'withdraw')" title="Withdraw"><i class="bi bi-x-circle"></i></button>`
                        : '—';
                    html += `<tr>
                        <td class="text-center">${i+1}</td>
                        <td class="text-center fw-bold">${r.status === 'waiting' ? '#' + r.position : '—'}</td>
                        <td>${escHtml(r.participant_name)}</td>
                        <td>${escHtml(r.agency_name || '—')}</td>
                        <td class="text-center"><span class="badge ${cls}">${r.status}</span></td>
                        <td class="text-center">${escHtml(r.added_by || '—')}</td>
                        <td class="text-center">${addedAt}</td>
                        <td class="text-center">${promAt}</td>
                        <td class="text-center">${actions}</td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
                body.innerHTML = html;
            })
            .catch(() => { body.innerHTML = '<div class="alert alert-danger">Request failed.</div>'; });
    }

    function wlAction(wlId, action) {
        const label = action === 'promote' ? 'Promote this participant?' : 'Withdraw this person from the waitlist?';
        const icon  = action === 'promote' ? 'question' : 'warning';
        Swal.fire({ title: label, icon, showCancelButton: true, confirmButtonText: action === 'promote' ? 'Promote' : 'Withdraw',
                    confirmButtonColor: action === 'promote' ? '#059669' : '#dc2626' })
            .then(res => {
                if (!res.isConfirmed) return;
                const fd = new FormData();
                fd.append('wl_id', wlId);
                fd.append('action', action);
                fd.append('_csrf', window.csrfToken || '');
                fetch('api/manage_waitlist.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(json => {
                        if (json.status !== 'success') { Swal.fire('Error', json.message, 'error'); return; }
                        if (json.promoted_name) {
                            Swal.fire({ icon: 'success', title: 'Promoted!', html: `<b>${json.promoted_name}</b> has been registered.`, timer: 3000, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'success', title: 'Done', text: json.message, timer: 1800, showConfirmButton: false });
                        }
                        waitlistLoaded = false;
                        loadWaitlistTab(true);
                    })
                    .catch(() => Swal.fire('Error', 'Request failed.', 'error'));
            });
    }
    </script>

<!-- ══ REPLACEMENT MODAL ════════════════════════════════════════════════ -->
<div id="replaceModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <div class="modal-avatar">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div class="modal-title-text">
                        <h6>Replace Participant</h6>
                        <small>Replacing: <span id="rep_originalName">—</span></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-tabs" role="tablist">
                <button class="modal-tab-btn rep-tab-btn active" data-tab="rep-tab-personal" aria-selected="true">
                    <i class="bi bi-person tab-icon"></i> Personal
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-professional" aria-selected="false">
                    <i class="bi bi-briefcase tab-icon"></i> Professional
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-agency" aria-selected="false">
                    <i class="bi bi-building tab-icon"></i> Agency
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-reason" aria-selected="false">
                    <i class="bi bi-chat-text tab-icon"></i> Reason
                </button>
            </div>

            <div class="modal-body">
                <form id="replaceForm">
                    <input type="hidden" name="enrollmentId"          id="rep_enrollmentId">
                    <input type="hidden" name="trainingId"            id="rep_trainingId">
                    <input type="hidden" name="originalParticipantId" id="rep_originalParticipantId">

                    <!-- Tab: Personal -->
                    <div id="rep-tab-personal" class="tab-pane-content active">
                        <div class="form-section-label"><i class="bi bi-person-lines-fill"></i> Personal Information</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_fname" name="new_fname">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="rep_mname" name="new_mname">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_lname" name="new_lname">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" id="rep_suffix" name="new_suffix">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" id="rep_gender" name="new_gender">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Civil Status</label>
                                <select class="form-select" id="rep_civil_status" name="new_civil_status">
                                    <option>Single</option><option>Married</option>
                                    <option>Widowed/Widower</option><option>Separated</option><option>Others</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="rep_email" name="new_email">
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Mobile No.</label>
                                <input type="text" class="form-control" id="rep_mobile_no" name="new_mobile_no" maxlength="11">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" id="rep_dob" name="new_dob">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">PWD</label>
                                <select class="form-select" id="rep_pwd" name="new_pwd">
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Professional -->
                    <div id="rep-tab-professional" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-briefcase-fill"></i> Professional Details</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Position Title <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_position_title" name="new_position_title">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Salary Grade</label>
                                <input type="text" class="form-control" id="rep_salary_grade" name="new_salary_grade">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Position Level</label>
                                <select class="form-select" id="rep_position_level" name="new_position_level">
                                    <option value="">— Select —</option>
                                    <option value="First Level">First Level</option>
                                    <option value="Second Level (Non-Supervisory)">Second Level (Rank-and-File)</option>
                                    <option value="Second Level (Supervisory)">Second Level (Supervisory)</option>
                                    <option value="Second Level (Executive/Managerial)">Second Level (Executive/Managerial)</option>
                                    <option value="Third Level">Third Level</option>
                                    <option value="N/A">N/A</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Employment Status</label>
                                <select class="form-select" id="rep_employment_status" name="new_employment_status">
                                    <option value="">— Select —</option>
                                    <option>Permanent</option><option>Temporary</option>
                                    <option>Coterminous</option><option>Contract of Service</option>
                                    <option>Casual</option><option>Elective</option><option>Others</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Civil Servant</label>
                                <select class="form-select" id="rep_civil_servant" name="new_civil_servant">
                                    <option value="">— Select —</option>
                                    <option>Yes</option><option>No</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CSC Field Office</label>
                                <select class="form-select" id="rep_location" name="new_location">
                                    <option value="">— Select —</option>
                                    <option value="LFOI">CSC Field Office - Leyte I</option>
                                    <option value="LFOII">CSC Field Office - Leyte II</option>
                                    <option value="WLSO">CSC Satellite Office - Western Leyte</option>
                                    <option value="SLFO">CSC Field Office - Southern Leyte</option>
                                    <option value="BFO">CSC Field Office - Biliran</option>
                                    <option value="SFO">CSC Field Office - Samar</option>
                                    <option value="ESFO">CSC Field Office - Eastern Samar</option>
                                    <option value="NSFO">CSC Field Office - Northern Samar</option>
                                    <option value="HRD">Outside Region VIII / Others</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Agency -->
                    <div id="rep-tab-agency" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-building"></i> Agency / Office</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency / Office Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_agency_name" name="new_agency_name">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency Unit</label>
                                <input type="text" class="form-control" id="rep_agency_unit" name="new_agency_unit">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Sector</label>
                                <select class="form-select" id="rep_sector" name="new_sector">
                                    <option value="">— Select —</option>
                                    <option>National Government</option>
                                    <option>Local Government</option>
                                    <option>Government-Owned Corporation</option>
                                    <option>State Universities and Colleges</option>
                                    <option>Others</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Province/Region</label>
                                <select class="form-select" id="rep_geo" name="new_geo">
                                    <option value="">— Select —</option>
                                    <option value="Province of Leyte">Province of Leyte</option>
                                    <option value="Province of Southern Leyte">Province of Southern Leyte</option>
                                    <option value="Province of Biliran">Province of Biliran</option>
                                    <option value="Province of Samar">Province of Samar</option>
                                    <option value="Province of Eastern Samar">Province of Eastern Samar</option>
                                    <option value="Province of Northern Samar">Province of Northern Samar</option>
                                    <option value="Outside Region VIII">Outside Region VIII</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency Address</label>
                                <input type="text" class="form-control" id="rep_agency_address" name="new_agency_address">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Home Address</label>
                                <input type="text" class="form-control" id="rep_home_address" name="new_home_address">
                            </div>
                        </div>
                        <div id="rep_foodRow" style="display:none;">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label"><i class="bi bi-egg-fried me-1"></i> Food Restrictions</label>
                                    <input type="text" class="form-control" id="rep_food_restrictions" name="new_food_restrictions">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Reason -->
                    <div id="rep-tab-reason" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-chat-text-fill"></i> Replacement Reason</div>
                        <div class="alert alert-info d-flex align-items-start gap-2" style="font-size:.83rem;">
                            <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
                            <span>Provide the reason for replacing this participant. This will be recorded in the audit log.</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Reason <span class="req">*</span></label>
                                <textarea class="form-control" id="rep_replacement_reason" name="replacement_reason" rows="4"
                                          placeholder="e.g. The original participant can no longer attend due to…"></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <span class="footer-hint"><i class="bi bi-exclamation-circle me-1"></i> Replacement takes effect immediately</span>
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-modal-replace" id="replaceParticipantBtn">
                    <div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div>
                    <span class="save-label"><i class="bi bi-arrow-left-right me-1"></i> Replace Participant</span>
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
