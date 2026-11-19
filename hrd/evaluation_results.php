<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/inc/training_dates.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

/* ── Training list (Ongoing + Completed only) ─────────────────────────────── */
try {
    $trainingList = $conn->query("
        SELECT t.id, t.name, t.start_date, t.end_date, t.training_status,
               COUNT(e.id) AS eval_count
        FROM   otrs_trainings t
        LEFT JOIN otrs_evaluations e ON e.training_id = t.id
        WHERE  t.training_status IN ('ongoing', 'completed')
          AND  t.status != 'archived'
        GROUP BY t.id
        ORDER BY FIELD(t.training_status, 'ongoing', 'completed'), t.start_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trainingList = [];
}

/* ── Selected training data ──────────────────────────────────────────────── */
$trainingId       = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
$training         = null;
$evals            = [];
$fullEvals        = [];
$smes             = [];
$smeRatings       = [];
$smeDetailRatings = [];
$aggregate        = [];
$reasons          = ['commitment' => [], 'confidence' => []];
$evalDayLabels    = [];
$fullN            = 0;
$smeOnlyN         = 0;

function fmtDateRange(?string $s, ?string $e): string {
    if (!$s || $s === '0000-00-00') return '—';
    try {
        $sd = new DateTimeImmutable($s);
        $ed = ($e && $e !== '0000-00-00') ? new DateTimeImmutable($e) : null;
        if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('M j, Y');
        if ($sd->format('Y-m') === $ed->format('Y-m')) return $sd->format('M j') . '–' . $ed->format('j, Y');
        return $sd->format('M j') . ' – ' . $ed->format('M j, Y');
    } catch (Exception $ex) { return htmlspecialchars($s); }
}

function evalStarsEr(float $avg, int $max = 5): string {
    $full  = (int)floor($avg);
    $half  = ($avg - $full) >= 0.5 ? 1 : 0;
    $empty = $max - $full - $half;
    $html  = str_repeat('<i class="bi bi-star-fill text-warning" style="font-size:.85rem;"></i>', $full);
    if ($half) $html .= '<i class="bi bi-star-half text-warning" style="font-size:.85rem;"></i>';
    $html .= str_repeat('<i class="bi bi-star text-secondary" style="font-size:.85rem;opacity:.35;"></i>', $empty);
    return $html;
}

if ($trainingId) {
    try {
        $stT = $conn->prepare("
            SELECT id, name, start_date, end_date, training_status
            FROM   otrs_trainings
            WHERE  id = :id AND training_status IN ('ongoing','completed') AND status != 'archived'
            LIMIT  1
        ");
        $stT->execute([':id' => $trainingId]);
        $training = $stT->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* non-fatal */ }

    if ($training) {

        /* ── Eval day labels from SME assignments ─────────────────── */
        try {
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
        } catch (PDOException $e) { /* non-fatal */ }

        /* ── Evaluations ──────────────────────────────────────────── */
        try {
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
            $stE->execute([':tid' => $trainingId]);
            $evals = $stE->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { /* non-fatal */ }

        foreach ($evals as &$ev) {
            $ev['is_sme_only'] = ((int)$ev['design_q1'] === 0);
        }
        unset($ev);

        $fullEvals = array_values(array_filter($evals, fn($e) => !$e['is_sme_only']));
        $fullN     = count($fullEvals);
        $smeOnlyN  = count($evals) - $fullN;

        if ($fullN > 0) {
            $aggKeys = [
                'confidence_rating', 'commitment_rating',
                'design_q1','design_q2','design_q3','design_q4',
                'materials_q1','materials_q2','materials_q3','materials_q4',
                'admin_q1','admin_q2','admin_q3','admin_q4',
            ];
            foreach ($aggKeys as $k) {
                $aggregate[$k] = array_sum(array_column($fullEvals, $k)) / $fullN;
            }
            $aggregate['design_avg']    = ($aggregate['design_q1'] + $aggregate['design_q2'] + $aggregate['design_q3'] + $aggregate['design_q4']) / 4;
            $aggregate['materials_avg'] = ($aggregate['materials_q1'] + $aggregate['materials_q2'] + $aggregate['materials_q3'] + $aggregate['materials_q4']) / 4;
            $aggregate['admin_avg']     = ($aggregate['admin_q1'] + $aggregate['admin_q2'] + $aggregate['admin_q3'] + $aggregate['admin_q4']) / 4;
        }

        /* ── SMEs ─────────────────────────────────────────────────── */
        try {
            $stSme = $conn->prepare("SELECT id, sme_name, designation FROM otrs_training_smes WHERE training_id = :tid ORDER BY sort_order");
            $stSme->execute([':tid' => $trainingId]);
            $smes = $stSme->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { /* non-fatal */ }

        if ($smes && $evals) {
            try {
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
                $stSR->execute([':tid' => $trainingId]);
                foreach ($stSR->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $smeRatings[(int)$r['sme_id']] = $r;
                }
            } catch (PDOException $e) { /* non-fatal */ }

            try {
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
                $stDet->execute([':tid' => $trainingId]);
                foreach ($stDet->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                    $smeDetailRatings[(int)$dr['sme_id']][] = $dr;
                }
            } catch (PDOException $e) { $smeDetailRatings = []; }
        }

        /* ── Commitment / Confidence reasons ─────────────────────── */
        if ($evals) {
            $evalIds = array_column($evals, 'id');
            $ph = implode(',', array_fill(0, count($evalIds), '?'));
            try {
                $stCom = $conn->prepare("SELECT reason_code, COUNT(*) AS cnt FROM otrs_evaluation_commitment_reasons WHERE evaluation_id IN ($ph) GROUP BY reason_code ORDER BY cnt DESC");
                $stCom->execute($evalIds);
                $reasons['commitment'] = $stCom->fetchAll(PDO::FETCH_ASSOC);

                $stCon = $conn->prepare("SELECT reason_code, COUNT(*) AS cnt FROM otrs_evaluation_confidence_reasons WHERE evaluation_id IN ($ph) GROUP BY reason_code ORDER BY cnt DESC");
                $stCon->execute($evalIds);
                $reasons['confidence'] = $stCon->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* non-fatal */ }
        }
    }
}

$totalN = count($evals);

$locationMap = [
    ''      => 'HRD',   'hrd'   => 'HRD',
    'lfoi'  => 'LFO I', 'lfoii' => 'LFO II',
    'bfo'   => 'BFO',   'wlso'  => 'WLSO',
    'slfo'  => 'SLFO',  'sfo'   => 'SFO',
    'esfo'  => 'ESFO',  'nsfo'  => 'NSFO',
    'ro6'   => 'RO VI', 'ro7'   => 'RO VII',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Evaluation Results – OTRS HRD</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
.score-card { padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.2rem; }
.score-card .sc-label { font-size:var(--otrs-text-xs); color:var(--otrs-gray-500); text-transform:uppercase; letter-spacing:1.5px; font-weight:600; margin-bottom:.1rem; }
.score-card .sc-value { font-size:1.6rem; font-weight:700; line-height:1.1; }
.score-card .sc-sub   { font-size:var(--otrs-text-sm); color:var(--otrs-gray-400); font-weight:400; }
.score-card .sc-bar   { height:5px; border-radius:3px; margin-top:.5rem; background:var(--otrs-gray-200); }
.score-card .sc-fill  { height:100%; border-radius:3px; transition:width .4s; }

.open-text-cell { max-width:220px; white-space:normal; word-break:break-word; font-size:var(--otrs-text-xs); }
.sme-avg        { color:var(--otrs-purple); font-weight:700; }

.status-dot          { display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:4px; vertical-align:middle; }
.status-dot.ongoing  { background:#10b981; }
.status-dot.completed{ background:#6366f1; }
</style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-purple"><i class="bi bi-bar-chart-line-fill"></i></div>
                <div>
                    <h1>Evaluation Results</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Evaluation Results</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
        </div>
    </div>

    <section class="section">

        <!-- ── Training selector ──────────────────────────────────────────── -->
        <div class="otrs-card mb-4">
            <div class="otrs-card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-sm-8 col-lg-7 col-xl-6">
                        <label class="form-label fw-semibold" style="font-size:var(--otrs-text-sm);">
                            Select Training
                            <span class="text-muted fw-normal ms-1" style="font-size:var(--otrs-text-xs);">(Ongoing &amp; Completed only)</span>
                        </label>
                        <select name="training_id" class="form-select">
                            <option value="">— Choose a training —</option>
                            <?php
                            $currentGroup = null;
                            foreach ($trainingList as $t):
                                $grp = $t['training_status'];
                                if ($grp !== $currentGroup):
                                    if ($currentGroup !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($grp) . '">';
                                    $currentGroup = $grp;
                                endif;
                            ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$t['id'] === $trainingId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                                (<?php echo fmtDateRange($t['start_date'], $t['end_date']); ?>)
                                <?php if ((int)$t['eval_count'] > 0): ?>— <?php echo (int)$t['eval_count']; ?> response<?php echo $t['eval_count'] != 1 ? 's' : ''; ?><?php endif; ?>
                            </option>
                            <?php endforeach;
                            if ($currentGroup !== null) echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="col-sm-auto d-flex align-items-center gap-2">
                        <?php if ($trainingId): ?>
                        <a href="api/export_tabulation.php?training_id=<?php echo (int)$trainingId; ?>"
                           class="btn-f btn-f-success d-flex align-items-center gap-1"
                           title="Download L1/L2 Tabulation Matrix (XLSX)">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Tabulation Matrix
                        </a>
                        <?php endif; ?>
                        <button type="submit" class="btn-f btn-f-primary">
                            <i class="bi bi-bar-chart"></i> View Results
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($trainingId && !$training): ?>
        <div class="otrs-alert otrs-alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            Training not found or is not Ongoing/Completed.
        </div>
        <?php endif; ?>

        <?php if ($training): ?>

        <?php if ($totalN === 0): ?>
        <div class="otrs-alert otrs-alert-info">
            <i class="bi bi-info-circle-fill"></i>
            No evaluations submitted yet for <strong><?php echo htmlspecialchars($training['name']); ?></strong>.
        </div>

        <?php elseif ($fullN === 0): ?>
        <div class="otrs-alert otrs-alert-info">
            <i class="bi bi-info-circle-fill"></i>
            <?php echo $smeOnlyN; ?> facilitation-only evaluation<?php echo $smeOnlyN !== 1 ? 's' : ''; ?> submitted.
            General program scores will be available once participants complete the full evaluation.
        </div>

        <?php else: ?>

        <!-- ── Day filter ─────────────────────────────────────────────────── -->
        <?php if (count($evalDayLabels) > 1): ?>
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <span style="font-size:.78rem;font-weight:600;color:#475569;">Filter by Eval Day:</span>
            <div class="btn-group btn-group-sm" id="evalDayFilter">
                <button class="btn btn-outline-secondary active" data-day="0" style="font-size:.75rem;">All Days</button>
                <?php foreach ($evalDayLabels as $dn => $dlabel): ?>
                <button class="btn btn-outline-secondary" data-day="<?php echo $dn; ?>" style="font-size:.75rem;"><?php echo htmlspecialchars($dlabel); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Overall Scores ─────────────────────────────────────────────── -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="otrs-section-label mb-0">
                <i class="bi bi-clipboard-data"></i>
                Overall Scores — <?php echo htmlspecialchars($training['name']); ?>
                <span class="otrs-count-badge">
                    <?php echo $fullN; ?> response<?php echo $fullN !== 1 ? 's' : ''; ?>
                    <?php if ($smeOnlyN > 0): ?>
                    <span style="background:#fef3c7;color:#92400e;border-radius:10px;padding:.08em .45em;font-size:.7em;font-weight:600;margin-left:.3em;">
                        +<?php echo $smeOnlyN; ?> facilitation-only
                    </span>
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

        <div class="row g-3 mb-4">
            <?php
            $summaryCards = [
                ['label' => 'Confidence',     'value' => $aggregate['confidence_rating'] ?? 0, 'max' => 5, 'color' => '#0ea5e9'],
                ['label' => 'Commitment',     'value' => $aggregate['commitment_rating'] ?? 0, 'max' => 5, 'color' => '#10b981'],
                ['label' => 'Program Design', 'value' => $aggregate['design_avg']        ?? 0, 'max' => 5, 'color' => '#8b5cf6'],
                ['label' => 'Materials',      'value' => $aggregate['materials_avg']     ?? 0, 'max' => 5, 'color' => '#f59e0b'],
                ['label' => 'Administration', 'value' => $aggregate['admin_avg']         ?? 0, 'max' => 5, 'color' => '#ec4899'],
            ];
            foreach ($summaryCards as $card):
                $pct = round($card['value'] / $card['max'] * 100);
            ?>
            <div class="col-sm-6 col-lg">
                <div class="otrs-card h-100">
                    <div class="score-card">
                        <div class="sc-label"><?php echo $card['label']; ?></div>
                        <div class="sc-value" style="color:<?php echo $card['color']; ?>;">
                            <?php echo number_format($card['value'], 2); ?>
                            <span class="sc-sub">/ <?php echo $card['max']; ?></span>
                        </div>
                        <div><?php echo evalStarsEr($card['value']); ?></div>
                        <div class="sc-bar">
                            <div class="sc-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $card['color']; ?>;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── SME / Facilitator ratings ──────────────────────────────────── -->
        <?php if ($smes): ?>
        <div class="otrs-card mb-4">
            <div class="otrs-card-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="otrs-ph-icon icon-purple" style="width:28px;height:28px;font-size:.8rem;">
                        <i class="bi bi-person-workspace"></i>
                    </div>
                    <span class="fw-semibold" style="font-size:var(--otrs-text-md);color:var(--otrs-purple);">
                        Facilitator / SME Ratings
                    </span>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <small style="font-size:.72rem;color:var(--otrs-gray-500);">
                        <i class="bi bi-chevron-down me-1"></i>Click a facilitator row to see per-participant ratings
                    </small>
                    <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=sme"
                       class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                       style="font-size:.75rem;" title="Export SME Ratings to Excel/CSV">
                        <i class="bi bi-file-earmark-excel"></i> Export
                    </a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover otrs-table mb-0" id="smeAggTable">
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
                    <?php foreach ($smes as $sme):
                        $sid       = (int)$sme['id'];
                        $sr        = $smeRatings[$sid] ?? null;
                        $avg       = $sr ? round(($sr['avg_q1'] + $sr['avg_q2'] + $sr['avg_q3'] + $sr['avg_q4']) / 4, 2) : null;
                        $details   = $smeDetailRatings[$sid] ?? [];
                        $hasDetail = !empty($details);
                    ?>
                        <tr class="sme-agg-row<?php echo $hasDetail ? ' sme-expandable' : ''; ?>"
                            data-sme-id="<?php echo $sid; ?>"
                            style="cursor:<?php echo $hasDetail ? 'pointer' : 'default'; ?>;">
                            <td class="text-center" style="padding-right:0;">
                                <?php if ($hasDetail): ?>
                                <i class="bi bi-chevron-right sme-toggle-icon" style="font-size:.7rem;color:var(--otrs-purple);transition:transform .2s;"></i>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($sme['sme_name']); ?></td>
                            <td style="color:var(--otrs-gray-500);"><?php echo htmlspecialchars($sme['designation'] ?: '—'); ?></td>
                            <?php if ($sr): ?>
                            <td class="text-center"><?php echo evalStarsEr((float)$sr['avg_q1']); ?><br><small class="sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q1'], 2); ?></small></td>
                            <td class="text-center"><?php echo evalStarsEr((float)$sr['avg_q2']); ?><br><small class="sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q2'], 2); ?></small></td>
                            <td class="text-center"><?php echo evalStarsEr((float)$sr['avg_q3']); ?><br><small class="sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q3'], 2); ?></small></td>
                            <td class="text-center"><?php echo evalStarsEr((float)$sr['avg_q4']); ?><br><small class="sme-avg" style="font-size:.72rem;"><?php echo number_format($sr['avg_q4'], 2); ?></small></td>
                            <td class="text-center">
                                <span style="font-size:1.05rem;font-weight:700;color:var(--otrs-purple);"><?php echo number_format($avg, 2); ?></span><br>
                                <small style="font-size:.65rem;color:var(--otrs-gray-400);">/ 5.00</small>
                            </td>
                            <td class="text-center">
                                <?php if ($hasDetail): ?>
                                <span style="background:#f3e8ff;color:#6d28d9;padding:.12em .52em;border-radius:12px;font-size:.68rem;font-weight:700;"><?php echo (int)$sr['rating_count']; ?></span>
                                <?php else: ?>
                                <span style="color:var(--otrs-gray-400);"><?php echo (int)$sr['rating_count']; ?></span>
                                <?php endif; ?>
                            </td>
                            <?php else: ?>
                            <td colspan="6" class="text-center" style="color:var(--otrs-gray-400);font-style:italic;">No ratings submitted</td>
                            <?php endif; ?>
                        </tr>

                        <?php if ($hasDetail): ?>
                        <tr class="sme-detail-row d-none" id="sme-detail-<?php echo $sid; ?>">
                            <td colspan="9" style="padding:0;border-top:none;">
                                <div style="background:#faf7ff;border-top:2px solid #e9d5ff;padding:.85rem 1.2rem 1rem;">
                                    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6d28d9;margin-bottom:.6rem;">
                                        <i class="bi bi-person-lines-fill me-1"></i>
                                        Individual Ratings — <?php echo htmlspecialchars($sme['sme_name']); ?>
                                    </div>
                                    <div style="overflow-x:auto;">
                                    <table class="table table-sm mb-0" style="font-size:.76rem;background:#fff;border-radius:8px;overflow:hidden;">
                                        <thead>
                                            <tr style="background:#f3e8ff;">
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;white-space:nowrap;">#</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;min-width:150px;">Participant</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;">Agency</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;">Office</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;">Day</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;" title="My knowledge about the topic has improved because of the expertise of the SME.">Q1</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;" title="The SME encouraged my interaction and participation.">Q2</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;" title="The style of the SME kept me engaged throughout the session.">Q3</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;" title="The SME discussed the topics based on my learning pace.">Q4</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;text-align:center;">Avg</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;min-width:180px;">Comment</th>
                                                <th style="font-size:.65rem;font-weight:700;color:#6d28d9;white-space:nowrap;">Submitted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($details as $di => $dr):
                                            $pAvg = ($dr['q1_rating'] + $dr['q2_rating'] + $dr['q3_rating'] + $dr['q4_rating']) / 4;
                                            $pOfc = $locationMap[strtolower($dr['p_location'] ?? '')] ?? strtoupper($dr['p_location'] ?? '—');
                                            $pSub = $dr['submitted_at'] ? date('M j, Y', strtotime($dr['submitted_at'])) : '—';
                                        ?>
                                        <tr style="border-bottom:1px solid #f3e8ff;" data-eval-day="<?php echo (int)$dr['day_number']; ?>">
                                            <td style="color:#94a3b8;"><?php echo $di + 1; ?></td>
                                            <td class="fw-semibold text-nowrap"><?php echo htmlspecialchars($dr['participant_name']); ?></td>
                                            <td style="color:#64748b;max-width:160px;word-break:break-word;"><?php echo htmlspecialchars($dr['agency_name'] ?: '—'); ?></td>
                                            <td class="text-center"><span style="background:#f0fdf4;color:#166534;padding:.1em .4em;border-radius:8px;font-size:.64rem;font-weight:700;"><?php echo htmlspecialchars($pOfc); ?></span></td>
                                            <td class="text-center"><span style="background:#f3e8ff;color:#6d28d9;padding:.1em .4em;border-radius:8px;font-size:.64rem;font-weight:700;">Day <?php echo (int)$dr['day_number']; ?></span></td>
                                            <?php foreach (['q1_rating','q2_rating','q3_rating','q4_rating'] as $qk):
                                                $qv = (int)$dr[$qk];
                                                $qBg = $qv >= 4 ? '#d1fae5' : ($qv == 3 ? '#fef9c3' : '#fee2e2');
                                                $qTx = $qv >= 4 ? '#065f46' : ($qv == 3 ? '#92400e' : '#991b1b');
                                            ?>
                                            <td class="text-center"><span style="background:<?php echo $qBg; ?>;color:<?php echo $qTx; ?>;padding:.15em .5em;border-radius:8px;font-size:.75rem;font-weight:700;"><?php echo $qv; ?></span></td>
                                            <?php endforeach; ?>
                                            <td class="text-center"><span style="font-size:.8rem;font-weight:700;color:var(--otrs-purple);"><?php echo number_format($pAvg, 2); ?></span></td>
                                            <td style="color:#64748b;white-space:normal;max-width:200px;"><?php echo htmlspecialchars($dr['comments'] ?: '—'); ?></td>
                                            <td style="color:#94a3b8;white-space:nowrap;font-size:.68rem;"><?php echo $pSub; ?></td>
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
            <div style="padding:.55rem 1.1rem;font-size:.7rem;color:var(--otrs-gray-400);border-top:1px solid var(--otrs-gray-100);">
                <strong>Rating scale:</strong> 5 = Strongly Agree &nbsp;·&nbsp; 4 = Agree &nbsp;·&nbsp; 3 = Neither &nbsp;·&nbsp; 2 = Disagree &nbsp;·&nbsp; 1 = Strongly Disagree
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Confidence & Commitment reasons ────────────────────────────── -->
        <?php if ($reasons['confidence'] || $reasons['commitment']): ?>
        <div class="row g-3 mb-4">
            <?php foreach (['confidence' => 'Confidence Reasons', 'commitment' => 'Commitment Reasons'] as $rKey => $rLabel):
                if (!$reasons[$rKey]) continue; ?>
            <div class="col-md-6">
                <div class="otrs-card h-100">
                    <div class="otrs-card-top d-flex align-items-center justify-content-between gap-2">
                        <span class="fw-semibold" style="font-size:var(--otrs-text-md);"><?php echo $rLabel; ?></span>
                        <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=reasons"
                           class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                           style="font-size:.72rem;" title="Export Confidence &amp; Commitment Reasons to Excel/CSV">
                            <i class="bi bi-file-earmark-excel"></i> Export
                        </a>
                    </div>
                    <div class="otrs-card-body">
                        <?php foreach ($reasons[$rKey] as $r):
                            $pct = $fullN > 0 ? round($r['cnt'] / $fullN * 100) : 0;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1" style="font-size:var(--otrs-text-sm);">
                                <span><?php echo htmlspecialchars(str_replace('_', ' ', $r['reason_code'])); ?></span>
                                <span style="color:var(--otrs-gray-500);"><?php echo $r['cnt']; ?> (<?php echo $pct; ?>%)</span>
                            </div>
                            <div class="progress" style="height:5px;border-radius:3px;">
                                <div class="progress-bar" style="width:<?php echo $pct; ?>%;background:var(--otrs-purple);"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Individual responses ────────────────────────────────────────── -->
        <div class="otrs-card">
            <div class="otrs-card-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="otrs-ph-icon icon-purple" style="width:28px;height:28px;font-size:.8rem;">
                        <i class="bi bi-table"></i>
                    </div>
                    <span class="fw-semibold" style="font-size:var(--otrs-text-md);">Individual Responses</span>
                    <span style="background:#f3e8ff;color:#6d28d9;padding:.1em .55em;border-radius:12px;font-size:.68rem;font-weight:700;"><?php echo $fullN; ?></span>
                </div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <?php if ($smeOnlyN > 0): ?>
                    <small style="font-size:.7rem;color:var(--otrs-gray-500);"><i class="bi bi-info-circle me-1"></i>Facilitation-only submissions shown in SME Ratings above.</small>
                    <?php endif; ?>
                    <a href="api/export_eval.php?training_id=<?php echo (int)$trainingId; ?>&section=individual"
                       class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                       style="font-size:.75rem;" title="Export Individual Responses to Excel/CSV">
                        <i class="bi bi-file-earmark-excel"></i> Export
                    </a>
                </div>
            </div>
            <div class="table-responsive">
            <table id="evalTable" class="table table-sm table-hover otrs-table mb-0" style="font-size:.75rem;">
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
                <?php foreach ($fullEvals as $ei => $ev):
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

        <?php endif; /* $totalN > 0 && $fullN > 0 */ ?>
        <?php endif; /* $training */ ?>

    </section>
</main>

<?php imis_include('footer_db') ?>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
$(function () {
    <?php if ($totalN > 0 && $fullN > 0): ?>
    var selectedDay = 0;

    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== 'evalTable') return true;
        if (selectedDay === 0) return true;
        return parseInt($(dt.row(dataIndex).node()).data('eval-day')) === selectedDay;
    });

    var dt = $('#evalTable').DataTable({
        pageLength: 25,
        order:      [[16, 'asc']],
        columnDefs: [{ targets: [9, 10, 11, 12, 13, 14, 15], orderable: false }],
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-sm-12'tr>><'row'<'col-sm-5'i><'col-sm-7'p>>",
        language: {
            search: '',
            searchPlaceholder: 'Search…',
            paginate: {
                next:     '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        }
    });

    // Day filter
    document.querySelectorAll('#evalDayFilter [data-day]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#evalDayFilter [data-day]').forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            selectedDay = parseInt(this.dataset.day);
            dt.draw();

            // Also filter SME detail sub-rows
            document.querySelectorAll('.sme-detail-row tbody tr[data-eval-day]').forEach(function (row) {
                row.style.display = (selectedDay === 0 || parseInt(row.dataset.evalDay) === selectedDay) ? '' : 'none';
            });
        });
    });
    <?php endif; ?>

    // SME row expand / collapse
    document.querySelectorAll('.sme-expandable').forEach(function (row) {
        row.addEventListener('click', function () {
            var sid       = this.dataset.smeId;
            var detailRow = document.getElementById('sme-detail-' + sid);
            var icon      = this.querySelector('.sme-toggle-icon');
            var isOpen    = !detailRow.classList.contains('d-none');
            detailRow.classList.toggle('d-none', isOpen);
            if (icon) icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
        });
    });
});
</script>
</body>
</html>
