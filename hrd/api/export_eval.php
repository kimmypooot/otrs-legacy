<?php
/**
 * hrd/api/export_eval.php
 * GET ?training_id=N[&section=all|overall|sme|reasons|individual]
 * Streams a UTF-8 CSV for evaluation results.
 * section defaults to "all" (original behaviour).
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    http_response_code(422);
    exit('Invalid training ID.');
}

$section = trim(filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'all');
if (!in_array($section, ['all', 'overall', 'sme', 'reasons', 'individual'], true)) {
    $section = 'all';
}

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

try {
    // ── Training info ──────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT name, start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        http_response_code(404);
        exit('Training not found.');
    }

    // ── Evaluations ────────────────────────────────────────────────
    $stmtE = $conn->prepare("
        SELECT e.*,
               CONCAT(p.lname, ', ', p.fname,
                      IF(p.mname <> '', CONCAT(' ', LEFT(p.mname,1), '.'), '')) AS participant_name,
               p.location AS p_location, p.agency_name
        FROM   otrs_evaluations e
        JOIN   otrs_participants p ON p.id = e.participant_id
        WHERE  e.training_id = :tid
        ORDER BY e.day_number ASC, e.submitted_at ASC
    ");
    $stmtE->execute([':tid' => $trainingId]);
    $evals = $stmtE->fetchAll(PDO::FETCH_ASSOC);

    foreach ($evals as &$ev) {
        $ev['is_sme_only'] = ((int)$ev['design_q1'] === 0);
    }
    unset($ev);

    $fullEvals = array_values(array_filter($evals, fn($e) => !$e['is_sme_only']));
    $fullN     = count($fullEvals);
    $totalN    = count($evals);

    $aggregate = [];
    $aggKeys   = [
        'confidence_rating', 'commitment_rating',
        'design_q1', 'design_q2', 'design_q3', 'design_q4',
        'materials_q1', 'materials_q2', 'materials_q3', 'materials_q4',
        'admin_q1', 'admin_q2', 'admin_q3', 'admin_q4',
    ];
    if ($fullN > 0) {
        foreach ($aggKeys as $k) {
            $aggregate[$k] = array_sum(array_column($fullEvals, $k)) / $fullN;
        }
        $aggregate['design_avg']    = ($aggregate['design_q1'] + $aggregate['design_q2'] + $aggregate['design_q3'] + $aggregate['design_q4']) / 4;
        $aggregate['materials_avg'] = ($aggregate['materials_q1'] + $aggregate['materials_q2'] + $aggregate['materials_q3'] + $aggregate['materials_q4']) / 4;
        $aggregate['admin_avg']     = ($aggregate['admin_q1'] + $aggregate['admin_q2'] + $aggregate['admin_q3'] + $aggregate['admin_q4']) / 4;
    }

    // ── SME ratings ────────────────────────────────────────────────
    $stmtSme = $conn->prepare("SELECT id, sme_name, designation FROM otrs_training_smes WHERE training_id = :tid ORDER BY sort_order");
    $stmtSme->execute([':tid' => $trainingId]);
    $smes = $stmtSme->fetchAll(PDO::FETCH_ASSOC);

    $smeRatings = [];
    $smeDetailRatings = [];
    if ($smes) {
        $stmtSR = $conn->prepare("
            SELECT sr.sme_id,
                   AVG(sr.q1_rating) AS avg_q1, AVG(sr.q2_rating) AS avg_q2,
                   AVG(sr.q3_rating) AS avg_q3, AVG(sr.q4_rating) AS avg_q4,
                   COUNT(*) AS rating_count
            FROM   otrs_evaluation_sme_ratings sr
            JOIN   otrs_evaluations e ON e.id = sr.evaluation_id
            WHERE  e.training_id = :tid
            GROUP BY sr.sme_id
        ");
        $stmtSR->execute([':tid' => $trainingId]);
        foreach ($stmtSR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $smeRatings[(int)$r['sme_id']] = $r;
        }

        $stmtDet = $conn->prepare("
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
        $stmtDet->execute([':tid' => $trainingId]);
        foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) as $dr) {
            $smeDetailRatings[(int)$dr['sme_id']][] = $dr;
        }
    }

    // ── Commitment / Confidence reasons ────────────────────────────
    $reasons = ['commitment' => [], 'confidence' => []];
    if ($totalN > 0) {
        $evalIds = array_column($evals, 'id');
        $ph = implode(',', array_fill(0, count($evalIds), '?'));

        $stCom = $conn->prepare("SELECT reason_code, COUNT(*) AS cnt FROM otrs_evaluation_commitment_reasons WHERE evaluation_id IN ($ph) GROUP BY reason_code ORDER BY cnt DESC");
        $stCom->execute($evalIds);
        $reasons['commitment'] = $stCom->fetchAll(PDO::FETCH_ASSOC);

        $stCon = $conn->prepare("SELECT reason_code, COUNT(*) AS cnt FROM otrs_evaluation_confidence_reasons WHERE evaluation_id IN ($ph) GROUP BY reason_code ORDER BY cnt DESC");
        $stCon->execute($evalIds);
        $reasons['confidence'] = $stCon->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Filename ───────────────────────────────────────────────────
    $safeName    = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($training['name'], 0, 40));
    $sectionSlug = $section === 'all' ? '' : '_' . $section;
    $filename    = 'EvalResults' . $sectionSlug . '_' . $safeName . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    $fmt     = fn($v) => number_format((float)$v, 2);
    $dateStr = $training['start_date'] . ($training['end_date'] && $training['end_date'] !== '0000-00-00' && $training['end_date'] !== $training['start_date'] ? ' to ' . $training['end_date'] : '');

    // ── Header block (every section) ──────────────────────────────
    fputcsv($out, ['EVALUATION RESULTS' . ($section !== 'all' ? ' — ' . strtoupper($section) : '')]);
    fputcsv($out, ['Training', $training['name']]);
    fputcsv($out, ['Date', $dateStr]);
    fputcsv($out, ['Total Responses', $totalN . ($totalN !== $fullN ? ' (' . ($totalN - $fullN) . ' facilitation-only)' : '')]);
    fputcsv($out, ['Exported', date('F j, Y g:i A')]);
    fputcsv($out, []);

    // ── SECTION: Overall Scores ────────────────────────────────────
    if ($section === 'all' || $section === 'overall') {
        if ($fullN > 0) {
            fputcsv($out, ['OVERALL SCORES (based on ' . $fullN . ' full response' . ($fullN !== 1 ? 's' : '') . ')']);
            fputcsv($out, ['Category', 'Average Score', 'Out of']);
            fputcsv($out, ['Confidence',     $fmt($aggregate['confidence_rating']),  '5']);
            fputcsv($out, ['Commitment',     $fmt($aggregate['commitment_rating']),  '5']);
            fputcsv($out, ['Program Design', $fmt($aggregate['design_avg']),         '5']);
            fputcsv($out, ['  Q1 - Relevance to Job',         $fmt($aggregate['design_q1']), '5']);
            fputcsv($out, ['  Q2 - Covered Objectives',       $fmt($aggregate['design_q2']), '5']);
            fputcsv($out, ['  Q3 - Duration Appropriate',     $fmt($aggregate['design_q3']), '5']);
            fputcsv($out, ['  Q4 - Schedule Convenient',      $fmt($aggregate['design_q4']), '5']);
            fputcsv($out, ['Materials',      $fmt($aggregate['materials_avg']),       '5']);
            fputcsv($out, ['  Q1 - Relevance of Materials',   $fmt($aggregate['materials_q1']), '5']);
            fputcsv($out, ['  Q2 - Clarity of Materials',     $fmt($aggregate['materials_q2']), '5']);
            fputcsv($out, ['  Q3 - Usefulness of Materials',  $fmt($aggregate['materials_q3']), '5']);
            fputcsv($out, ['  Q4 - Completeness',             $fmt($aggregate['materials_q4']), '5']);
            fputcsv($out, ['Administration', $fmt($aggregate['admin_avg']),           '5']);
            fputcsv($out, ['  Q1 - Registration Process',     $fmt($aggregate['admin_q1']), '5']);
            fputcsv($out, ['  Q2 - Venue / Platform',         $fmt($aggregate['admin_q2']), '5']);
            fputcsv($out, ['  Q3 - Support / Assistance',     $fmt($aggregate['admin_q3']), '5']);
            fputcsv($out, ['  Q4 - Overall Satisfaction',     $fmt($aggregate['admin_q4']), '5']);
            fputcsv($out, []);
        } else {
            fputcsv($out, ['No full evaluations submitted yet.']);
            fputcsv($out, []);
        }
    }

    // ── SECTION: SME / Facilitator ratings ────────────────────────
    if ($section === 'all' || $section === 'sme') {
        if ($smes) {
            fputcsv($out, ['FACILITATOR / SME RATINGS — SUMMARY']);
            fputcsv($out, ['Facilitator', 'Designation', 'Q1 — Knowledge', 'Q2 — Interaction', 'Q3 — Engagement', 'Q4 — Learning Pace', 'Overall Average', 'Responses']);
            foreach ($smes as $sme) {
                $sr = $smeRatings[(int)$sme['id']] ?? null;
                if ($sr) {
                    $avg = ($sr['avg_q1'] + $sr['avg_q2'] + $sr['avg_q3'] + $sr['avg_q4']) / 4;
                    fputcsv($out, [
                        $sme['sme_name'],
                        $sme['designation'],
                        $fmt($sr['avg_q1']),
                        $fmt($sr['avg_q2']),
                        $fmt($sr['avg_q3']),
                        $fmt($sr['avg_q4']),
                        $fmt($avg),
                        (int)$sr['rating_count'],
                    ]);
                } else {
                    fputcsv($out, [$sme['sme_name'], $sme['designation'], 'No ratings', '', '', '', '', '']);
                }
            }
            fputcsv($out, []);

            // Per-participant detail per SME
            foreach ($smes as $sme) {
                $details = $smeDetailRatings[(int)$sme['id']] ?? [];
                if (!$details) continue;
                fputcsv($out, ['INDIVIDUAL RATINGS — ' . strtoupper($sme['sme_name'])]);
                fputcsv($out, ['#', 'Participant', 'Agency', 'Office/Location', 'Day', 'Q1 — Knowledge', 'Q2 — Interaction', 'Q3 — Engagement', 'Q4 — Learning Pace', 'Average', 'Comment', 'Submitted']);
                foreach ($details as $i => $dr) {
                    $avg         = ($dr['q1_rating'] + $dr['q2_rating'] + $dr['q3_rating'] + $dr['q4_rating']) / 4;
                    $officeLabel = $locationMap[strtolower($dr['p_location'] ?? '')] ?? strtoupper($dr['p_location'] ?? '');
                    fputcsv($out, [
                        $i + 1,
                        $dr['participant_name'],
                        $dr['agency_name'] ?: '',
                        $officeLabel,
                        'Day ' . (int)$dr['day_number'],
                        $dr['q1_rating'],
                        $dr['q2_rating'],
                        $dr['q3_rating'],
                        $dr['q4_rating'],
                        $fmt($avg),
                        $dr['comments'] ?: '',
                        $dr['submitted_at'] ? date('Y-m-d', strtotime($dr['submitted_at'])) : '',
                    ]);
                }
                fputcsv($out, []);
            }
        } else {
            fputcsv($out, ['No SMEs / facilitators assigned to this training.']);
            fputcsv($out, []);
        }
    }

    // ── SECTION: Commitment & Confidence reasons ──────────────────
    if ($section === 'all' || $section === 'reasons') {
        $hasReasons = $reasons['commitment'] || $reasons['confidence'];
        if ($hasReasons) {
            foreach (['commitment' => 'COMMITMENT REASONS', 'confidence' => 'CONFIDENCE REASONS'] as $rKey => $rLabel) {
                if (!$reasons[$rKey]) continue;
                fputcsv($out, [$rLabel]);
                fputcsv($out, ['Reason', 'Count', '% of Responses']);
                foreach ($reasons[$rKey] as $r) {
                    $pct = $fullN > 0 ? round($r['cnt'] / $fullN * 100, 1) : 0;
                    fputcsv($out, [
                        str_replace('_', ' ', $r['reason_code']),
                        $r['cnt'],
                        $pct . '%',
                    ]);
                }
                fputcsv($out, []);
            }
        } else {
            fputcsv($out, ['No commitment/confidence reason data available.']);
            fputcsv($out, []);
        }
    }

    // ── SECTION: Individual responses ─────────────────────────────
    if ($section === 'all' || $section === 'individual') {
        fputcsv($out, ['INDIVIDUAL RESPONSES']);
        fputcsv($out, [
            '#', 'Participant', 'Agency', 'Office/Location', 'Eval Day', 'Type',
            'Confidence', 'Commitment',
            'Design Q1', 'Design Q2', 'Design Q3', 'Design Q4', 'Design Avg',
            'Materials Q1', 'Materials Q2', 'Materials Q3', 'Materials Q4', 'Materials Avg',
            'Admin Q1', 'Admin Q2', 'Admin Q3', 'Admin Q4', 'Admin Avg',
            'Design Comment', 'Materials Comment', 'Admin Comment',
            'Liked Most', 'Improvements', 'Recommendations', 'Future Topics',
            'Submitted',
        ]);

        foreach ($evals as $idx => $ev) {
            $isSmeOnly   = $ev['is_sme_only'];
            $officeLabel = $locationMap[strtolower($ev['p_location'] ?? '')] ?? strtoupper($ev['p_location'] ?? '');
            $dn          = (int)($ev['day_number'] ?? 1);

            if ($isSmeOnly) {
                $row = [
                    $idx + 1,
                    $ev['participant_name'],
                    $ev['agency_name'] ?: '',
                    $officeLabel,
                    'Day ' . $dn,
                    'Facilitation Only',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '',
                    $ev['submitted_at'] ? date('Y-m-d', strtotime($ev['submitted_at'])) : '',
                ];
            } else {
                $designAvg = ($ev['design_q1'] + $ev['design_q2'] + $ev['design_q3'] + $ev['design_q4']) / 4;
                $matsAvg   = ($ev['materials_q1'] + $ev['materials_q2'] + $ev['materials_q3'] + $ev['materials_q4']) / 4;
                $adminAvg  = ($ev['admin_q1'] + $ev['admin_q2'] + $ev['admin_q3'] + $ev['admin_q4']) / 4;
                $row = [
                    $idx + 1,
                    $ev['participant_name'],
                    $ev['agency_name'] ?: '',
                    $officeLabel,
                    'Day ' . $dn,
                    'Full',
                    $ev['confidence_rating'],
                    $ev['commitment_rating'],
                    $ev['design_q1'],    $ev['design_q2'],    $ev['design_q3'],    $ev['design_q4'],    $fmt($designAvg),
                    $ev['materials_q1'], $ev['materials_q2'], $ev['materials_q3'], $ev['materials_q4'], $fmt($matsAvg),
                    $ev['admin_q1'],     $ev['admin_q2'],     $ev['admin_q3'],     $ev['admin_q4'],     $fmt($adminAvg),
                    $ev['design_comment']    ?: '',
                    $ev['materials_comment'] ?: '',
                    $ev['admin_comment']     ?: '',
                    $ev['liked_most']        ?: '',
                    $ev['improvements']      ?: '',
                    $ev['recommendations']   ?: '',
                    $ev['future_topics']     ?: '',
                    $ev['submitted_at'] ? date('Y-m-d', strtotime($ev['submitted_at'])) : '',
                ];
            }
            fputcsv($out, $row);
        }
        fputcsv($out, []);
    }

    fclose($out);

} catch (PDOException $e) {
    error_log('[export_eval] ' . $e->getMessage());
    http_response_code(500);
    exit('Export failed.');
}
