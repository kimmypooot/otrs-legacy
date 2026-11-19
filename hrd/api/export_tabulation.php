<?php
/**
 * hrd/api/export_tabulation.php
 * GET ?training_id=N
 * Generates the L1/L2 Tabulation Matrix XLSX following the CSC template format.
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403); exit('Access denied.');
}
$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) { http_response_code(422); exit('Invalid training ID.'); }

require_once __DIR__ . '/../../../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ── Column-letter helper (0-indexed) ──────────────────────────────
function tabCol(int $c): string {
    $s = ''; $n = $c + 1;
    while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
    return $s;
}
function tabRef(int $c, int $r): string { return tabCol($c) . $r; }
function tabRange(int $c1, int $r1, int $c2, int $r2): string {
    return tabRef($c1,$r1) . ':' . tabRef($c2,$r2);
}

// ── Style helper ──────────────────────────────────────────────────
function tabStyle($ws, string $range, array $style): void {
    $ws->getStyle($range)->applyFromArray($style);
}

// ── Compute section stats ─────────────────────────────────────────
// Returns per-question COUNTIF data + aggregate stats
function tabSectionStats(array $rows, array $keys): array {
    $counts = []; // $counts[q][r] = count
    $total = 0; $sum = 0.0;
    for ($q = 0; $q < 4; $q++) {
        for ($r = 1; $r <= 5; $r++) $counts[$q][$r] = 0;
    }
    foreach ($rows as $row) {
        for ($q = 0; $q < 4; $q++) {
            $key = $keys[$q] ?? null;
            $v   = ($key !== null) ? (int)($row[$key] ?? 0) : 0;
            if ($v >= 1 && $v <= 5) { $counts[$q][$v]++; $sum += $v; $total++; }
        }
    }
    $evs = 0.0; $exc = 0.0; $vs = 0.0; $avg = 0.0;
    if ($total > 0) {
        $avg = $sum / $total;
        $n4  = array_sum(array_column($counts, 4));
        $n5  = array_sum(array_column($counts, 5));
        $evs = ($n4 + $n5) / $total;
        $exc = $n5 / $total;
        $vs  = $n4 / $total;
    }
    return compact('counts', 'total', 'avg', 'evs', 'exc', 'vs');
}

try {
    // ── Training ───────────────────────────────────────────────────
    $stT = $conn->prepare("SELECT name, start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stT->execute([':id' => $trainingId]);
    $training = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$training) { http_response_code(404); exit('Training not found.'); }

    // ── All evaluations (full + facilitation-only) ────────────────
    // Facilitation-only: design_q1 = 0 (participant only rated SMEs, not design/materials/admin)
    $stE = $conn->prepare("
        SELECT e.id, e.participant_id,
               e.design_q1, e.design_q2, e.design_q3, e.design_q4,
               e.materials_q1, e.materials_q2, e.materials_q3, e.materials_q4,
               e.admin_q1, e.admin_q2, e.admin_q3, e.admin_q4,
               e.confidence_rating, e.commitment_rating,
               e.day_number
        FROM   otrs_evaluations e
        WHERE  e.training_id = :tid
        ORDER BY e.day_number ASC, e.submitted_at ASC
    ");
    $stE->execute([':tid' => $trainingId]);
    $allEvalsRaw = $stE->fetchAll(PDO::FETCH_ASSOC);

    // One row per unique participant: prefer full eval over facilitation-only.
    // SME ratings are looked up by participant_id separately, so design/materials/admin
    // from the full eval will combine with SME ratings from any of their submissions.
    $participantRows = [];
    foreach ($allEvalsRaw as $ev) {
        $pid    = (int)$ev['participant_id'];
        $isFull = (int)$ev['design_q1'] > 0;
        if (!isset($participantRows[$pid])) {
            $participantRows[$pid] = array_merge($ev, ['is_full' => $isFull]);
        } elseif ($isFull && !$participantRows[$pid]['is_full']) {
            $participantRows[$pid] = array_merge($ev, ['is_full' => true]);
        }
        // If already have a full eval for this participant, keep it
    }
    $allRows  = array_values($participantRows);
    $fullEvals = array_values(array_filter($allRows, fn($e) => $e['is_full']));
    $fullN    = count($fullEvals);

    // ── SMEs ───────────────────────────────────────────────────────
    $stSme = $conn->prepare("SELECT id, sme_name FROM otrs_training_smes WHERE training_id = :tid ORDER BY sort_order");
    $stSme->execute([':tid' => $trainingId]);
    $smes = $stSme->fetchAll(PDO::FETCH_ASSOC);
    $numSmes = count($smes);

    // ── SME ratings keyed by [participant_id][sme_id] ────────────
    // Keyed by participant rather than eval_id so that ratings submitted
    // in a facilitation-only eval (Day 1 SME) are found for the same
    // participant's full eval (Day N). Multiple submissions per participant
    // per SME are averaged.
    $smeRatings = [];
    if ($numSmes) {
        $stSR = $conn->prepare("
            SELECT e.participant_id, sr.sme_id,
                   AVG(sr.q1_rating) AS q1, AVG(sr.q2_rating) AS q2,
                   AVG(sr.q3_rating) AS q3, AVG(sr.q4_rating) AS q4
            FROM   otrs_evaluation_sme_ratings sr
            JOIN   otrs_evaluations e ON e.id = sr.evaluation_id
            WHERE  e.training_id = :tid
            GROUP BY e.participant_id, sr.sme_id
        ");
        $stSR->execute([':tid' => $trainingId]);
        foreach ($stSR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $smeRatings[(int)$r['participant_id']][(int)$r['sme_id']] = [
                (float)$r['q1'], (float)$r['q2'],
                (float)$r['q3'], (float)$r['q4'],
            ];
        }
    }

    // ── Section stats ──────────────────────────────────────────────
    $designStats = tabSectionStats($fullEvals,
        ['design_q1','design_q2','design_q3','design_q4']);
    $matsStats = tabSectionStats($fullEvals,
        ['materials_q1','materials_q2','materials_q3','materials_q4']);
    $adminStats = tabSectionStats($fullEvals,
        ['admin_q1','admin_q2','admin_q3','admin_q4']);

    $smeStatsList = [];
    foreach ($smes as $sme) {
        $sid     = (int)$sme['id'];
        $smeRows = [];
        // Include ALL participants who rated this SME (full eval + facilitation-only)
        foreach ($smeRatings as $ratings) {
            if (isset($ratings[$sid])) {
                $r = $ratings[$sid];
                $smeRows[] = ['q1' => $r[0], 'q2' => $r[1], 'q3' => $r[2], 'q4' => $r[3]];
            }
        }
        $st = tabSectionStats($smeRows, ['q1','q2','q3','q4']);
        $st['name'] = $sme['sme_name'];
        $smeStatsList[] = $st;
    }

    // Combined facilitation avg (average of all SME section stats)
    $facAvg = $facEvs = $facExc = $facVs = 0.0;
    if ($numSmes > 0) {
        foreach ($smeStatsList as $ss) {
            $facAvg += $ss['avg']; $facEvs += $ss['evs'];
            $facExc += $ss['exc']; $facVs  += $ss['vs'];
        }
        $facAvg /= $numSmes; $facEvs /= $numSmes;
        $facExc /= $numSmes; $facVs  /= $numSmes;
    }

    // Confidence / Commitment stats (Level 2)
    $confStats = tabSectionStats($fullEvals,
        ['confidence_rating', null, null, null]);
    $commStats = tabSectionStats($fullEvals,
        ['commitment_rating', null, null, null]);
    // Adjust: treat these as 1-question (Q1 only); recompute from single column
    $confStats = tabSectionStats(
        array_map(fn($e) => ['q1' => (int)$e['confidence_rating']], $fullEvals),
        ['q1', null, null, null]
    );
    $commStats = tabSectionStats(
        array_map(fn($e) => ['q1' => (int)$e['commitment_rating']], $fullEvals),
        ['q1', null, null, null]
    );

    // Overall numerical avg (Design, Facilitation combined, Admin)
    $overallAvg = 0.0;
    if ($numSmes > 0) {
        $overallAvg = ($designStats['avg'] + $matsStats['avg'] + $facAvg + $adminStats['avg']) / 4;
    } else {
        $overallAvg = ($designStats['avg'] + $matsStats['avg'] + $adminStats['avg']) / 3;
    }
    $overallEvs = ($designStats['evs'] + $matsStats['evs'] + ($numSmes > 0 ? $facEvs : 0) + $adminStats['evs'])
                  / ($numSmes > 0 ? 4 : 3);
    $overallExc = ($designStats['exc'] + $matsStats['exc'] + ($numSmes > 0 ? $facExc : 0) + $adminStats['exc'])
                  / ($numSmes > 0 ? 4 : 3);
    $overallVs  = ($designStats['vs']  + $matsStats['vs']  + ($numSmes > 0 ? $facVs  : 0) + $adminStats['vs'])
                  / ($numSmes > 0 ? 4 : 3);

    // L2 averages
    $l2Avg = ($confStats['avg'] + $commStats['avg']) / 2;
    $l2Evs = ($confStats['evs'] + $commStats['evs']) / 2;
    $l2Exc = ($confStats['exc'] + $commStats['exc']) / 2;
    $l2Vs  = ($confStats['vs']  + $commStats['vs'])  / 2;

    // ── Column layout (0-indexed) ──────────────────────────────────
    // Level 1:  A=Pax | B-E=Design | F-I=Materials | J..=SMEs(4 each) | Admin(4) | Remarks(4)
    $paxCol      = 0;
    $designStart = 1;   // B
    $matsStart   = 5;   // F
    $smeStartCols = [];
    for ($i = 0; $i < $numSmes; $i++) {
        $smeStartCols[$i] = 9 + 4 * $i;
    }
    $adminStart  = 9 + 4 * $numSmes;
    $remStart    = 13 + 4 * $numSmes;
    $remEnd      = 16 + 4 * $numSmes;
    $totalCols   = $remEnd + 1;

    // Level 2 columns
    $l2PaxCol    = 0;
    $l2ConfStart = 1;   // B
    $l2CommStart = 5;   // F
    $l2RemStart  = 9;   // J
    $l2RemEnd    = 12;
    $l2TotalCols = 13;

    // ── Row layout ─────────────────────────────────────────────────
    $maxPax        = max(30, count($allRows));   // includes facilitation-only participants
    $titleRow      = 1;
    $subTitleRow   = 2;
    $programRow    = 4;
    $courseRow     = 5;
    $sectionHdrRow = 6;
    $qNumRow       = 7;
    $dataStart     = 8;
    $dataEnd       = 7 + $maxPax;
    $countStart    = $dataEnd + 2;   // row 39 when maxPax=30
    $countEnd      = $countStart + 4;
    $pctStart      = $countEnd + 2;
    $pctEnd        = $pctStart + 4;
    $numRatRow     = $pctEnd + 1;
    $evsRow        = $numRatRow + 1;
    $excRow        = $evsRow + 1;
    $vsRow         = $excRow + 1;

    // ── Build Spreadsheet ──────────────────────────────────────────
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('CSC RO VIII OTRS')
        ->setTitle('Training Evaluation Tabulation Matrix');

    // ── SHARED STYLES ─────────────────────────────────────────────
    $S_TITLE = [
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ];
    $S_SUB = [
        'font' => ['bold' => true, 'size' => 11],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ];
    $S_META_LABEL = [
        'font' => ['bold' => true, 'size' => 10],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ];
    $S_META_VAL = [
        'font' => ['size' => 10],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ];
    $S_SECTION = [
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1F4E79']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'FFFFFF']]],
    ];
    $S_QNUM = [
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '2E74B5']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'FFFFFF']]],
    ];
    $S_DATA = [
        'font' => ['size' => 10],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'D9D9D9']]],
    ];
    $S_DATA_PAX = [
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '595959']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F2F2F2']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'D9D9D9']]],
    ];
    $S_COUNT_HDR = [
        'font' => ['bold' => true, 'size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FFF2CC']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'BF8F00']]],
    ];
    $S_COUNT = [
        'font' => ['size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FFF2CC']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'BF8F00']]],
    ];
    $S_PCT_HDR = [
        'font' => ['bold' => true, 'size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'DDEBF7']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => '2E74B5']]],
        'numberFormat' => ['formatCode' => '0.00%'],
    ];
    $S_PCT = [
        'font' => ['size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'DDEBF7']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => '2E74B5']]],
        'numberFormat' => ['formatCode' => '0.00%'],
    ];
    $S_NUM_RAT_LBL = [
        'font' => ['bold' => true, 'size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'E2EFDA']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM,
                                       'color' => ['rgb' => '375623']]],
    ];
    $S_NUM_RAT = [
        'font' => ['bold' => true, 'size' => 11],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'E2EFDA']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM,
                                       'color' => ['rgb' => '375623']]],
        'numberFormat' => ['formatCode' => '0.00'],
    ];
    $S_SUMM_SEC = [
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '375623']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'FFFFFF']]],
    ];
    $S_SUMM_LBL = [
        'font' => ['bold' => true, 'size' => 8],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'EAF6E6']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => '375623']]],
    ];
    $S_EVS = [
        'font' => ['bold' => true, 'size' => 11],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '92D050']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => '375623']]],
        'numberFormat' => ['formatCode' => '0.00%'],
    ];
    $S_EXC = [
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '375623']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => 'FFFFFF']]],
        'numberFormat' => ['formatCode' => '0.00%'],
    ];
    $S_VS = [
        'font' => ['bold' => true, 'size' => 11],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '4BACC6']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                       'color' => ['rgb' => '216A6F']]],
        'numberFormat' => ['formatCode' => '0.00%'],
    ];
    $S_OVERALL = [
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '7030A0']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM,
                                       'color' => ['rgb' => 'FFFFFF']]],
    ];
    // (S_OVERALL_NUM / S_OVERALL_PCT reserved for future use)

    // ── Helper: write summary block for one section ────────────────
    // Places: label (merged evsRow:vsRow at $lblCol), sub-labels at $subCol,
    // values at $valCol.
    function writeSummaryBlock($ws, $lblCol, $subCol, $valCol,
                               $evsRow, $excRow, $vsRow,
                               $sectionLabel, $avg, $evs, $exc, $vs,
                               $S_SUMM_SEC, $S_SUMM_LBL, $S_EVS, $S_EXC, $S_VS): void
    {
        $ws->setCellValue(tabRef($lblCol, $evsRow), $sectionLabel);
        $ws->mergeCells(tabRange($lblCol, $evsRow, $lblCol, $vsRow));
        tabStyle($ws, tabRange($lblCol, $evsRow, $lblCol, $vsRow), $S_SUMM_SEC);

        foreach ([[$evsRow,'E+VS',$evs,$S_EVS],[$excRow,"Excellent\n(5)",$exc,$S_EXC],
                  [$vsRow,"Very Satisfactory\n(4)",$vs,$S_VS]] as [$row,$lbl,$val,$sty]) {
            $ws->setCellValue(tabRef($subCol, $row), $lbl);
            tabStyle($ws, tabRef($subCol, $row), $S_SUMM_LBL);
            $ws->setCellValue(tabRef($valCol, $row), $val);
            tabStyle($ws, tabRef($valCol, $row), $sty);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // SHEET 1 — Level 1
    // ══════════════════════════════════════════════════════════════
    $ws1 = $spreadsheet->getActiveSheet();
    $ws1->setTitle('Level 1');

    // Row heights
    $ws1->getRowDimension($titleRow)->setRowHeight(22);
    $ws1->getRowDimension($sectionHdrRow)->setRowHeight(28);
    $ws1->getRowDimension($qNumRow)->setRowHeight(18);
    for ($r = $dataStart; $r <= $dataEnd; $r++) $ws1->getRowDimension($r)->setRowHeight(16);
    foreach ([$evsRow,$excRow,$vsRow] as $r) $ws1->getRowDimension($r)->setRowHeight(22);

    // Column widths
    $ws1->getColumnDimensionByColumn(1)->setWidth(5);   // A=Pax
    for ($c = 2; $c <= $totalCols; $c++) {
        $ws1->getColumnDimensionByColumn($c)->setWidth(6);
    }

    // ── Titles ────────────────────────────────────────────────────
    $ws1->setCellValue('A1', 'Learning & Development Evaluation Level 1');
    $ws1->mergeCells(tabRange(0, $titleRow, $remEnd, $titleRow));
    tabStyle($ws1, 'A1', $S_TITLE);

    $ws1->setCellValue('A2', 'Tabulation Matrix');
    $ws1->mergeCells(tabRange(0, $subTitleRow, $remEnd, $subTitleRow));
    tabStyle($ws1, 'A2', $S_SUB);

    // ── Program / Course info ─────────────────────────────────────
    $startDateStr = $training['start_date'] ?? '';
    $endDateStr   = ($training['end_date'] && $training['end_date'] !== '0000-00-00')
                    ? $training['end_date'] : '';

    $ws1->setCellValue(tabRef(0, $programRow), 'Program Title:');
    $ws1->setCellValue(tabRef(4, $programRow), $training['name']);
    $ws1->mergeCells(tabRange(4, $programRow, $adminStart - 1, $programRow));

    $ws1->setCellValue(tabRef($adminStart, $programRow), 'Start Date:');
    $ws1->setCellValue(tabRef($adminStart + 2, $programRow), $startDateStr);
    $ws1->mergeCells(tabRange($adminStart + 2, $programRow, $remEnd - 1, $programRow));

    $ws1->setCellValue(tabRef($remStart, $programRow), 'REMARKS');
    $ws1->mergeCells(tabRange($remStart, $programRow, $remEnd, $programRow));

    $ws1->setCellValue(tabRef(0, $courseRow), 'Course Title:');
    $ws1->setCellValue(tabRef(4, $courseRow), $training['name']);
    $ws1->mergeCells(tabRange(4, $courseRow, $adminStart - 1, $courseRow));

    $ws1->setCellValue(tabRef($adminStart, $courseRow), 'End Date:');
    $ws1->setCellValue(tabRef($adminStart + 2, $courseRow), $endDateStr);
    $ws1->mergeCells(tabRange($adminStart + 2, $courseRow, $remEnd - 1, $courseRow));

    foreach ([[$programRow,$courseRow]] as [$r1,$r2]) {
        tabStyle($ws1, tabRange(0,$r1,0,$r2), $S_META_LABEL);
        tabStyle($ws1, tabRange($adminStart,$r1,$adminStart,$r2), $S_META_LABEL);
    }

    // ── Section header row ────────────────────────────────────────
    $ws1->setCellValue(tabRef($paxCol, $sectionHdrRow), 'Pax');
    $ws1->mergeCells(tabRange($paxCol, $sectionHdrRow, $paxCol, $qNumRow));
    tabStyle($ws1, tabRange($paxCol,$sectionHdrRow,$paxCol,$qNumRow), $S_SECTION);

    $sections1 = [
        ['Design', $designStart, $designStart + 3],
        ['Training Materials & Methodologies', $matsStart, $matsStart + 3],
    ];
    foreach ($smes as $idx => $sme) {
        $sc = $smeStartCols[$idx];
        $sections1[] = ['Facilitation ' . ($idx + 1) . "\n" . $sme['sme_name'],
                        $sc, $sc + 3];
    }
    $sections1[] = ['Administration', $adminStart, $adminStart + 3];
    $sections1[] = ['REMARKS', $remStart, $remEnd];

    foreach ($sections1 as [$label, $cs, $ce]) {
        $ws1->setCellValue(tabRef($cs, $sectionHdrRow), $label);
        $ws1->mergeCells(tabRange($cs, $sectionHdrRow, $ce, $sectionHdrRow));
        tabStyle($ws1, tabRange($cs,$sectionHdrRow,$ce,$sectionHdrRow), $S_SECTION);
    }

    // ── Question number row ───────────────────────────────────────
    tabStyle($ws1, tabRef($paxCol, $qNumRow), $S_QNUM);
    $qCols = array_merge(
        range($designStart, $designStart + 3),
        range($matsStart, $matsStart + 3)
    );
    foreach ($smeStartCols as $sc) $qCols = array_merge($qCols, range($sc, $sc + 3));
    $qCols = array_merge($qCols, range($adminStart, $adminStart + 3));

    foreach ($qCols as $i => $c) {
        $ws1->setCellValue(tabRef($c, $qNumRow), ($i % 4) + 1);
        tabStyle($ws1, tabRef($c, $qNumRow), $S_QNUM);
    }

    // REMARKS header on q num row
    $ws1->mergeCells(tabRange($remStart, $qNumRow, $remEnd, $qNumRow));
    tabStyle($ws1, tabRange($remStart,$qNumRow,$remEnd,$qNumRow), $S_SECTION);

    // ── Data rows ─────────────────────────────────────────────────
    tabStyle($ws1, tabRange(0, $dataStart, $remEnd, $dataEnd), $S_DATA);

    for ($paxIdx = 0; $paxIdx < $maxPax; $paxIdx++) {
        $row    = $dataStart + $paxIdx;
        $ev     = $allRows[$paxIdx] ?? null;
        $isFull = $ev ? (bool)$ev['is_full'] : false;

        $ws1->setCellValue(tabRef($paxCol, $row), $paxIdx + 1);
        tabStyle($ws1, tabRef($paxCol, $row), $S_DATA_PAX);

        if ($ev) {
            // Design & Materials — full eval only
            if ($isFull) {
                $colIdx = $designStart;
                foreach (['design_q1','design_q2','design_q3','design_q4',
                          'materials_q1','materials_q2','materials_q3','materials_q4'] as $key) {
                    $ws1->setCellValue(tabRef($colIdx++, $row), (int)$ev[$key]);
                }
            }

            // SME facilitation — all participants (full + facilitation-only)
            foreach ($smes as $idx => $sme) {
                $sid = (int)$sme['id'];
                $sr  = $smeRatings[(int)$ev['participant_id']][$sid] ?? null;
                for ($q = 0; $q < 4; $q++) {
                    if ($sr && $sr[$q] > 0)
                        $ws1->setCellValue(tabRef($smeStartCols[$idx] + $q, $row), round($sr[$q]));
                }
            }

            // Administration — full eval only
            if ($isFull) {
                $colIdx = $adminStart;
                foreach (['admin_q1','admin_q2','admin_q3','admin_q4'] as $key) {
                    $ws1->setCellValue(tabRef($colIdx++, $row), (int)$ev[$key]);
                }
            }
        }
        $ws1->mergeCells(tabRange($remStart, $row, $remEnd, $row));
    }

    // ── COUNTIF section ───────────────────────────────────────────
    $allSectionStats = array_merge(
        [$designStats, $matsStats],
        $smeStatsList,
        [$adminStats]
    );

    // Collect per-question counts in column order
    // Each section has 4 questions; $qCols holds all question columns in order
    $allCountsByCol = []; // $allCountsByCol[colIndex][rating] = count
    $colOffset = 0;
    foreach ($allSectionStats as $ss) {
        for ($q = 0; $q < 4; $q++) {
            $allCountsByCol[$colOffset + $q] = $ss['counts'][$q];
        }
        $colOffset += 4;
    }

    // Header labels for count section
    tabStyle($ws1, tabRange(0, $countStart, $remEnd, $countEnd), $S_COUNT);
    for ($ri = 0; $ri < 5; $ri++) {
        $rating = $ri + 1;
        $row    = $countStart + $ri;
        $ws1->setCellValue(tabRef($paxCol, $row), $rating);
        tabStyle($ws1, tabRef($paxCol, $row), $S_COUNT_HDR);
        foreach ($qCols as $qi => $c) {
            $cnt = $allCountsByCol[$qi][$rating] ?? 0;
            $ws1->setCellValue(tabRef($c, $row), $cnt);
        }
        $ws1->mergeCells(tabRange($remStart, $row, $remEnd, $row));
    }

    // ── % distribution (5 → 1) ────────────────────────────────────
    tabStyle($ws1, tabRange(0, $pctStart, $remEnd, $pctEnd), $S_PCT);
    $pctOrder = [5, 4, 3, 2, 1];
    foreach ($pctOrder as $pi => $rating) {
        $row = $pctStart + $pi;
        $ws1->setCellValue(tabRef($paxCol, $row), $rating);
        tabStyle($ws1, tabRef($paxCol, $row), $S_PCT_HDR);
        foreach ($qCols as $qi => $c) {
            // total responses for this question
            $qTotal = array_sum($allCountsByCol[$qi]);
            $pct = $qTotal > 0 ? ($allCountsByCol[$qi][$rating] ?? 0) / $qTotal : 0.0;
            $ws1->setCellValue(tabRef($c, $row), $pct);
        }
        $ws1->mergeCells(tabRange($remStart, $row, $remEnd, $row));
    }

    // ── Numerical Rating row ──────────────────────────────────────
    tabStyle($ws1, tabRange(0, $numRatRow, $remEnd, $numRatRow), $S_NUM_RAT_LBL);
    $ws1->mergeCells(tabRange($remStart, $numRatRow, $remEnd, $numRatRow));

    $numRatSections = [
        [$designStart + 3, $designStats['avg'],  'Design'],
        [$matsStart   + 3, $matsStats['avg'],    'Materials'],
    ];
    foreach ($smeStatsList as $idx => $ss) {
        $numRatSections[] = [$smeStartCols[$idx] + 3, $ss['avg'], 'F' . ($idx + 1)];
    }
    if ($numSmes > 1) {
        // Combined facilitation at last col of last SME section
        $lastSmeEnd = $smeStartCols[$numSmes - 1] + 3;
        // Move individual last SME to col-1, combined to last col
        $lastSmeStatEntry = &$numRatSections[count($numRatSections) - 1];
        $lastSmeStatEntry[0] = $lastSmeEnd - 1; // individual at col-1
        $numRatSections[] = [$lastSmeEnd, $facAvg, 'Combined F'];
    }
    $numRatSections[] = [$adminStart + 3, $adminStats['avg'], 'Admin'];

    $ws1->setCellValue(tabRef($paxCol, $numRatRow), 'Numerical Rating');
    $ws1->mergeCells(tabRange($paxCol, $numRatRow, $remStart - 2, $numRatRow));
    tabStyle($ws1, tabRange($paxCol,$numRatRow,$remStart-2,$numRatRow), $S_NUM_RAT_LBL);

    foreach ($numRatSections as [$c, $val, $lbl]) {
        $ws1->setCellValue(tabRef($c, $numRatRow), $val);
        tabStyle($ws1, tabRef($c, $numRatRow), $S_NUM_RAT);
    }

    // Overall numerical avg
    $ws1->setCellValue(tabRef($remStart - 1, $numRatRow), 'AVERAGE RATING');
    $ws1->mergeCells(tabRange($remStart - 1, $numRatRow, $remStart - 1, $vsRow));
    tabStyle($ws1, tabRange($remStart-1,$numRatRow,$remStart-1,$vsRow), $S_OVERALL);

    $ws1->setCellValue(tabRef($remEnd - 1, $numRatRow), 'Numerical Rating');
    tabStyle($ws1, tabRef($remEnd - 1, $numRatRow), $S_NUM_RAT_LBL);
    $ws1->setCellValue(tabRef($remEnd, $numRatRow), $overallAvg);
    tabStyle($ws1, tabRef($remEnd, $numRatRow), $S_NUM_RAT);

    // ── Summary (E+VS, Excellent, VS) rows ───────────────────────
    // Overall summary column
    $ws1->setCellValue(tabRef($remEnd - 1, $evsRow), "OVERALL\n(E+VS)");
    tabStyle($ws1, tabRef($remEnd-1,$evsRow), $S_SUMM_LBL);
    $ws1->setCellValue(tabRef($remEnd, $evsRow), $overallEvs);
    tabStyle($ws1, tabRef($remEnd,$evsRow), $S_EVS);

    $ws1->setCellValue(tabRef($remEnd - 1, $excRow), "Excellent\n(5)");
    tabStyle($ws1, tabRef($remEnd-1,$excRow), $S_SUMM_LBL);
    $ws1->setCellValue(tabRef($remEnd, $excRow), $overallExc);
    tabStyle($ws1, tabRef($remEnd,$excRow), $S_EXC);

    $ws1->setCellValue(tabRef($remEnd - 1, $vsRow), "Very Satisfactory\n(4)");
    tabStyle($ws1, tabRef($remEnd-1,$vsRow), $S_SUMM_LBL);
    $ws1->setCellValue(tabRef($remEnd, $vsRow), $overallVs);
    tabStyle($ws1, tabRef($remEnd,$vsRow), $S_VS);

    // Per-section summary blocks
    // Each section: (label_col, sub_col, val_col, section_stats)
    $summaryBlocks = [
        [$designStart + 1, $designStart + 2, $designStart + 3, $designStats, 'DESIGN'],
        [$matsStart + 1,   $matsStart + 2,   $matsStart + 3,   $matsStats,   'TRAINING MATERIALS & METHODOLOGIES'],
    ];
    foreach ($smes as $idx => $sme) {
        $sc = $smeStartCols[$idx];
        $isLast = ($idx === $numSmes - 1) && $numSmes > 1;
        if ($isLast) {
            // Last SME in multi-SME training: label at col+0, sub at col+1, individual at col+2
            // Combined facilitation values (no extra label) go at col+3
            $summaryBlocks[] = [$sc, $sc + 1, $sc + 2, $smeStatsList[$idx],
                                'FACILITATION ' . ($idx + 1) . "\n" . $sme['sme_name']];
            // Write combined values directly (no label block — just values at last col)
            $ws1->setCellValue(tabRef($sc + 3, $evsRow), $facEvs);
            tabStyle($ws1, tabRef($sc + 3, $evsRow), $S_EVS);
            $ws1->setCellValue(tabRef($sc + 3, $excRow), $facExc);
            tabStyle($ws1, tabRef($sc + 3, $excRow), $S_EXC);
            $ws1->setCellValue(tabRef($sc + 3, $vsRow), $facVs);
            tabStyle($ws1, tabRef($sc + 3, $vsRow), $S_VS);
        } else {
            $summaryBlocks[] = [$sc + 1, $sc + 2, $sc + 3, $smeStatsList[$idx],
                                'FACILITATION ' . ($idx + 1) . "\n" . $sme['sme_name']];
        }
    }
    $summaryBlocks[] = [$adminStart + 1, $adminStart + 2, $adminStart + 3, $adminStats, 'ADMINISTRATION'];

    foreach ($summaryBlocks as [$lc, $sc2, $vc, $stats, $lbl]) {
        writeSummaryBlock($ws1, $lc, $sc2, $vc, $evsRow, $excRow, $vsRow,
                          $lbl, $stats['avg'], $stats['evs'], $stats['exc'], $stats['vs'],
                          $S_SUMM_SEC, $S_SUMM_LBL, $S_EVS, $S_EXC, $S_VS);
    }

    // ── Freeze panes ──────────────────────────────────────────────
    $ws1->freezePane(tabRef(1, $dataStart));

    // ══════════════════════════════════════════════════════════════
    // SHEET 2 — Level 2 (Confidence & Commitment)
    // ══════════════════════════════════════════════════════════════
    $ws2 = $spreadsheet->createSheet();
    $ws2->setTitle('Level 2');

    // L2 row layout (same offsets as L1 but maxPax rows)
    $l2DataEnd     = 7 + $maxPax;
    $l2CountStart  = $l2DataEnd + 2;
    $l2CountEnd    = $l2CountStart + 4;
    $l2PctStart    = $l2CountEnd + 2;
    $l2PctEnd      = $l2PctStart + 4;
    $l2NumRatRow   = $l2PctEnd + 1;
    $l2EvsRow      = $l2NumRatRow + 1;
    $l2ExcRow      = $l2EvsRow + 1;
    $l2VsRow       = $l2ExcRow + 1;

    // Column widths
    $ws2->getColumnDimensionByColumn(1)->setWidth(5);
    for ($c = 2; $c <= $l2TotalCols; $c++) $ws2->getColumnDimensionByColumn($c)->setWidth(6.5);

    // Titles
    $ws2->setCellValue('A1', 'Learning & Development Evaluation Level 2');
    $ws2->mergeCells(tabRange(0, 1, $l2RemEnd, 1));
    tabStyle($ws2, 'A1', $S_TITLE);

    $ws2->setCellValue('A2', 'CONFIDENCE and COMMITMENT');
    $ws2->mergeCells(tabRange(0, 2, $l2RemEnd, 2));
    tabStyle($ws2, 'A2', $S_SUB);

    $ws2->setCellValue('A3', 'Tabulation Matrix');
    $ws2->mergeCells(tabRange(0, 3, $l2RemEnd, 3));
    tabStyle($ws2, 'A3', $S_META_VAL);

    // Program info
    $ws2->setCellValue(tabRef(0, 5), 'Program Title:');
    $ws2->setCellValue(tabRef(2, 5), $training['name']);
    $ws2->mergeCells(tabRange(2, 5, $l2CommStart - 1, 5));
    $ws2->setCellValue(tabRef($l2CommStart, 5), 'Start:');
    $ws2->setCellValue(tabRef($l2CommStart + 2, 5), $startDateStr);
    tabStyle($ws2, tabRef(0,5), $S_META_LABEL);
    tabStyle($ws2, tabRef($l2CommStart,5), $S_META_LABEL);

    $ws2->setCellValue(tabRef(0, 6), 'Course Title:');
    $ws2->setCellValue(tabRef(2, 6), $training['name']);
    $ws2->mergeCells(tabRange(2, 6, $l2CommStart - 1, 6));
    $ws2->setCellValue(tabRef($l2CommStart, 6), 'End:');
    $ws2->setCellValue(tabRef($l2CommStart + 2, 6), $endDateStr);
    tabStyle($ws2, tabRef(0,6), $S_META_LABEL);
    tabStyle($ws2, tabRef($l2CommStart,6), $S_META_LABEL);

    // Section headers
    $ws2->setCellValue(tabRef($l2PaxCol, 7), 'Pax');
    $ws2->mergeCells(tabRange($l2PaxCol, 7, $l2PaxCol, 8));
    tabStyle($ws2, tabRange($l2PaxCol,7,$l2PaxCol,8), $S_SECTION);

    $ws2->setCellValue(tabRef($l2ConfStart, 7), 'Confidence');
    $ws2->mergeCells(tabRange($l2ConfStart, 7, $l2ConfStart + 3, 7));
    tabStyle($ws2, tabRange($l2ConfStart,7,$l2ConfStart+3,7), $S_SECTION);

    $ws2->setCellValue(tabRef($l2CommStart, 7), 'Commitment');
    $ws2->mergeCells(tabRange($l2CommStart, 7, $l2CommStart + 3, 7));
    tabStyle($ws2, tabRange($l2CommStart,7,$l2CommStart+3,7), $S_SECTION);

    $ws2->setCellValue(tabRef($l2RemStart, 7), 'REMARKS');
    $ws2->mergeCells(tabRange($l2RemStart, 7, $l2RemEnd, 7));
    tabStyle($ws2, tabRange($l2RemStart,7,$l2RemEnd,7), $S_SECTION);

    // Q num row
    foreach ([$l2ConfStart, $l2CommStart] as $secStart) {
        for ($q = 0; $q < 4; $q++) {
            $ws2->setCellValue(tabRef($secStart + $q, 8), $q + 1);
            tabStyle($ws2, tabRef($secStart + $q, 8), $S_QNUM);
        }
    }
    tabStyle($ws2, tabRef($l2PaxCol, 8), $S_QNUM);
    $ws2->mergeCells(tabRange($l2RemStart, 8, $l2RemEnd, 8));
    tabStyle($ws2, tabRange($l2RemStart,8,$l2RemEnd,8), $S_SECTION);

    // Data rows (only Q1 for confidence/commitment)
    $l2QCols = array_merge(range($l2ConfStart, $l2ConfStart + 3),
                           range($l2CommStart, $l2CommStart + 3));
    tabStyle($ws2, tabRange(0, 9, $l2RemEnd, 8 + $maxPax), $S_DATA);

    for ($pi = 0; $pi < $maxPax; $pi++) {
        $row = 9 + $pi;
        $ev  = $fullEvals[$pi] ?? null;
        $ws2->setCellValue(tabRef($l2PaxCol, $row), $pi + 1);
        tabStyle($ws2, tabRef($l2PaxCol, $row), $S_DATA_PAX);
        if ($ev) {
            $ws2->setCellValue(tabRef($l2ConfStart, $row), (int)$ev['confidence_rating']);
            $ws2->setCellValue(tabRef($l2CommStart, $row), (int)$ev['commitment_rating']);
        }
        $ws2->mergeCells(tabRange($l2RemStart, $row, $l2RemEnd, $row));
    }

    // L2 COUNTIF section
    $l2ConfCounts = $confStats['counts'][0]; // rating => count
    $l2CommCounts = $commStats['counts'][0];
    tabStyle($ws2, tabRange(0, $l2CountStart, $l2RemEnd, $l2CountEnd), $S_COUNT);

    for ($ri = 0; $ri < 5; $ri++) {
        $rating = $ri + 1;
        $row    = $l2CountStart + $ri;
        $ws2->setCellValue(tabRef($l2PaxCol, $row), $rating);
        tabStyle($ws2, tabRef($l2PaxCol,$row), $S_COUNT_HDR);
        $ws2->setCellValue(tabRef($l2ConfStart, $row), $l2ConfCounts[$rating] ?? 0);
        $ws2->setCellValue(tabRef($l2CommStart, $row), $l2CommCounts[$rating] ?? 0);
        // Q2-Q4 blank (0 ratings stored there)
        $ws2->mergeCells(tabRange($l2RemStart, $row, $l2RemEnd, $row));
    }

    // L2 % distribution
    tabStyle($ws2, tabRange(0, $l2PctStart, $l2RemEnd, $l2PctEnd), $S_PCT);
    foreach ([5,4,3,2,1] as $pi => $rating) {
        $row      = $l2PctStart + $pi;
        $confTot  = array_sum($l2ConfCounts);
        $commTot  = array_sum($l2CommCounts);
        $ws2->setCellValue(tabRef($l2PaxCol, $row), $rating);
        tabStyle($ws2, tabRef($l2PaxCol,$row), $S_PCT_HDR);
        $ws2->setCellValue(tabRef($l2ConfStart, $row),
            $confTot > 0 ? ($l2ConfCounts[$rating] ?? 0) / $confTot : 0.0);
        $ws2->setCellValue(tabRef($l2CommStart, $row),
            $commTot > 0 ? ($l2CommCounts[$rating] ?? 0) / $commTot : 0.0);
        $ws2->mergeCells(tabRange($l2RemStart, $row, $l2RemEnd, $row));
    }

    // L2 Numerical Rating row
    tabStyle($ws2, tabRange(0,$l2NumRatRow,$l2RemEnd,$l2NumRatRow), $S_NUM_RAT_LBL);
    $ws2->setCellValue(tabRef($l2PaxCol, $l2NumRatRow), 'Numerical Rating');
    $ws2->mergeCells(tabRange($l2PaxCol, $l2NumRatRow, $l2ConfStart, $l2NumRatRow));
    $ws2->setCellValue(tabRef($l2ConfStart + 3, $l2NumRatRow), $confStats['avg']);
    tabStyle($ws2, tabRef($l2ConfStart+3,$l2NumRatRow), $S_NUM_RAT);
    $ws2->setCellValue(tabRef($l2CommStart + 3, $l2NumRatRow), $commStats['avg']);
    tabStyle($ws2, tabRef($l2CommStart+3,$l2NumRatRow), $S_NUM_RAT);

    // Overall avg
    $ws2->setCellValue(tabRef($l2RemStart, $l2NumRatRow), 'AVERAGE RATING');
    $ws2->mergeCells(tabRange($l2RemStart, $l2NumRatRow, $l2RemStart, $l2VsRow));
    tabStyle($ws2, tabRange($l2RemStart,$l2NumRatRow,$l2RemStart,$l2VsRow), $S_OVERALL);
    $ws2->setCellValue(tabRef($l2RemEnd - 1, $l2NumRatRow), 'Numerical Rating');
    tabStyle($ws2, tabRef($l2RemEnd-1,$l2NumRatRow), $S_NUM_RAT_LBL);
    $ws2->setCellValue(tabRef($l2RemEnd, $l2NumRatRow), $l2Avg);
    tabStyle($ws2, tabRef($l2RemEnd,$l2NumRatRow), $S_NUM_RAT);

    // L2 summary blocks
    writeSummaryBlock($ws2,
        $l2ConfStart + 1, $l2ConfStart + 2, $l2ConfStart + 3,
        $l2EvsRow, $l2ExcRow, $l2VsRow,
        'CONFIDENCE LEVEL', $confStats['avg'], $confStats['evs'], $confStats['exc'], $confStats['vs'],
        $S_SUMM_SEC, $S_SUMM_LBL, $S_EVS, $S_EXC, $S_VS);

    writeSummaryBlock($ws2,
        $l2CommStart + 1, $l2CommStart + 2, $l2CommStart + 3,
        $l2EvsRow, $l2ExcRow, $l2VsRow,
        'COMMITMENT LEVEL', $commStats['avg'], $commStats['evs'], $commStats['exc'], $commStats['vs'],
        $S_SUMM_SEC, $S_SUMM_LBL, $S_EVS, $S_EXC, $S_VS);

    // Overall L2 summary
    $ws2->setCellValue(tabRef($l2RemEnd - 1, $l2EvsRow), "OVERALL\n(E+VS)");
    tabStyle($ws2, tabRef($l2RemEnd-1,$l2EvsRow), $S_SUMM_LBL);
    $ws2->setCellValue(tabRef($l2RemEnd, $l2EvsRow), $l2Evs);
    tabStyle($ws2, tabRef($l2RemEnd,$l2EvsRow), $S_EVS);

    $ws2->setCellValue(tabRef($l2RemEnd - 1, $l2ExcRow), "Excellent\n(5)");
    tabStyle($ws2, tabRef($l2RemEnd-1,$l2ExcRow), $S_SUMM_LBL);
    $ws2->setCellValue(tabRef($l2RemEnd, $l2ExcRow), $l2Exc);
    tabStyle($ws2, tabRef($l2RemEnd,$l2ExcRow), $S_EXC);

    $ws2->setCellValue(tabRef($l2RemEnd - 1, $l2VsRow), "Very Satisfactory\n(4)");
    tabStyle($ws2, tabRef($l2RemEnd-1,$l2VsRow), $S_SUMM_LBL);
    $ws2->setCellValue(tabRef($l2RemEnd, $l2VsRow), $l2Vs);
    tabStyle($ws2, tabRef($l2RemEnd,$l2VsRow), $S_VS);

    $ws2->freezePane(tabRef(1, 9));

    // ── Activate Sheet 1 and stream ───────────────────────────────
    $spreadsheet->setActiveSheetIndex(0);

    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($training['name'], 0, 40));
    $filename  = 'TabulationMatrix_' . $safeName . '_' . date('Ymd') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $writer = new XlsxWriter($spreadsheet);
    $writer->save('php://output');

} catch (PDOException $e) {
    error_log('[export_tabulation] DB: ' . $e->getMessage());
    http_response_code(500);
    exit('Export failed.');
} catch (Throwable $e) {
    error_log('[export_tabulation] ' . $e->getMessage());
    http_response_code(500);
    exit('Export failed: ' . $e->getMessage());
}
