<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$name     = $_SESSION['name']     ?? $_SESSION['username'] ?? '—';
$position = $_SESSION['position'] ?? '—';
$fo_rsu   = $_SESSION['fo_rsu']   ?? '—';

$loginSuccess = false;
if (!empty($_SESSION['login_success'])) {
    $loginSuccess = true;
    unset($_SESSION['login_success']);
}

// Auto-complete overdue trainings (at most once every 5 minutes)
$lockFile = sys_get_temp_dir() . '/otrs_auto_complete.lock';
if (!file_exists($lockFile) || (time() - filemtime($lockFile)) > 300) {
    try {
        $conn->prepare(
            "UPDATE otrs_trainings
             SET training_status = 'completed'
             WHERE end_date IS NOT NULL AND end_date <> '0000-00-00'
               AND end_date < CURDATE()
               AND LOWER(training_status) NOT IN ('completed','cancelled','rescheduled')"
        )->execute();
    } catch (PDOException $e) { error_log('[auto_complete] ' . $e->getMessage()); }
    touch($lockFile);
}

// Dashboard stats — single query batch
try {
    $stats = $conn->query("
        SELECT
            SUM(status IN ('active','inactive','draft') AND training_status != 'cancelled')            AS total_trainings,
            SUM(training_status IN ('Upcoming','Ongoing','upcoming','ongoing'))                         AS active_trainings,
            SUM(LOWER(training_status) = 'completed')                                                  AS completed_trainings
        FROM otrs_trainings
    ")->fetch(PDO::FETCH_ASSOC);

    $pendingPayments      = (int) $conn->query("SELECT COUNT(*) FROM otrs_registrations WHERE payment_status = 'pending' AND (proof_file IS NULL OR proof_file = '')")->fetchColumn();
    $pendingCancellations = (int) $conn->query("SELECT COUNT(*) FROM otrs_cancellation_requests WHERE status = 'pending'")->fetchColumn();
    $replacementsMonth    = (int) $conn->query("SELECT COUNT(*) FROM otrs_replacement_requests WHERE MONTH(replaced_at)=MONTH(NOW()) AND YEAR(replaced_at)=YEAR(NOW())")->fetchColumn();
    $evaluationsYear      = (int) $conn->query("SELECT COUNT(*) FROM otrs_evaluations WHERE YEAR(submitted_at)=YEAR(NOW())")->fetchColumn();
    $pendingSubmissions   = (int) $conn->query("SELECT COUNT(*) FROM otrs_training_submissions WHERE validation_status='pending'")->fetchColumn();

    $totalTrainings    = (int)($stats['total_trainings']    ?? 0);
    $activeTrainings   = (int)($stats['active_trainings']   ?? 0);
    $completedTrainings= (int)($stats['completed_trainings'] ?? 0);
} catch (PDOException $e) {
    $totalTrainings = $activeTrainings = $completedTrainings = 0;
    $pendingPayments = $pendingCancellations = $replacementsMonth = $evaluationsYear = $pendingSubmissions = 0;
}

// Inbox items — fetch up to 5 of each for the dashboard action panel
$inboxCancellations = [];
$inboxSubmissions   = [];
try {
    $stmtC = $conn->query("
        SELECT cr.id, cr.reason,
               CONCAT_WS(' ', TRIM(p.fname), NULLIF(CONCAT(LEFT(TRIM(p.mname),1),'.'),'.'), TRIM(p.lname)) AS participant_name,
               t.name AS training_name,
               cr.created_at
        FROM   otrs_cancellation_requests cr
        JOIN   otrs_registrations r ON r.id = cr.enrollment_id
        JOIN   otrs_participants  p ON p.id = cr.participant_id
        JOIN   otrs_trainings     t ON t.id = cr.training_id
        WHERE  cr.status = 'pending'
        ORDER  BY cr.created_at ASC
        LIMIT  5
    ");
    $inboxCancellations = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    $stmtS = $conn->query("
        SELECT ts.id, ts.day_number, ts.submitted_at,
               CONCAT_WS(' ', TRIM(p.fname), NULLIF(CONCAT(LEFT(TRIM(p.mname),1),'.'),'.'), TRIM(p.lname)) AS participant_name,
               t.name AS training_name
        FROM   otrs_training_submissions ts
        JOIN   otrs_registrations r ON r.id = ts.registration_id
        JOIN   otrs_participants  p ON p.id = r.participant_id
        JOIN   otrs_trainings     t ON t.id = ts.training_id
        WHERE  ts.validation_status = 'pending'
        ORDER  BY ts.submitted_at ASC
        LIMIT  5
    ");
    $inboxSubmissions = $stmtS->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[inbox] ' . $e->getMessage());
}

// Total notification badge count for header
$totalAlerts = $pendingPayments + $pendingCancellations + $pendingSubmissions;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OTRS Dashboard — CSC RO VIII</title>

  <link href="../../assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

  <style>
    :root {
      --csc-blue:  #0077b6;
      --csc-navy:  #012970;
      --surface:   #f5f7ff;
    }
    body   { background: var(--surface); }
    .sidebar { display: none; }
    .main    { margin-left: 0 !important; }

    /* ── Hero header strip ───────────────────────────────────────── */
    .dash-hero {
      background: linear-gradient(135deg, var(--csc-navy) 0%, #1a3a6e 60%, #0077b6 100%);
      border-radius: 14px;
      padding: 1.4rem 1.8rem;
      color: #fff;
      margin-bottom: 1.25rem;
      position: relative;
      overflow: hidden;
    }
    .dash-hero::before {
      content: '';
      position: absolute; right: -40px; top: -40px;
      width: 220px; height: 220px; border-radius: 50%;
      background: rgba(255,255,255,.05);
    }
    .dash-hero::after {
      content: '';
      position: absolute; right: 60px; bottom: -60px;
      width: 150px; height: 150px; border-radius: 50%;
      background: rgba(255,255,255,.04);
    }
    .dash-hero .hero-title  { font-size: 1.15rem; font-weight: 700; margin: 0; }
    .dash-hero .hero-sub    { font-size: .78rem; opacity: .72; margin: .15rem 0 0; }
    .status-ping {
      width: 8px; height: 8px; border-radius: 50%; background: #4ade80; flex-shrink: 0;
      animation: ping 2s infinite;
    }
    @keyframes ping {
      0%,100%{ box-shadow: 0 0 0 0 rgba(74,222,128,.5); }
      70%    { box-shadow: 0 0 0 6px rgba(74,222,128,0); }
    }

    /* ── Stat pills ──────────────────────────────────────────────── */
    .stat-strip {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: .65rem;
      margin-bottom: 1.25rem;
    }
    .stat-pill {
      background: #fff;
      border-radius: 10px;
      padding: .75rem .9rem;
      border: 1.5px solid #ebeef4;
      display: flex; align-items: center; gap: .6rem;
      text-decoration: none; color: inherit;
      transition: transform .18s, box-shadow .18s;
      position: relative; overflow: hidden;
    }
    .stat-pill:hover  { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(1,41,112,.1); }
    .stat-pill .sp-icon {
      width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: .95rem;
    }
    .stat-pill .sp-val  { font-size: 1.35rem; font-weight: 800; line-height: 1; }
    .stat-pill .sp-lbl  { font-size: .62rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; margin-top: 1px; }
    .stat-pill.alert-pill { border-color: #fca5a5; }
    .stat-pill.alert-pill .sp-val { color: #dc2626; }
    .stat-pill .sp-dot {
      position: absolute; top: 8px; right: 8px;
      width: 7px; height: 7px; border-radius: 50%; background: #ef4444;
    }

    /* ── Module grid ─────────────────────────────────────────────── */
    .mod-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: .6rem;
      margin-bottom: 1rem;
    }
    .mod-item {
      background: #fff; border-radius: 10px;
      border: 1.5px solid #ebeef4;
      padding: .75rem .85rem;
      display: flex; align-items: center; gap: .65rem;
      text-decoration: none; color: var(--csc-navy);
      transition: transform .18s, box-shadow .18s, border-color .15s;
      cursor: pointer; position: relative;
    }
    .mod-item:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(1,41,112,.1);
      border-color: #bfdbfe;
      color: var(--csc-navy);
    }
    .mod-item:active { transform: translateY(0); }
    .mod-item .mi-icon {
      width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem; color: #fff;
    }
    .mod-item .mi-name  { font-size: .78rem; font-weight: 600; line-height: 1.3; flex: 1; }
    .mod-item .mi-badge {
      font-size: .6rem; font-weight: 700; padding: .15rem .4rem;
      border-radius: 20px; white-space: nowrap; flex-shrink: 0;
    }

    /* Section dividers */
    .mod-section-label {
      font-size: .65rem; font-weight: 700; letter-spacing: 1.8px; color: #94a3b8;
      text-transform: uppercase; margin-bottom: .5rem; margin-top: .3rem;
      display: flex; align-items: center; gap: .4rem;
    }
    .mod-section-label::after {
      content: ''; flex: 1; height: 1px; background: #ebeef4;
    }

    /* ── Sidebar panels ──────────────────────────────────────────── */
    .info-panel {
      background: #fff; border-radius: 12px;
      border: 1.5px solid #ebeef4; overflow: hidden;
      margin-bottom: .85rem;
    }
    .info-panel-head {
      padding: .65rem 1rem; font-size: .72rem; font-weight: 700;
      letter-spacing: .4px; text-transform: uppercase; color: #fff;
      display: flex; align-items: center; gap: .45rem;
    }
    .info-panel-body { padding: .9rem 1rem; }
    .info-row {
      display: flex; align-items: center; justify-content: space-between;
      font-size: .78rem; padding: .38rem 0;
      border-bottom: 1px solid #f1f5f9;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .lbl { color: #94a3b8; font-weight: 500; }
    .info-row .val { font-weight: 600; color: var(--csc-navy); text-align: right; max-width: 58%; }

    /* Alert rows */
    .alert-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: .5rem 0; border-bottom: 1px solid #f1f5f9; font-size: .78rem;
    }
    .alert-row:last-child { border-bottom: none; }
    .alert-row .ar-icon {
      width: 30px; height: 30px; border-radius: 7px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .alert-row .ar-count { font-size: 1.1rem; font-weight: 800; line-height: 1; }
    .alert-row .ar-lbl   { font-size: .68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; }
    .alert-row a { font-size: .72rem; font-weight: 600; text-decoration: none; color: var(--csc-blue); }
    .alert-row a:hover { text-decoration: underline; }

    /* Portal quick links */
    .portal-link {
      display: flex; align-items: center; gap: .6rem;
      padding: .55rem .75rem; border-radius: 8px; border: 1.5px solid #e0f2fe;
      background: #f0f9ff; text-decoration: none; color: var(--csc-navy);
      font-size: .78rem; font-weight: 600; margin-bottom: .5rem;
      transition: background .15s, border-color .15s;
    }
    .portal-link:hover { background: #dbeafe; border-color: #93c5fd; color: var(--csc-navy); }
    .portal-link:last-child { margin-bottom: 0; }
    .portal-link .pl-icon { width: 28px; height: 28px; border-radius: 6px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: .85rem; color: #fff; }

    /* Backup button (styled as mod-item) */
    .mod-item-btn { background: none; border: 1.5px solid #ebeef4; width: 100%; text-align: left; }

    /* Back button */
    .btn-back-dash {
      display: inline-flex; align-items: center; gap: .4rem;
      background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3);
      color: #fff; font-size: .78rem; font-weight: 600; padding: .42rem .85rem;
      border-radius: 6px; text-decoration: none; transition: background .15s;
    }
    .btn-back-dash:hover { background: rgba(255,255,255,.28); color: #fff; }

    @media (max-width: 1199px) {
      .stat-strip { grid-template-columns: repeat(4, 1fr); }
    }
    @media (max-width: 767px) {
      .stat-strip { grid-template-columns: repeat(2, 1fr); }
      .mod-grid   { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 479px) {
      .stat-strip { grid-template-columns: repeat(2, 1fr); }
      .mod-grid   { grid-template-columns: 1fr 1fr; }
    }
    /* ── Pending Actions Inbox ── */
    .inbox-item {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: .5rem; padding: .4rem .5rem; margin-bottom: .3rem;
      background: #fff; border: 1px solid #e2e8f0; border-radius: 7px;
      transition: background .15s;
    }
    .inbox-item:last-child { margin-bottom: 0; }
    .inbox-item.removing   { opacity: 0; transform: translateX(20px); transition: all .3s ease; }
    .inbox-item-info       { flex: 1; min-width: 0; }
    .inbox-item-name       { font-size: .75rem; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .inbox-item-sub        { font-size: .68rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .inbox-item-reason     { font-size: .67rem; color: #94a3b8; font-style: italic; margin-top: .1rem; }
    .inbox-item-actions    { display: flex; gap: .25rem; flex-shrink: 0; }
    .inbox-btn {
      width: 26px; height: 26px; border-radius: 50%; border: none;
      display: flex; align-items: center; justify-content: center;
      font-size: .75rem; cursor: pointer; transition: all .15s;
    }
    .inbox-approve { background: #d1fae5; color: #065f46; }
    .inbox-approve:hover { background: #059669; color: #fff; }
    .inbox-reject  { background: #fee2e2; color: #991b1b; }
    .inbox-reject:hover  { background: #dc2626; color: #fff; }
  </style>
</head>
<body>

<?php imis_include('header') ?>

<main id="main" class="main">

  <!-- ── HERO HEADER ──────────────────────────────────────────────── -->
  <div class="dash-hero mb-0">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div style="z-index:1;">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-easel2" style="font-size:1.1rem;opacity:.85;"></i>
          <p class="hero-title">Online Training Registration System</p>
        </div>
        <p class="hero-sub">
          <i class="bi bi-calendar3 me-1"></i><span id="currentDate"></span>
          &nbsp;·&nbsp; HRD Administration Panel
        </p>
      </div>
      <div class="d-flex align-items-center gap-2" style="z-index:1;">
        <a href="../../dashboard" class="btn-back-dash">
          <i class="bi bi-arrow-left-circle"></i> Dashboard
        </a>
      </div>
    </div>
  </div>

  <section class="section pt-3">

    <!-- ── STAT STRIP ───────────────────────────────────────────────── -->
    <div class="stat-strip">

      <a href="index_trainings" class="stat-pill">
        <div class="sp-icon" style="background:#fffbeb;"><i class="bi bi-journals" style="color:#d97706;"></i></div>
        <div>
          <div class="sp-val" style="color:#d97706;"><?php echo $totalTrainings; ?></div>
          <div class="sp-lbl">Total</div>
        </div>
      </a>

      <a href="index_manage_trainings" class="stat-pill">
        <div class="sp-icon" style="background:#eff6ff;"><i class="bi bi-calendar-event" style="color:#3b82f6;"></i></div>
        <div>
          <div class="sp-val" style="color:#3b82f6;"><?php echo $activeTrainings; ?></div>
          <div class="sp-lbl">Active</div>
        </div>
      </a>

      <a href="index_trainings" class="stat-pill">
        <div class="sp-icon" style="background:#f0fdf4;"><i class="bi bi-patch-check" style="color:#16a34a;"></i></div>
        <div>
          <div class="sp-val" style="color:#16a34a;"><?php echo $completedTrainings; ?></div>
          <div class="sp-lbl">Completed</div>
        </div>
      </a>

      <a href="replacement_requests" class="stat-pill">
        <div class="sp-icon" style="background:#fff3e0;"><i class="bi bi-arrow-left-right" style="color:#d97706;"></i></div>
        <div>
          <div class="sp-val" style="color:#d97706;"><?php echo $replacementsMonth; ?></div>
          <div class="sp-lbl">Replacements</div>
        </div>
      </a>

      <a href="cancellation_requests" class="stat-pill <?php echo $pendingCancellations > 0 ? 'alert-pill' : ''; ?>">
        <?php if ($pendingCancellations > 0): ?><div class="sp-dot"></div><?php endif; ?>
        <div class="sp-icon" style="background:#fff7ed;"><i class="bi bi-x-circle" style="color:#ea580c;"></i></div>
        <div>
          <div class="sp-val" style="<?php echo $pendingCancellations > 0 ? 'color:#dc2626' : ''; ?>"><?php echo $pendingCancellations; ?></div>
          <div class="sp-lbl">Cancels</div>
        </div>
      </a>

      <a href="submission_review" class="stat-pill <?php echo $pendingSubmissions > 0 ? 'alert-pill' : ''; ?>">
        <?php if ($pendingSubmissions > 0): ?><div class="sp-dot"></div><?php endif; ?>
        <div class="sp-icon" style="background:#f0f9ff;"><i class="bi bi-card-checklist" style="color:#0369a1;"></i></div>
        <div>
          <div class="sp-val" style="<?php echo $pendingSubmissions > 0 ? 'color:#dc2626' : ''; ?>"><?php echo $pendingSubmissions; ?></div>
          <div class="sp-lbl">Submissions</div>
        </div>
      </a>

      <a href="evaluation_results" class="stat-pill">
        <div class="sp-icon" style="background:#faf5ff;"><i class="bi bi-bar-chart-line" style="color:#7c3aed;"></i></div>
        <div>
          <div class="sp-val" style="color:#7c3aed;" id="statEval"><?php echo $evaluationsYear; ?></div>
          <div class="sp-lbl">Evals <?php echo date('Y'); ?></div>
        </div>
      </a>

    </div>
    <!-- ── END STAT STRIP ─────────────────────────────────────────── -->

    <div class="row g-3">

      <!-- ════ LEFT — Module Grid ═══════════════════════════════════ -->
      <div class="col-xl-8 col-lg-7">

        <!-- CORE MANAGEMENT -->
        <p class="mod-section-label"><i class="bi bi-grid-3x3-gap-fill"></i> Core Management</p>
        <div class="mod-grid mb-3">

          <a href="index_trainings" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0077b6,#0096c7);">
              <i class="bi bi-journals"></i>
            </div>
            <span class="mi-name">Trainings &amp; Participants</span>
          </a>

          <a href="index_manage_trainings" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#059669,#10b981);">
              <i class="bi bi-easel2"></i>
            </div>
            <span class="mi-name">Manage Trainings</span>
          </a>

          <a href="hrd_calendar" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#7c3aed,#9333ea);">
              <i class="bi bi-calendar2-week-fill"></i>
            </div>
            <span class="mi-name">HRD Calendar</span>
          </a>

          <a href="index_participants" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#ea580c,#f97316);">
              <i class="bi bi-person-lines-fill"></i>
            </div>
            <span class="mi-name">Manage Participants</span>
          </a>

          <a href="index_co" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0891b2,#06b6d4);">
              <i class="bi bi-person-vcard"></i>
            </div>
            <span class="mi-name">Collecting Officer</span>
          </a>

        </div>

        <!-- CERTIFICATES -->
        <p class="mod-section-label"><i class="bi bi-award-fill"></i> Certificates</p>
        <div class="mod-grid mb-3">

          <a href="ccs_dashboard" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#5c0099,#7b00cc);">
              <i class="bi bi-collection-fill"></i>
            </div>
            <span class="mi-name">Certificate Repository</span>
          </a>

          <a href="index_trainings" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#7b00cc,#a855f7);">
              <i class="bi bi-patch-check-fill"></i>
            </div>
            <span class="mi-name">Generate Certificates</span>
          </a>

          <a href="agency_certificates" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);">
              <i class="bi bi-building-fill-check"></i>
            </div>
            <span class="mi-name">Agency-Requested</span>
          </a>

          <a href="appearance_certificates" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0d9488,#14b8a6);">
              <i class="bi bi-person-badge-fill"></i>
            </div>
            <span class="mi-name">Certificate of Appearance</span>
          </a>

        </div>

        <!-- MONITORING & REQUESTS -->
        <p class="mod-section-label"><i class="bi bi-clipboard2-pulse-fill"></i> Monitoring &amp; Requests</p>
        <div class="mod-grid mb-3">

          <a href="cancellation_requests" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#b91c1c,#dc2626);">
              <i class="bi bi-x-circle-fill"></i>
            </div>
            <span class="mi-name">Cancellation Requests</span>
            <?php if ($pendingCancellations > 0): ?>
            <span class="mi-badge" style="background:#fee2e2;color:#b91c1c;"><?php echo $pendingCancellations; ?></span>
            <?php endif; ?>
          </a>

          <a href="replacement_requests" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#b45309,#d97706);">
              <i class="bi bi-arrow-left-right"></i>
            </div>
            <span class="mi-name">Replacements</span>
            <?php if ($replacementsMonth > 0): ?>
            <span class="mi-badge" style="background:#fef3c7;color:#92400e;"><?php echo $replacementsMonth; ?> mo</span>
            <?php endif; ?>
          </a>

          <a href="pending_payments" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
              <i class="bi bi-receipt"></i>
            </div>
            <span class="mi-name">Pending Payments</span>
            <?php if ($pendingPayments > 0): ?>
            <span class="mi-badge" style="background:#fee2e2;color:#b91c1c;"><?php echo $pendingPayments; ?></span>
            <?php endif; ?>
          </a>

          <a href="submission_review" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0369a1,#0284c7);">
              <i class="bi bi-card-checklist"></i>
            </div>
            <span class="mi-name">Submission Review</span>
            <?php if ($pendingSubmissions > 0): ?>
            <span class="mi-badge" style="background:#dbeafe;color:#1e40af;"><?php echo $pendingSubmissions; ?></span>
            <?php endif; ?>
          </a>

          <a href="rescheduled_trainings" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
              <i class="bi bi-calendar-event"></i>
            </div>
            <span class="mi-name">Rescheduled Trainings</span>
          </a>

          <a href="payment_logs" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#92400e,#b45309);">
              <i class="bi bi-clock-history"></i>
            </div>
            <span class="mi-name">Payment Audit Log</span>
          </a>

        </div>

        <!-- ANALYTICS, REPORTS & TOOLS -->
        <p class="mod-section-label"><i class="bi bi-graph-up-arrow"></i> Analytics, Reports &amp; Tools</p>
        <div class="mod-grid">

          <a href="evaluation_results" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);">
              <i class="bi bi-bar-chart-line-fill"></i>
            </div>
            <span class="mi-name">Evaluation Results</span>
          </a>

          <a href="sme_management" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0891b2,#0369a1);">
              <i class="bi bi-person-video3"></i>
            </div>
            <span class="mi-name">SME Management</span>
          </a>

          <a href="annual_report" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#065f46,#059669);">
              <i class="bi bi-file-earmark-bar-graph-fill"></i>
            </div>
            <span class="mi-name">Annual Report</span>
          </a>

          <a href="cert_verification_log" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#166534,#16a34a);">
              <i class="bi bi-shield-check"></i>
            </div>
            <span class="mi-name">Cert Verification Log</span>
          </a>

          <a href="fo-breakdown" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0f4c81,#1a6baa);">
              <i class="bi bi-diagram-3-fill"></i>
            </div>
            <span class="mi-name">FO Breakdown</span>
          </a>

          <a href="email_management" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#0e7490,#0891b2);">
              <i class="bi bi-envelope-at-fill"></i>
            </div>
            <span class="mi-name">Email Templates</span>
          </a>

          <a href="audit_log" class="mod-item">
            <div class="mi-icon" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
              <i class="bi bi-journal-text"></i>
            </div>
            <span class="mi-name">Audit Log</span>
          </a>

          <button type="button" class="mod-item mod-item-btn" id="backupBtn">
            <div class="mi-icon" style="background:linear-gradient(135deg,#374151,#4b5563);">
              <i class="bi bi-hdd-stack"></i>
            </div>
            <span class="mi-name">Backup Database</span>
          </button>

        </div>

      </div>
      <!-- ── END LEFT ────────────────────────────────────────────── -->

      <!-- ════ RIGHT — Info Panels ══════════════════════════════════ -->
      <div class="col-xl-4 col-lg-5">

        <!-- System / User Info -->
        <div class="info-panel">
          <div class="info-panel-head" style="background:linear-gradient(135deg,var(--csc-navy),#1a3a6e);">
            <i class="bi bi-person-badge-fill"></i> Session Info
          </div>
          <div class="info-panel-body">
            <div class="info-row">
              <span class="lbl">Status</span>
              <span style="display:inline-flex;align-items:center;gap:.35rem;font-size:.78rem;font-weight:600;color:#16a34a;">
                <span class="status-ping" style="width:7px;height:7px;background:#4ade80;"></span> Active
              </span>
            </div>
            <div class="info-row">
              <span class="lbl">Logged in as</span>
              <span class="val"><?php echo htmlspecialchars($name); ?></span>
            </div>
            <div class="info-row">
              <span class="lbl">Position</span>
              <span class="val"><?php echo htmlspecialchars($position); ?></span>
            </div>
            <div class="info-row">
              <span class="lbl">Office</span>
              <span class="val"><?php echo htmlspecialchars($fo_rsu); ?></span>
            </div>
            <div class="info-row">
              <span class="lbl">Total Participants</span>
              <span class="val" id="totalPax" style="color:var(--csc-blue);">—</span>
            </div>
          </div>
        </div>

        <!-- Pending Actions Inbox -->
        <?php if ($totalAlerts > 0): ?>
        <div class="info-panel">
          <div class="info-panel-head" style="background:linear-gradient(135deg,#b91c1c,#dc2626);">
            <i class="bi bi-inbox-fill"></i> Pending Actions
            <span style="background:rgba(255,255,255,.25);border-radius:20px;padding:.05rem .45rem;font-size:.7rem;margin-left:auto;">
              <?php echo $totalAlerts; ?>
            </span>
          </div>
          <div class="info-panel-body" style="padding:.5rem .8rem;">

            <?php if ($pendingPayments > 0): ?>
            <!-- Payments — no direct action, link to review page -->
            <div style="margin-bottom:.6rem;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
                <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#dc2626;">
                  <i class="bi bi-receipt me-1"></i>Pending Payments (<?php echo $pendingPayments; ?>)
                </span>
                <a href="pending_payments" style="font-size:.7rem;font-weight:600;color:#dc2626;text-decoration:none;">View All →</a>
              </div>
              <div style="font-size:.73rem;color:#64748b;padding:.3rem .5rem;background:#fff5f5;border-radius:6px;border-left:3px solid #dc2626;">
                <?php echo $pendingPayments; ?> participant<?php echo $pendingPayments !== 1 ? 's' : ''; ?> awaiting payment confirmation.
              </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($inboxCancellations)): ?>
            <!-- Cancellations -->
            <div style="margin-bottom:.6rem;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
                <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#ea580c;">
                  <i class="bi bi-x-circle me-1"></i>Cancellations (<?php echo $pendingCancellations; ?>)
                </span>
                <?php if ($pendingCancellations > 5): ?>
                <a href="cancellation_requests" style="font-size:.7rem;font-weight:600;color:#ea580c;text-decoration:none;">View All →</a>
                <?php endif; ?>
              </div>
              <?php foreach ($inboxCancellations as $cr): ?>
              <div class="inbox-item" data-id="<?php echo (int)$cr['id']; ?>" data-type="cancellation">
                <div class="inbox-item-info">
                  <div class="inbox-item-name"><?php echo htmlspecialchars($cr['participant_name']); ?></div>
                  <div class="inbox-item-sub"><?php echo htmlspecialchars($cr['training_name']); ?></div>
                  <?php if (!empty($cr['reason'])): ?>
                  <div class="inbox-item-reason">"<?php echo htmlspecialchars(mb_strimwidth($cr['reason'], 0, 60, '…')); ?>"</div>
                  <?php endif; ?>
                </div>
                <div class="inbox-item-actions">
                  <button class="inbox-btn inbox-approve" data-id="<?php echo (int)$cr['id']; ?>" data-type="cancellation" title="Approve">
                    <i class="bi bi-check-lg"></i>
                  </button>
                  <button class="inbox-btn inbox-reject" data-id="<?php echo (int)$cr['id']; ?>" data-type="cancellation" title="Reject">
                    <i class="bi bi-x-lg"></i>
                  </button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($inboxSubmissions)): ?>
            <!-- Submissions -->
            <div>
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
                <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#1d4ed8;">
                  <i class="bi bi-card-checklist me-1"></i>Submissions (<?php echo $pendingSubmissions; ?>)
                </span>
                <?php if ($pendingSubmissions > 5): ?>
                <a href="submission_review" style="font-size:.7rem;font-weight:600;color:#1d4ed8;text-decoration:none;">View All →</a>
                <?php endif; ?>
              </div>
              <?php foreach ($inboxSubmissions as $sub): ?>
              <div class="inbox-item" data-id="<?php echo (int)$sub['id']; ?>" data-type="submission">
                <div class="inbox-item-info">
                  <div class="inbox-item-name"><?php echo htmlspecialchars($sub['participant_name']); ?></div>
                  <div class="inbox-item-sub"><?php echo htmlspecialchars($sub['training_name']); ?> — Day <?php echo (int)$sub['day_number']; ?></div>
                </div>
                <div class="inbox-item-actions">
                  <button class="inbox-btn inbox-approve" data-id="<?php echo (int)$sub['id']; ?>" data-type="submission" title="Approve">
                    <i class="bi bi-check-lg"></i>
                  </button>
                  <button class="inbox-btn inbox-reject"  data-id="<?php echo (int)$sub['id']; ?>" data-type="submission" title="Reject">
                    <i class="bi bi-x-lg"></i>
                  </button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

          </div>
        </div>
        <?php endif; ?>

        <!-- Public Portal Quick Links -->
        <div class="info-panel">
          <div class="info-panel-head" style="background:linear-gradient(135deg,#0891b2,#0369a1);">
            <i class="bi bi-globe2"></i> Public Portals
          </div>
          <div class="info-panel-body">
            <a href="https://otrs.cscro8.com/attend" target="_blank" class="portal-link">
              <div class="pl-icon" style="background:linear-gradient(135deg,#0891b2,#0369a1);">
                <i class="bi bi-clipboard-check-fill"></i>
              </div>
              <div>
                <div style="font-size:.78rem;font-weight:700;">Attendance &amp; Evaluation</div>
                <div style="font-size:.68rem;color:#64748b;">Public-Offered Online Trainings</div>
              </div>
              <i class="bi bi-box-arrow-up-right ms-auto" style="font-size:.7rem;color:#94a3b8;"></i>
            </a>
            <a href="https://otrs.cscro8.com/claim" target="_blank" class="portal-link">
              <div class="pl-icon" style="background:linear-gradient(135deg,#1acc8d,#17b57d);">
                <i class="bi bi-award-fill"></i>
              </div>
              <div>
                <div style="font-size:.78rem;font-weight:700;">Certificate Claiming</div>
                <div style="font-size:.68rem;color:#64748b;">Free Trainings</div>
              </div>
              <i class="bi bi-box-arrow-up-right ms-auto" style="font-size:.7rem;color:#94a3b8;"></i>
            </a>
          </div>
        </div>

      </div>
      <!-- ── END RIGHT ───────────────────────────────────────────── -->

    </div><!-- /.row -->

  </section>

</main>

<?php imis_include('footer_db') ?>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../../assets/js/main.js"></script>

<script>
document.getElementById('currentDate').textContent = new Date().toLocaleDateString('en-PH', {
  weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
});

// Async: total participants
fetch('api/get_total_participants.php')
  .then(r => r.json())
  .then(d => { if (d.success) document.getElementById('totalPax').textContent = Number(d.total).toLocaleString(); })
  .catch(() => {});

// Backup
document.getElementById('backupBtn').addEventListener('click', () => {
  Swal.fire({
    title: 'Backup Database?',
    text: 'A backup will be created and emailed to the administrator.',
    icon: 'question', showCancelButton: true,
    confirmButtonColor: '#0077b6', cancelButtonColor: '#6c757d',
    confirmButtonText: 'Yes, backup now',
  }).then(r => {
    if (!r.isConfirmed) return;
    Swal.fire({ title: 'Creating backup…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('_csrf', window.csrfToken ?? '');
    fetch('api/backup.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
      .then(r => r.text())
      .then(text => {
        Swal.close();
        if (text.trim() === 'success') {
          Swal.fire({ title: 'Backup Complete!', text: 'Backed up and emailed successfully.', icon: 'success', confirmButtonColor: '#0077b6' });
        } else {
          Swal.fire({ title: 'Backup Failed', text: text || 'An error occurred.', icon: 'error', confirmButtonColor: '#dc3545' });
        }
      })
      .catch(() => Swal.fire({ title: 'Error', text: 'Could not reach backup endpoint.', icon: 'error' }));
  });
});

<?php if ($loginSuccess): ?>
Swal.fire({
  title: 'Welcome back!', text: 'Logged in successfully.',
  icon: 'success', timer: 2500, showConfirmButton: false,
  toast: true, position: 'top-end'
});
<?php endif; ?>

// ── Pending Actions Inbox ──────────────────────────────────────────
const CSRF = <?php echo json_encode($_SESSION['_csrf_token'] ?? ''); ?>;

function inboxAction(btn, action) {
    const id   = btn.dataset.id;
    const type = btn.dataset.type;
    const item = btn.closest('.inbox-item');
    const name = item.querySelector('.inbox-item-name')?.textContent || '';

    const label     = action === 'approve' ? 'Approve' : 'Reject';
    const btnColor  = action === 'approve' ? '#059669' : '#dc2626';

    Swal.fire({
        title: `${label} request?`,
        html:  `<b>${name}</b>`,
        icon:  action === 'approve' ? 'question' : 'warning',
        showCancelButton: true,
        confirmButtonText: label,
        confirmButtonColor: btnColor,
        input: action === 'reject' ? 'textarea' : undefined,
        inputPlaceholder: action === 'reject' ? 'Remarks (optional)…' : undefined,
        inputAttributes: { rows: 2 },
    }).then(async res => {
        if (!res.isConfirmed) return;

        const fd = new FormData();
        fd.append('_csrf', CSRF);

        let url;
        if (type === 'cancellation') {
            url = 'api/process_cancellation.php';
            fd.append('requestId',      id);
            fd.append('action',         action);
            fd.append('review_remarks', res.value || '');
        } else {
            url = 'api/validate_submission.php';
            fd.append('submission_id', id);
            fd.append('status',        action === 'approve' ? 'approved' : 'rejected');
            fd.append('remarks',       res.value || '');
        }

        try {
            const r    = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF } });
            const json = await r.json();
            if (json.status === 'success') {
                item.classList.add('removing');
                setTimeout(() => {
                    item.remove();
                    // If section is now empty, remove its container div
                    document.querySelectorAll('.info-panel-body > div').forEach(section => {
                        if (!section.querySelector('.inbox-item')) {
                            const remaining = section.querySelectorAll('.inbox-item');
                            if (remaining.length === 0 && section.querySelector('.inbox-item-name') === null) {
                                section.remove();
                            }
                        }
                    });
                }, 320);
                if (json.promoted) {
                    Swal.fire({ icon: 'info', title: 'Waitlist Promoted', html: `<b>${json.promoted.name}</b> from the waitlist has been auto-registered.`, timer: 4000, showConfirmButton: false, toast: true, position: 'top-end' });
                } else {
                    Swal.fire({ icon: 'success', title: `${label}d`, timer: 1800, showConfirmButton: false, toast: true, position: 'top-end' });
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: json.message || 'Action failed.' });
            }
        } catch {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' });
        }
    });
}

document.querySelectorAll('.inbox-approve').forEach(btn => btn.addEventListener('click', () => inboxAction(btn, 'approve')));
document.querySelectorAll('.inbox-reject').forEach(btn  => btn.addEventListener('click', () => inboxAction(btn,  'reject')));
</script>
</body>
</html>
