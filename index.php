<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle      = 'Learning and Development';
$breadcrumbText = 'HRD';
$sessionType    = strtolower($_SESSION['type'] ?? '');
$location       = ($sessionType === 'hrd')
    ? strtolower(filter_input(INPUT_GET, 'location', FILTER_SANITIZE_SPECIAL_CHARS) ?? '')
    : $sessionType;

$titleMap = [
    'hrd'   => ['title' => 'Human Resource Division',              'breadcrumb' => 'HRD'],
    'lfoi'  => ['title' => 'CSC Field Office — Leyte I',           'breadcrumb' => 'LFOI'],
    'lfoii' => ['title' => 'CSC Field Office — Leyte II',          'breadcrumb' => 'LFOII'],
    'bfo'   => ['title' => 'CSC Field Office — Biliran',           'breadcrumb' => 'BFO'],
    'wlso'  => ['title' => 'CSC Satellite Office — Western Leyte', 'breadcrumb' => 'WLSO'],
    'slfo'  => ['title' => 'CSC Field Office — Southern Leyte',    'breadcrumb' => 'SLFO'],
    'sfo'   => ['title' => 'CSC Field Office — Samar',             'breadcrumb' => 'SFO'],
    'esfo'  => ['title' => 'CSC Field Office — Eastern Samar',     'breadcrumb' => 'ESFO'],
    'nsfo'  => ['title' => 'CSC Field Office — Northern Samar',    'breadcrumb' => 'NSFO'],
    'ro6'   => ['title' => 'CSC Regional Office VI',               'breadcrumb' => 'RO6'],
    'ro7'   => ['title' => 'CSC Regional Office VII',              'breadcrumb' => 'RO7'],
];

if (isset($titleMap[$location])) {
    $pageTitle      = $titleMap[$location]['title'];
    $breadcrumbText = $titleMap[$location]['breadcrumb'];
}

// Pending cancellations for this office
$pendingCancelCount = 0;
try {
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM otrs_cancellation_requests WHERE status='pending' AND LOWER(requested_by_location)=LOWER(:loc)");
    $cStmt->execute([':loc' => $location]);
    $pendingCancelCount = (int) $cStmt->fetchColumn();
} catch (PDOException $e) { error_log('[index.php pendingCancelCount] ' . $e->getMessage()); }

// Auto-complete overdue trainings (5-minute lock)
$_autoLock = sys_get_temp_dir() . '/otrs_auto_complete.lock';
if (!file_exists($_autoLock) || (time() - filemtime($_autoLock)) > 300) {
    try {
        // Move trainings whose start_date is today or has passed (and end not yet passed) to Ongoing
        $conn->prepare(
            "UPDATE otrs_trainings SET training_status='ongoing'
             WHERE start_date IS NOT NULL AND start_date != '0000-00-00'
               AND start_date <= CURDATE()
               AND (end_date IS NULL OR end_date = '0000-00-00' OR end_date >= CURDATE())
               AND LOWER(training_status) NOT IN ('completed','cancelled','ongoing','rescheduled')"
        )->execute();
        // Mark overdue trainings as completed
        $conn->prepare(
            "UPDATE otrs_trainings SET training_status='completed'
             WHERE end_date IS NOT NULL AND end_date<>'0000-00-00'
               AND end_date < CURDATE()
               AND LOWER(training_status) NOT IN ('completed','cancelled','rescheduled')"
        )->execute();
    } catch (PDOException $_e) { error_log('[otrs_auto_complete] ' . $_e->getMessage()); }
    @touch($_autoLock);
}
unset($_autoLock, $_e);

// Tab counts (for tab labels)
$tabCounts = ['upcoming' => 0, 'ongoing' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $tcRows = $conn->query("SELECT LOWER(training_status) AS s, COUNT(*) AS n FROM otrs_trainings GROUP BY s")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tcRows as $r) {
        if (array_key_exists($r['s'], $tabCounts)) $tabCounts[$r['s']] = (int)$r['n'];
    }
} catch (PDOException $e) { error_log('[index.php tabCounts] ' . $e->getMessage()); }

// Office participation summary (FO only)
$officeSummary = ['paid' => 0, 'pending' => 0, 'promissory' => 0, 'cancelled' => 0, 'total' => 0];
if ($location && $sessionType !== 'hrd') {
    try {
        $sStmt = $conn->prepare("
            SELECT
                SUM(r.payment_status='paid')            AS paid,
                SUM(r.payment_status='pending')         AS pending,
                SUM(r.payment_status='promissory note') AS promissory,
                SUM(r.payment_status='cancelled')       AS cancelled,
                COUNT(*) AS total
            FROM   otrs_registrations r
            JOIN   otrs_participants  p ON p.id = r.participant_id
            JOIN   otrs_trainings     t ON t.id = r.training_id
            WHERE  UPPER(p.location) = UPPER(:loc)
              AND  t.training_status NOT IN ('completed','cancelled','Rescheduled','rescheduled')
              AND  t.status = 'active'
        ");
        $sStmt->execute([':loc' => $location]);
        $officeSummary = array_merge($officeSummary, $sStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (PDOException $e) { error_log('[index.php officeSummary] ' . $e->getMessage()); }
}

function formatTrainingDate(string $start, string $end): string
{
    if (empty($start) || $start === '0000-00-00') return '—';
    try {
        $s = new DateTimeImmutable($start);
        $e = (!empty($end) && $end !== '0000-00-00') ? new DateTimeImmutable($end) : $s;
        if ($s->format('Y-m-d') === $e->format('Y-m-d'))  return $s->format('F j, Y');
        if ($s->format('Y') !== $e->format('Y'))           return $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
        if ($s->format('m') !== $e->format('m'))           return $s->format('M j')    . ' – ' . $e->format('M j, Y');
        return $s->format('F j') . '–' . $e->format('j, Y');
    } catch (Exception $ex) { return htmlspecialchars($start); }
}

function renderUserTrainingRows(string $statusFilter, string $locationFilter): void
{
    global $conn;
    $sortDir = in_array($statusFilter, ['upcoming', 'ongoing'], true) ? 'ASC' : 'DESC';
    try {
        if (!empty($locationFilter)) {
            $sql = "
                SELECT t.id, t.name, t.start_date, t.end_date, t.location, t.mode, t.training_status, t.slots,
                    COALESCE(SUM(p.id IS NOT NULL AND r.payment_status IN ('pending','paid','promissory note')),0) AS participant_status_count,
                    COALESCE(SUM(p.id IS NOT NULL AND r.payment_status='pending'),         0) AS pending_count,
                    COALESCE(SUM(p.id IS NOT NULL AND r.payment_status='paid'),            0) AS paid_count,
                    COALESCE(SUM(p.id IS NOT NULL AND r.payment_status='promissory note'), 0) AS promissory_count,
                    COALESCE(SUM(p.id IS NOT NULL AND r.payment_status='free'),            0) AS free_count,
                    COALESCE(SUM(p.id IS NOT NULL AND r.payment_status='cancelled'),       0) AS cancelled_count,
                    COALESCE(SUM(p.id IS NOT NULL AND r.proof_file IS NOT NULL AND r.proof_file!='' AND r.proof_file!='outputs/'
                                 AND r.payment_status NOT IN ('paid','free') AND r.registration_status NOT IN ('cancelled','not qualified')),0) AS proof_pending_count,
                    COALESCE(SUM(p.id IS NOT NULL), 0) AS total_count
                FROM otrs_trainings AS t
                LEFT JOIN otrs_registrations AS r ON r.training_id=t.id
                LEFT JOIN otrs_participants  AS p ON p.id=r.participant_id AND UPPER(p.location)=UPPER(:location)
                WHERE t.training_status=:status
                GROUP BY t.id ORDER BY t.start_date $sortDir
            ";
        } else {
            $sql = "
                SELECT t.id, t.name, t.start_date, t.end_date, t.location, t.mode, t.training_status, t.slots,
                    COALESCE(SUM(r.payment_status IN ('pending','paid','promissory note')),0) AS participant_status_count,
                    COALESCE(SUM(r.payment_status='pending'),         0) AS pending_count,
                    COALESCE(SUM(r.payment_status='paid'),            0) AS paid_count,
                    COALESCE(SUM(r.payment_status='promissory note'), 0) AS promissory_count,
                    COALESCE(SUM(r.payment_status='free'),            0) AS free_count,
                    COALESCE(SUM(r.payment_status='cancelled'),       0) AS cancelled_count,
                    COALESCE(COUNT(r.id), 0) AS total_count
                FROM otrs_trainings AS t
                LEFT JOIN otrs_registrations AS r ON r.training_id=t.id
                WHERE t.training_status=:status
                GROUP BY t.id ORDER BY t.start_date $sortDir
            ";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':status', $statusFilter);
        if (!empty($locationFilter)) $stmt->bindParam(':location', $locationFilter);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return;

        $counter = 1;
        foreach ($rows as $row) {
            $ts = strtolower($row['training_status']);
            $badgeHtml = match ($ts) {
                'upcoming'    => '<span class="ts-badge ts-upcoming">Upcoming</span>',
                'ongoing'     => '<span class="ts-badge ts-ongoing">Ongoing</span>',
                'completed'   => '<span class="ts-badge ts-completed">Completed</span>',
                'cancelled'   => '<span class="ts-badge ts-cancelled">Cancelled</span>',
                'rescheduled' => '<span class="ts-badge ts-rescheduled">Rescheduled</span>',
                default       => '<span class="ts-badge ts-default">' . htmlspecialchars($row['training_status']) . '</span>',
            };

            $displayDate    = formatTrainingDate($row['start_date'], $row['end_date']);
            $totalCount     = (int)($row['total_count'] ?? 0);
            $noParticipants = ($totalCount === 0);
            $disabledMaster = ($ts === 'cancelled' || $noParticipants) ? 'disabled' : '';
            $listTitle      = $ts === 'cancelled' ? 'Training cancelled'
                            : ($noParticipants    ? 'No participants yet'
                                                  : 'View participant list');
            $baseUrl        = 'participants.php?id=' . $row['id'] . '&location=' . urlencode($locationFilter);

            $cl = static function (int $n, string $filter) use ($baseUrl): string {
                return $n > 0
                    ? '<a href="' . $baseUrl . '&filter=' . urlencode($filter) . '" class="count-link">' . $n . '</a>'
                    : '<span class="count-zero">0</span>';
            };

            echo '<tr>';
            echo '<td class="text-center text-muted" style="font-size:.75rem;width:36px;">' . $counter++ . '</td>';
            echo '<td class="fw-semibold" style="font-size:.8rem;">' . htmlspecialchars($row['name']) . '</td>';
            echo '<td style="font-size:.78rem;white-space:nowrap;">' . $displayDate . '</td>';
            echo '<td style="font-size:.78rem;">' . htmlspecialchars($row['location']) . '</td>';
            echo '<td style="font-size:.78rem;">' . htmlspecialchars($row['mode']) . '</td>';
            $slots = (int)($row['slots'] ?? 0);
            $slotsBadge = $slots <= 0
                ? '<span style="display:inline-block;padding:.15rem .45rem;border-radius:20px;font-size:.7rem;font-weight:700;background:#fee2e2;color:#991b1b;">Full</span>'
                : '<span style="display:inline-block;padding:.15rem .45rem;border-radius:20px;font-size:.7rem;font-weight:700;background:#d1fae5;color:#065f46;">' . $slots . '</span>';
            echo '<td class="text-center">' . $slotsBadge . '</td>';
            echo '<td>' . $badgeHtml . '</td>';
            echo '<td class="text-center">' . $cl($row['participant_status_count'], 'All')             . '</td>';
            echo '<td class="text-center">' . $cl($row['paid_count'],               'paid')            . '</td>';
            echo '<td class="text-center">' . $cl($row['promissory_count'],          'promissory note') . '</td>';
            echo '<td class="text-center">' . $cl($row['pending_count'],             'pending')         . '</td>';
            echo '<td class="text-center">' . $cl($row['free_count'],               'free')            . '</td>';
            echo '<td class="text-center">' . $cl($row['cancelled_count'],           'cancelled')       . '</td>';
            echo '<td class="text-center" style="white-space:nowrap;">';
            echo '<a href="' . ($disabledMaster ? '#' : $baseUrl) . '"
                    class="btn-f btn-f-primary participants-link ' . $disabledMaster . '"
                    style="font-size:.72rem;padding:.22rem .6rem;"
                    title="' . $listTitle . '">
                    <i class="bi bi-people-fill"></i> List
                  </a> ';
            echo '<button type="button" class="btn-f btn-f-secondary view-graph-btn" style="font-size:.72rem;padding:.22rem .6rem;"
                    data-training-id="'    . $row['id'] . '"
                    data-training-name="'  . htmlspecialchars($row['name']) . '"
                    data-training-date="'  . $displayDate . '"
                    data-training-count="' . $row['paid_count'] . '"
                    data-pending-count="'  . $row['pending_count'] . '"
                    data-promissory-count="' . $row['promissory_count'] . '">
                    <i class="bi bi-pie-chart-fill"></i>
                  </button>';
            echo '</td>';
            echo '</tr>';
        }
    } catch (PDOException $e) { error_log('[index.php renderRows] ' . $e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OTRS — <?php echo htmlspecialchars($pageTitle); ?></title>

  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/apexcharts/3.40.0/apexcharts.min.css" rel="stylesheet">
  <link href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
  <link href="assets/css/field-office.css?v=<?php echo filemtime(__DIR__.'/assets/css/field-office.css'); ?>" rel="stylesheet">

  <style>
    /* ── Page surface ─────────────────────────────────────── */
    body { background: #f4f6fb; font-family: 'Poppins', sans-serif; }

    /* ── Compact page title ───────────────────────────────── */
    .pagetitle h1 { font-size: 1rem; font-weight: 700; color: #012970; }
    .pagetitle .breadcrumb { font-size: .72rem; }
    .pagetitle { margin-bottom: 1rem !important; }

    /* ── Office stat strip (FO only) ──────────────────────── */
    .office-stat-strip {
      display: flex; flex-wrap: wrap; gap: .6rem;
      margin-bottom: 1rem;
    }
    .osp {
      display: flex; align-items: center; gap: .55rem;
      background: #fff; border: 1.5px solid #ebeef4;
      border-radius: 10px; padding: .6rem .9rem;
      min-width: 130px; flex: 1;
    }
    .osp-icon {
      width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: .9rem;
    }
    .osp-val  { font-size: 1.25rem; font-weight: 800; line-height: 1; }
    .osp-lbl  { font-size: .65rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }

    /* ── Main card ────────────────────────────────────────── */
    .main-card {
      background: #fff; border-radius: 12px;
      border: 1.5px solid #ebeef4;
      overflow: hidden;
    }

    /* ── Tab + toolbar header bar ────────────────────────── */
    .card-top-bar {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: .75rem;
      padding: .85rem 1.1rem;
      border-bottom: 1.5px solid #ebeef4;
      background: #fafbff;
    }

    /* Segmented tab pills */
    .seg-tabs {
      display: inline-flex; gap: .25rem;
      background: #eef1f8; border-radius: 8px; padding: .25rem;
    }
    .seg-tab {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .32rem .8rem; border-radius: 6px;
      font-size: .75rem; font-weight: 600; color: #64748b;
      cursor: pointer; border: none; background: transparent;
      transition: background .15s, color .15s, box-shadow .15s;
      white-space: nowrap;
    }
    .seg-tab:hover  { color: #334155; background: rgba(255,255,255,.7); }
    .seg-tab.active {
      background: #fff; color: #012970;
      box-shadow: 0 1px 4px rgba(1,41,112,.12);
    }
    .seg-tab .tab-dot {
      width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
    }
    .seg-tab .tab-count {
      background: #e2e8f0; color: #475569;
      font-size: .6rem; font-weight: 700;
      padding: .05rem .38rem; border-radius: 10px; min-width: 18px; text-align: center;
    }
    .seg-tab.active .tab-count { background: #dbeafe; color: #1d4ed8; }

    /* Toolbar buttons */
    .top-actions { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }

    /* ── Table wrapper ────────────────────────────────────── */
    .table-wrap { padding: .75rem 1.1rem 1rem; }

    /* ── Status badges ────────────────────────────────────── */
    .ts-badge {
      display: inline-flex; align-items: center;
      padding: .18rem .6rem; border-radius: 20px;
      font-size: .68rem; font-weight: 700; letter-spacing: .2px;
      white-space: nowrap;
    }
    .ts-upcoming    { background: #dbeafe; color: #1e40af; }
    .ts-ongoing     { background: #fef3c7; color: #92400e; }
    .ts-completed   { background: #dcfce7; color: #15803d; }
    .ts-cancelled   { background: #fee2e2; color: #991b1b; }
    .ts-rescheduled { background: #fae8ff; color: #7e22ce; }
    .ts-default     { background: #f1f5f9; color: #64748b; }

    /* ── Count links in table ─────────────────────────────── */
    .count-link {
      font-weight: 700; color: #0077b6; text-decoration: none;
      font-size: .82rem;
    }
    .count-link:hover { text-decoration: underline; color: #005a8d; }
    .count-zero { color: #cbd5e1; font-size: .82rem; }

    /* ── DataTable overrides ──────────────────────────────── */
    .dataTables_wrapper .dataTables_filter input {
      border: 1.5px solid #dce4ef; border-radius: 6px;
      font-size: .8rem; padding: .28rem .7rem; height: auto;
    }
    .dataTables_wrapper .dataTables_filter input:focus { border-color: #0077b6; outline: none; }
    .dataTables_wrapper .dataTables_length select {
      border: 1.5px solid #dce4ef; border-radius: 6px; font-size: .8rem; padding: .28rem .5rem;
    }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length { font-size: .78rem; color: #94a3b8; }
    table.dataTable thead th {
      background: #f8faff; font-size: .72rem; font-weight: 700;
      color: #475569; text-transform: uppercase; letter-spacing: .5px;
      border-bottom: 1.5px solid #ebeef4 !important; padding: .55rem .6rem;
    }
    table.dataTable tbody td { font-size: .8rem; vertical-align: middle; padding: .5rem .6rem; }
    table.dataTable tbody tr:hover td { background: #f8fbff; }

    /* ── Cancellation badge on button ─────────────────────── */
    .btn-with-badge { position: relative; }
    .btn-notify-dot {
      position: absolute; top: -5px; right: -5px;
      background: #ef4444; color: #fff; border-radius: 10px;
      font-size: .58rem; font-weight: 700; min-width: 16px; height: 16px;
      line-height: 16px; text-align: center; padding: 0 3px; pointer-events: none;
    }

    /* ── Modal: chart ─────────────────────────────────────── */
    #statisticsModal .modal-header {
      background: #012970;
      color: #fff; border: none;
    }
    #statisticsModal .modal-title { font-size: .88rem; font-weight: 700; }
    #statisticsModal .btn-close { filter: invert(1) brightness(2); }
    #statisticsModal .modal-content { border: none; border-radius: 12px; overflow: hidden; }

    @media (max-width: 767px) {
      .office-stat-strip .osp { min-width: calc(50% - .3rem); }
      .seg-tabs { width: 100%; }
      .seg-tab  { flex: 1; justify-content: center; }
      .card-top-bar { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

  <!-- ── PAGE TITLE ─────────────────────────────────────────────── -->
  <div class="pagetitle">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="d-flex align-items-center justify-content-center rounded-2"
             style="width:36px;height:36px;background:#dbeafe;flex-shrink:0;">
          <i class="bi bi-journal-text" style="color:#1d4ed8;font-size:.95rem;"></i>
        </div>
        <div>
          <h1 class="mb-0">Trainings &amp; Participants</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="../dashboard">Home</a></li>
              <li class="breadcrumb-item"><?php echo htmlspecialchars($breadcrumbText); ?></li>
              <li class="breadcrumb-item active">Training List</li>
            </ol>
          </nav>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($location): ?>
        <span class="location-badge">
          <i class="bi bi-geo-alt-fill"></i> <?php echo htmlspecialchars($pageTitle); ?>
        </span>
        <?php endif; ?>
        <a href="../dashboard" class="btn-nav-back">
          <i class="bi bi-arrow-left-circle"></i> Dashboard
        </a>
      </div>
    </div>
  </div>
  <!-- ── END PAGE TITLE ─────────────────────────────────────────── -->

  <?php if ($location && $sessionType !== 'hrd'): ?>
  <!-- ── OFFICE STAT STRIP ────────────────────────────────────── -->
  <div class="office-stat-strip">
    <div class="osp">
      <div class="osp-icon" style="background:#dcfce7;"><i class="bi bi-check-circle-fill" style="color:#15803d;"></i></div>
      <div>
        <div class="osp-val" style="color:#15803d;"><?php echo (int)$officeSummary['paid']; ?></div>
        <div class="osp-lbl">Paid</div>
      </div>
    </div>
    <div class="osp">
      <div class="osp-icon" style="background:#fef9c3;"><i class="bi bi-hourglass-split" style="color:#92400e;"></i></div>
      <div>
        <div class="osp-val" style="color:#92400e;"><?php echo (int)$officeSummary['pending']; ?></div>
        <div class="osp-lbl">Pending</div>
      </div>
    </div>
    <div class="osp">
      <div class="osp-icon" style="background:#fce7f3;"><i class="bi bi-journal-text" style="color:#9d174d;"></i></div>
      <div>
        <div class="osp-val" style="color:#9d174d;"><?php echo (int)$officeSummary['promissory']; ?></div>
        <div class="osp-lbl">Promissory</div>
      </div>
    </div>
    <div class="osp">
      <div class="osp-icon" style="background:#fee2e2;"><i class="bi bi-x-circle-fill" style="color:#991b1b;"></i></div>
      <div>
        <div class="osp-val" style="color:#991b1b;"><?php echo (int)$officeSummary['cancelled']; ?></div>
        <div class="osp-lbl">Cancelled</div>
      </div>
    </div>
    <div class="osp" style="background:#f8fbff;border-color:#bfdbfe;">
      <div class="osp-icon" style="background:#dbeafe;"><i class="bi bi-people-fill" style="color:#1d4ed8;"></i></div>
      <div>
        <div class="osp-val" style="color:#1d4ed8;"><?php echo (int)$officeSummary['total']; ?></div>
        <div class="osp-lbl">Total</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── MAIN TABLE CARD ──────────────────────────────────────── -->
  <section class="section">
    <div class="main-card">

      <!-- Tab + Toolbar bar -->
      <div class="card-top-bar">

        <!-- Segmented tab pills -->
        <div class="seg-tabs" id="trainingTabs" role="tablist">
          <button class="seg-tab active" id="tab-upcoming"
                  data-bs-toggle="tab" data-bs-target="#upcoming" type="button" role="tab">
            <span class="tab-dot" style="background:#3b82f6;"></span>
            Upcoming
            <span class="tab-count"><?php echo $tabCounts['upcoming']; ?></span>
          </button>
          <button class="seg-tab" id="tab-ongoing"
                  data-bs-toggle="tab" data-bs-target="#ongoing" type="button" role="tab">
            <span class="tab-dot" style="background:#f59e0b;"></span>
            Ongoing
            <span class="tab-count"><?php echo $tabCounts['ongoing']; ?></span>
          </button>
          <button class="seg-tab" id="tab-completed"
                  data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab">
            <span class="tab-dot" style="background:#22c55e;"></span>
            Completed
            <span class="tab-count"><?php echo $tabCounts['completed']; ?></span>
          </button>
          <button class="seg-tab" id="tab-cancelled"
                  data-bs-toggle="tab" data-bs-target="#cancelled" type="button" role="tab">
            <span class="tab-dot" style="background:#ef4444;"></span>
            Cancelled
            <span class="tab-count"><?php echo $tabCounts['cancelled']; ?></span>
          </button>
        </div>

        <!-- Toolbar actions -->
        <div class="top-actions">
          <button id="exportButton" class="btn-f btn-f-success" style="font-size:.76rem;padding:.28rem .7rem;">
            <i class="bi bi-file-earmark-excel"></i> Export
          </button>
          <a href="cancellation_requests.php?location=<?php echo urlencode($location); ?>"
             class="btn-f btn-f-warning btn-with-badge" style="font-size:.76rem;padding:.28rem .7rem;">
            <i class="bi bi-clipboard-x-fill"></i> Cancellations
            <?php if ($pendingCancelCount > 0): ?>
            <span class="btn-notify-dot"><?php echo $pendingCancelCount; ?></span>
            <?php endif; ?>
          </a>
          <?php if ($sessionType === 'hrd'): ?>
          <a href="hrd/email_management"
             class="btn-f" style="font-size:.76rem;padding:.28rem .7rem;background:#0891b2;color:#fff;"
             title="Manage Email Templates">
            <i class="bi bi-envelope-paper-fill"></i> Email Templates
          </a>
          <?php endif; ?>
        </div>

      </div><!-- /.card-top-bar -->

      <!-- Tab content -->
      <div class="table-wrap tab-content" id="trainingTabsContent">
        <?php
        $tabs = [
            'upcoming'  => ['status' => 'upcoming',  'tableId' => 'trainingTable',  'active' => true],
            'ongoing'   => ['status' => 'ongoing',   'tableId' => 'ongoingTable',   'active' => false],
            'completed' => ['status' => 'completed', 'tableId' => 'completedTable', 'active' => false],
            'cancelled' => ['status' => 'cancelled', 'tableId' => 'cancelledTable', 'active' => false],
        ];
        foreach ($tabs as $key => $tab):
            $cls = $tab['active'] ? 'show active' : '';
        ?>
        <div class="tab-pane fade <?php echo $cls; ?>" id="<?php echo $key; ?>" role="tabpanel">
          <div class="table-responsive">
            <table id="<?php echo $tab['tableId']; ?>"
                   class="table table-bordered table-hover table-striped w-100"
                   style="font-size:13px;">
              <thead>
                <tr>
                  <th rowspan="2" class="align-middle text-center" style="width:36px;">#</th>
                  <th rowspan="2" class="align-middle">Training Name</th>
                  <th rowspan="2" class="align-middle text-center">Date</th>
                  <th rowspan="2" class="align-middle text-center">Location</th>
                  <th rowspan="2" class="align-middle text-center">Mode</th>
                  <th rowspan="2" class="align-middle text-center">Slots</th>
                  <th rowspan="2" class="align-middle text-center">Status</th>
                  <th colspan="6" class="align-middle text-center" style="background:#f0f4ff;">Participants</th>
                  <th rowspan="2" class="align-middle text-center">Action</th>
                </tr>
                <tr>
                  <th class="text-center">Reg.</th>
                  <th class="text-center">Paid</th>
                  <th class="text-center">Promis.</th>
                  <th class="text-center">Pending</th>
                  <th class="text-center">Free</th>
                  <th class="text-center">Cancelled</th>
                </tr>
              </thead>
              <tbody>
                <?php renderUserTrainingRows($tab['status'], $location); ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>
      </div><!-- /.table-wrap -->

    </div><!-- /.main-card -->
  </section>

</main>

<?php imis_include('footer_db') ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
  <i class="bi bi-arrow-up-short"></i>
</a>

<!-- Chart Modal -->
<div class="modal fade" id="statisticsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title" id="statisticsModalLabel"></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3">
        <div id="chart"></div>
      </div>
    </div>
  </div>
</div>

<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/apexcharts/3.40.0/apexcharts.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(function () {
  // ── DataTables ──────────────────────────────────────────────────
  function exportFilename() {
    const loc = new URLSearchParams(window.location.search).get('location') || 'hrd';
    const d = new Date();
    return `${loc}_trainings_${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}-${d.getFullYear()}`;
  }

  const tables = $('#trainingTable, #ongoingTable, #completedTable, #cancelledTable').DataTable({
    dom: "<'row align-items-center mb-2'<'col-auto'l><'col'f>>" +
         "<'row'<'col-12'tr>>"                                   +
         "<'row align-items-center mt-2'<'col-sm-5'i><'col-sm-7'p>>",
    buttons: [{ extend: 'excel', text: 'Excel', filename: exportFilename,
                exportOptions: { columns: ':not(:last-child)' } }],
    language: {
      search: '', searchPlaceholder: 'Search trainings…',
      lengthMenu: 'Show _MENU_',
      emptyTable: 'No trainings found.',
      zeroRecords: 'No matching trainings.',
      paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' }
    },
    lengthMenu: [10, 25, 50, 100],
    pageLength: 10,
  });

  tables.buttons('.buttons-excel').container().hide();

  // Redraw on tab switch
  document.querySelectorAll('.seg-tab').forEach(btn => {
    btn.addEventListener('shown.bs.tab', () => {
      const paneId = btn.getAttribute('data-bs-target').substring(1);
      const tbl = document.querySelector('#' + paneId + ' table');
      if (tbl) $(tbl).DataTable().columns.adjust().draw();
    });
  });

  // Export button targets active tab's table
  $('#exportButton').on('click', function () {
    const id = $('.tab-pane.active table').attr('id');
    if (id) $('#' + id).DataTable().button('.buttons-excel').trigger();
  });

  // ── ApexCharts pie ──────────────────────────────────────────────
  let chartInst = null;

  $(document).on('click', '.view-graph-btn', function () {
    const btn      = $(this);
    const paid     = parseInt(btn.data('training-count'))   || 0;
    const pending  = parseInt(btn.data('pending-count'))    || 0;
    const promiss  = parseInt(btn.data('promissory-count')) || 0;

    if (chartInst) { chartInst.destroy(); chartInst = null; }

    chartInst = new ApexCharts(document.querySelector('#chart'), {
      chart: { height: 320, type: 'pie', fontFamily: 'Poppins, sans-serif' },
      labels: ['Paid', 'Pending', 'Promissory Note'],
      series: [paid, pending, promiss],
      colors: ['#16a34a', '#3b82f6', '#f59e0b'],
      legend: { position: 'bottom', fontSize: '12px' },
      tooltip: {
        y: { formatter: val => {
          const tot = paid + pending + promiss;
          return `${val} (${tot > 0 ? ((val/tot)*100).toFixed(1) : 0}%)`;
        }}
      },
      dataLabels: { style: { fontSize: '12px' } },
    });
    chartInst.render();

    $('#statisticsModalLabel').html(
      `<span style="font-weight:700;">${btn.data('training-name')}</span>
       <span style="font-weight:400;opacity:.7;font-size:.8rem;margin-left:.5rem;">— ${btn.data('training-date')}</span>`
    );
    $('#statisticsModal').modal('show');
  });

  // Cleanup chart on modal close
  $('#statisticsModal').on('hidden.bs.modal', function () {
    if (chartInst) { chartInst.destroy(); chartInst = null; }
    document.getElementById('chart').innerHTML = '';
  });

  // ── Disabled participant-list link (cancelled training, or none registered yet) ──
  $(document).on('click', '.participants-link.disabled', function (e) {
    e.preventDefault();
    Swal.fire({
      icon: 'warning',
      title: 'List Not Available',
      text: 'The participant list isn\'t accessible for this training — it\'s either cancelled or has no participants yet.',
      confirmButtonColor: '#0077b6',
    });
  });
});
</script>
</body>
</html>
