<?php
// ── manage_trainings.php  —  PHP backend section (refactored) ────────────────
//
// Drop-in replacement for the top of the existing page.
// Changes vs. legacy version:
//   • Table:   trainings           → otrs_trainings
//   • Column:  status 'Active'     → status 'active'   (lowercase)
//   • Column:  status 'Inactive'   → status 'inactive' (lowercase)
//   • Column:  deadline            → registration_deadline
//   • Column:  status_trng         → training_status   (aliased back for renderTrainingRow)
//   • Column:  target              → target_audience   (aliased back)
//   • Column:  training_type       → JOIN otrs_training_types tt ON type_id (tt.type aliased as training_type)
//   • Column:  link                → registration_link (not used in table display)
//   • Names list: otrs_list_trainings → otrs_training_types (status = 'active')
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

// ─── 1. AUTO-ARCHIVE: mark trainings past their registration deadline inactive ─
try {
    $conn->prepare("
        UPDATE otrs_trainings
        SET    status = 'inactive'
        WHERE  registration_deadline < CURDATE()
          AND  status = 'active'
          AND  force_display = 0
    ")->execute();
} catch (PDOException $e) {
    error_log('Auto-archive failed: ' . $e->getMessage());
}

// ─── 2. HELPER: Render a single <tr> for the trainings table ─────────────────
function renderTrainingRow(array $row, int $counter): void
{
    // Build formatted date display
    $displayDate = '';
    if (!empty($row['start_date']) && $row['start_date'] !== '0000-00-00') {
        $s = new DateTime($row['start_date']);
        $e = (!empty($row['end_date']) && $row['end_date'] !== '0000-00-00')
            ? new DateTime($row['end_date']) : $s;

        if ($s == $e)
            $displayDate = $s->format('F j, Y');
        elseif ($s->format('Y') !== $e->format('Y'))
            $displayDate = $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
        elseif ($s->format('m') !== $e->format('m'))
            $displayDate = $s->format('M j') . ' – ' . $e->format('M j, Y');
        else
            $displayDate = $s->format('F j') . '–' . $e->format('j, Y');
    }

    // Status badge — design system ts-badge classes
    $statusBadgeMap = [
        'completed'                      => 'ts-completed',
        'ongoing registration'           => 'ts-ongoing',
        'accepting payments'             => 'ts-ongoing',
        'ongoing'                        => 'ts-ongoing',
        'upcoming'                       => 'ts-upcoming',
        'cancelled'                      => 'ts-cancelled',
        'rescheduled'                    => 'ts-rescheduled',
        'scheduled until further notice' => 'ts-default',
    ];
    $statusBadge = $statusBadgeMap[strtolower(trim($row['status_trng']))] ?? 'ts-default';

    // Training-type badge — design system type-badge classes
    $typeCfg = [
        'Public-Offered'     => ['cls' => 'type-public',   'icon' => 'bi-tag'],
        'Free'               => ['cls' => 'type-free',     'icon' => 'bi-gift'],
        'Congress-Conducted' => ['cls' => 'type-congress', 'icon' => 'bi-bank'],
    ];
    $type          = $row['training_type'] ?? 'Public-Offered';
    $tb            = $typeCfg[$type] ?? $typeCfg['Public-Offered'];
    $typeBadgeHtml = "<span class='type-badge {$tb['cls']}'><i class='bi {$tb['icon']}'></i> "
                   . htmlspecialchars($type) . "</span>";

    $isInviteOnly = !empty($row['is_invite_only']);
    $inviteBadge  = $isInviteOnly
        ? " <span class='badge bg-dark' title='Invite-only — hidden from public listings'><i class='bi bi-lock-fill'></i></span>"
        : '';

    // Fee display
    $feeDisplay = ($type === 'Free')
        ? "<span class='text-success fw-bold'>Free</span>"
        : '<span class="text-nowrap">&#8369;&nbsp;' . number_format((float)($row['fee'] ?? 0), 2) . '</span>';

    // Truncate target for ellipsis cell  (target_audience aliased as target)
    $summaryTarget = implode(' ', array_slice(
        explode(' ', preg_replace('/\s+/', ' ', $row['target'])), 0, 10
    )) . '…';

    $id = htmlspecialchars($row['id']);

    $publicStatus     = ucfirst($row['status']);
    $enrolledCount    = (int) ($row['enrolled_count']    ?? 0);
    $proofPending     = (int) ($row['proof_pending_count'] ?? 0);
    $forcePin = !empty($row['force_display'])
        ? " <i class='bi bi-pin-angle-fill text-warning' title='Force Display ON'></i>"
        : '';
    $inviteLinkBtn = $isInviteOnly
        ? "<button class='btn-tbl btn-tbl-dark copy-invite-link-btn' data-id='{$id}'
                   data-token='" . htmlspecialchars($row['invite_token'] ?? '', ENT_QUOTES) . "'
                   title='Copy Invite Link'>
               <i class='bi bi-link-45deg'></i>
           </button>"
        : '';

    $proofBadge = $proofPending > 0
        ? "<a href='proof_payment.php?id={$id}' class='badge bg-warning text-dark ms-1'
               title='{$proofPending} pending proof(s)' style='text-decoration:none'>
             <i class='bi bi-receipt'></i> {$proofPending}
           </a>"
        : '';

    echo "
    <tr data-training-id='{$id}' data-training-name='" . htmlspecialchars($row['name']) . "'>
        <td class='text-center'>{$counter}</td>
        <td>" . htmlspecialchars($row['name']) . "</td>
        <td class='text-nowrap'>{$displayDate}</td>
        <td>" . htmlspecialchars($row['location']) . "</td>
        <td>" . htmlspecialchars($row['mode']) . "</td>
        <td>{$typeBadgeHtml}{$inviteBadge}</td>
        <td><span class='ts-badge {$statusBadge}'>" . htmlspecialchars(ucwords(strtolower($row['status_trng']))) . "</span></td>
        <td class='text-end'>{$feeDisplay}</td>
        <td>" . htmlspecialchars($row['level']) . "</td>
        <td class='text-center'>" . htmlspecialchars($row['slots']) . "</td>
        <td class='text-center text-nowrap'>{$enrolledCount} / " . htmlspecialchars($row['slots']) . "</td>
        <td class='text-center'>" . htmlspecialchars($row['no_hrs'] ?? 0) . "</td>
        <td>{$publicStatus}{$forcePin}</td>
        <td class='text-nowrap text-center'>
            <button class='btn-tbl btn-tbl-primary update-button' data-id='{$id}' title='Edit'>
                <i class='bi bi-pencil-fill'></i>
            </button>
            <button class='btn-tbl btn-tbl-gray suspended-days-btn' data-id='{$id}'
                    data-name='" . htmlspecialchars($row['name'], ENT_QUOTES) . "'
                    data-dates='" . htmlspecialchars($row['start_date'] . ' – ' . $row['end_date'], ENT_QUOTES) . "'
                    title='Suspended Days'>
                <i class='bi bi-calendar-x'></i>
            </button>
            <button class='btn-tbl btn-tbl-warning reschedule-btn' data-id='{$id}'
                    data-name='" . htmlspecialchars($row['name'], ENT_QUOTES) . "'
                    title='Reschedule Training'
                    " . (strtolower($row['status_trng']) === 'rescheduled' ? "disabled" : "") . ">
                <i class='bi bi-calendar-event'></i>
            </button>
            <button class='btn-tbl btn-tbl-danger delete-training-btn' data-id='{$id}' title='Delete'>
                <i class='bi bi-trash-fill'></i>
            </button>
            {$inviteLinkBtn}
            {$proofBadge}
        </td>
    </tr>";
}

// ─── 3. FETCH DATA ────────────────────────────────────────────────────────────
$activeTrainings   = [];
$inactiveTrainings = [];

try {
    /*
     * Aliases keep renderTrainingRow() and all JS unchanged.
     * New column → old alias:
     *   training_status  → status_trng
     *   target_audience  → target
     *   tt.type          → training_type  (via JOIN on otrs_training_types)
     */
    $baseSql = "
        SELECT ot.id,
               ot.name,
               ot.start_date,
               ot.end_date,
               ot.location,
               ot.mode,
               ot.type            AS training_type,
               ot.training_status AS status_trng,
               ot.fee,
               ot.target_audience AS target,
               ot.level,
               ot.slots,
               ot.no_hrs,
               ot.status,
               ot.force_display,
               ot.is_invite_only,
               ot.invite_token,
               COALESCE(
                 (SELECT COUNT(*) FROM otrs_registrations r
                  WHERE r.training_id = ot.id
                    AND r.registration_status NOT IN ('cancelled','not qualified')),
                 0
               ) AS enrolled_count,
               COALESCE(
                 (SELECT COUNT(*) FROM otrs_registrations r2
                  WHERE r2.training_id = ot.id
                    AND r2.proof_file IS NOT NULL AND r2.proof_file != ''
                    AND r2.payment_status NOT IN ('paid','free')
                    AND r2.registration_status NOT IN ('cancelled','not qualified')),
                 0
               ) AS proof_pending_count
        FROM otrs_trainings ot
        WHERE ot.status = :status
        ORDER BY ot.start_date DESC
    ";

    $stmtA = $conn->prepare($baseSql);
    $stmtA->execute([':status' => 'active']);
    $activeTrainings = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    $stmtI = $conn->prepare($baseSql);
    $stmtI->execute([':status' => 'inactive']);
    $inactiveTrainings = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    // Training names for the Add-Training <select> — sourced from canonical types table
    $trainingNames = $conn
        ->query("SELECT id, name, default_target, default_level, default_description FROM otrs_training_names WHERE status = 'active' ORDER BY name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Fetch failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OTRS – Manage Trainings</title>

  <link href="../assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css" rel="stylesheet">
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

  <style>
    .ellipsis { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
    #openNameLibFromPublish { font-size: .72rem; }

    /* Reschedule modal save — amber accent */
    #rescheduleModal .btn-modal-save { background: #f59e0b; }
    #rescheduleModal .btn-modal-save:hover { filter: brightness(.9); }
  </style>
</head>

<body>
  <?php imis_include('header_js') ?>
  <?php include 'inc/sidebar.php' ?>

  <main id="main" class="main">
    <!-- ── PAGE TITLE ──────────────────────────────────────────── -->
    <div class="pagetitle otrs-ph">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <div class="otrs-ph-icon icon-green"><i class="bi bi-easel2"></i></div>
          <div>
            <h1>Manage Trainings</h1>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index">Home</a></li>
                <li class="breadcrumb-item">HRD</li>
                <li class="breadcrumb-item active">Manage Trainings</li>
              </ol>
            </nav>
          </div>
        </div>
        <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
      </div>
    </div>

    <section class="section">
      <div class="otrs-card">

        <!-- ── TOP BAR: tabs + toolbar ── -->
        <div class="otrs-card-top">

          <div class="seg-tabs" id="trainingTabs" role="tablist">
            <button class="seg-tab active" id="tab-active"
                    data-bs-toggle="tab" data-bs-target="#activeTrainings" type="button" role="tab">
              <span class="tab-dot" style="background:#16a34a;"></span>
              Active
              <span class="tab-count"><?= count($activeTrainings) ?></span>
            </button>
            <button class="seg-tab" id="tab-inactive"
                    data-bs-toggle="tab" data-bs-target="#inactiveTrainings" type="button" role="tab">
              <span class="tab-dot" style="background:#94a3b8;"></span>
              Inactive
              <span class="tab-count"><?= count($inactiveTrainings) ?></span>
            </button>
          </div>

          <div class="otrs-toolbar">
            <button type="button" class="btn-f btn-f-primary"
                    data-bs-toggle="modal" data-bs-target="#addTrainingModal">
              <i class="bi bi-plus-circle"></i> Publish Training
            </button>
            <button type="button" class="btn-f btn-f-secondary"
                    data-bs-toggle="modal" data-bs-target="#addTrainingNameMain">
              <i class="bi bi-layout-text-sidebar-reverse"></i> Templates
            </button>
            <div class="otrs-toolbar-divider"></div>
            <button type="button" class="btn-f btn-f-success" id="exportActiveBtn">
              <i class="bi bi-file-earmark-excel"></i> Export
            </button>
          </div>

        </div><!-- /.otrs-card-top -->

            <?php
            // Reusable table header
            $theadHtml = '
            <thead>
              <tr>
                <th class="text-center">#</th>
                <th>Name</th>
                <th>Date</th>
                <th>Place</th>
                <th>Mode</th>
                <th>Type</th>
                <th>Status</th>
                <th class="text-end">Fee</th>
                <th>Area</th>
                <th class="text-center">Slots</th>
                <th class="text-center">Enrolled</th>
                <th class="text-center">Hrs</th>
                <th>Public</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>';
            ?>

        <!-- ── TABLE CONTENT ── -->
        <div class="otrs-card-body tab-content" id="trainingTabContent">

          <!-- Active Tab -->
          <div class="tab-pane fade show active" id="activeTrainings" role="tabpanel">
            <div class="table-responsive">
              <table id="hrdTrainings"
                     class="table table-bordered table-hover table-striped otrs-table w-100">
                <?= $theadHtml ?>
                <tbody>
                  <?php $i = 1; foreach ($activeTrainings as $row) renderTrainingRow($row, $i++); ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Inactive Tab -->
          <div class="tab-pane fade" id="inactiveTrainings" role="tabpanel">
            <div class="table-responsive">
              <table id="inactiveHrdTrainings"
                     class="table table-bordered table-hover table-striped otrs-table w-100">
                <?= $theadHtml ?>
                <tbody>
                  <?php $i = 1; foreach ($inactiveTrainings as $row) renderTrainingRow($row, $i++); ?>
                </tbody>
              </table>
            </div>
          </div>

        </div><!-- /.tab-content -->
      </div><!-- /.otrs-card -->
    </section>
  </main>


  <!-- ══════════════════════════════════════════════════════════════
       MODAL: ADD TRAINING  (modal-xl)
  ══════════════════════════════════════════════════════════════ -->
  <div class="modal fade" id="addTrainingModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-plus-circle"></i></div>
            <div class="modal-title-text">
              <h6>Publish Training</h6>
              <small>Add a new training program to the system</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="addTrainingForm" novalidate>

            <!-- Classification Banner -->
            <div class="mb-3 p-3 rounded border bg-light">
              <label class="form-label fw-semibold">Training Classification <span class="text-danger">*</span></label>
              <div class="d-flex gap-2 flex-wrap" id="addTypeSelector">
                <input type="radio" class="btn-check" name="training_type" id="typePublicOffered" value="Public-Offered" checked>
                <label class="btn btn-outline-info btn-sm" for="typePublicOffered">
                  <i class="bi bi-tag"></i> Public-Offered
                </label>

                <input type="radio" class="btn-check" name="training_type" id="typeFree" value="Free">
                <label class="btn btn-outline-success btn-sm" for="typeFree">
                  <i class="bi bi-gift"></i> Free
                </label>

                <input type="radio" class="btn-check" name="training_type" id="typeCongress" value="Congress-Conducted">
                <label class="btn btn-outline-warning btn-sm" for="typeCongress">
                  <i class="bi bi-bank"></i> Congress-Conducted
                </label>
              </div>
              <div id="addTypeHint" class="form-text mt-2"></div>

              <div class="form-check mt-2 d-none" id="addInviteOnlyWrap">
                <input class="form-check-input" type="checkbox" id="addInviteOnlyToggle" name="is_invite_only">
                <label class="form-check-label" for="addInviteOnlyToggle">
                  <i class="bi bi-lock-fill"></i> Invite-only (hidden from public listings — only reachable via a generated link)
                </label>
                <div class="form-text">
                  Cannot be changed after the training is created. Pick this only for a training arranged for a specific group, not for general public registration.
                </div>

                <div class="mt-2">
                  <label class="form-label fw-semibold small mb-1" for="addInviteSlugInput">
                    Custom link <span class="text-muted fw-normal">(optional)</span>
                  </label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">.../index.php?invite=</span>
                    <input type="text" class="form-control" id="addInviteSlugInput" name="invite_slug"
                           placeholder="e.g. hr-leaders-2026" maxlength="64" pattern="[A-Za-z0-9\-]+">
                  </div>
                  <div class="form-text">Letters, numbers, and hyphens only. Leave blank to auto-generate a short link.</div>
                </div>
              </div>
            </div>

            <div class="row g-3">
              <!-- LEFT COLUMN -->
              <div class="col-md-6">
                <div class="mb-2">
                  <label class="form-label fw-semibold">Name of Training <span class="text-danger">*</span></label>
                  <select class="form-select" name="name" id="addTrainingNameSelect" required>
                    <option value="" hidden>— Select Training —</option>
                    <?php foreach ($trainingNames as $n): ?>
                      <option value="<?= htmlspecialchars($n['name']) ?>"
                              data-template='<?= htmlspecialchars(json_encode([
                                  'target'      => $n['default_target']      ?? '',
                                  'level'       => $n['default_level']       ?? '',
                                  'description' => $n['default_description'] ?? '',
                              ]), ENT_QUOTES) ?>'>
                        <?= htmlspecialchars($n['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">
                    <a href="#" id="openNameLibFromPublish" class="text-primary text-decoration-none">
                      <i class="bi bi-layout-text-sidebar-reverse"></i> Manage Training Templates
                    </a>
                  </div>
                </div>

                <div class="mb-2">
                  <label class="form-label fw-semibold">Place / Venue <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="location" required>
                </div>

                <div class="row g-2 mb-2" id="addRangeDatesRow">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="start_date" id="addStartDate" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="end_date" id="addEndDate" required>
                  </div>
                </div>

                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" role="switch" id="addCustomDatesToggle">
                  <label class="form-check-label" for="addCustomDatesToggle">
                    Custom (non-consecutive) dates
                  </label>
                </div>

                <div class="mb-2 d-none" id="addCustomDatesWrap">
                  <label class="form-label fw-semibold">Training Dates <span class="text-danger">*</span></label>
                  <div id="addCustomDatesList" class="d-flex flex-column gap-2 mb-2"></div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="addCustomDateBtn">
                    <i class="bi bi-plus-circle"></i> Add Date
                  </button>
                  <div class="form-text">Pick every actual training day. Days not listed here (e.g. a gap between two sessions) are not counted as training days.</div>
                </div>

                <div class="mb-2">
                  <label class="form-label fw-semibold">Registration Link <span class="text-muted fw-normal">(optional)</span></label>
                  <input type="url" class="form-control" name="link" placeholder="https://">
                </div>

                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Mode <span class="text-danger">*</span></label>
                    <select class="form-select" name="mode" required>
                      <option value="Face to Face">Face to Face</option>
                      <option value="Online">Online</option>
                      <option value="Hybrid">Hybrid</option>
                    </select>
                  </div>
                  <div class="col-6" id="addFeeWrapper">
                    <label class="form-label fw-semibold">Fee <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text">&#8369;</span>
                      <input type="text" class="form-control" id="addFeeInput" name="fee"
                             inputmode="decimal" placeholder="0.00" required>
                    </div>
                  </div>
                </div>

                <div class="mb-2">
                  <label class="form-label fw-semibold">Registration Deadline <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="deadline" required>
                  <small id="addDeadlineWarn" class="text-warning d-none">
                    <i class="bi bi-exclamation-triangle"></i> Deadline is in the past — training may be auto-archived.
                  </small>
                </div>
              </div><!-- /left col -->

              <!-- RIGHT COLUMN -->
              <div class="col-md-6">
                <div class="mb-2">
                  <label class="form-label fw-semibold">Target Participants <span class="text-danger">*</span></label>
                  <textarea class="form-control" name="target" rows="4" maxlength="600" required></textarea>
                  <small class="char-counter text-muted float-end"></small>
                </div>

                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Curriculum Area <span class="text-danger">*</span></label>
                    <select class="form-select" name="level" required>
                      <option value="Leadership and Management">Leadership and Management</option>
                      <option value="Foundation">Foundation</option>
                      <option value="Technical">Technical</option>
                    </select>
                  </div>
                  <div class="col-3">
                    <label class="form-label fw-semibold">
                      Slots <span class="text-danger">*</span>
                      <i class="bi bi-info-circle text-secondary" id="addSlotsHintIcon"
                         style="display:none" data-bs-toggle="tooltip" title=""></i>
                    </label>
                    <input type="number" class="form-control" id="addSlotsInput"
                           name="slots" min="1" max="9999" required>
                  </div>
                  <div class="col-3">
                    <label class="form-label fw-semibold">
                      No. of Hrs
                      <i class="bi bi-info-circle text-muted small"
                         data-bs-toggle="tooltip"
                         title="Training hours credited on the certificate"></i>
                    </label>
                    <input type="number" class="form-control" name="no_hrs"
                           min="0" max="999" value="0">
                  </div>
                </div>

                <div class="mb-2">
                  <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                  <textarea class="form-control" name="description" rows="9" maxlength="1500" required></textarea>
                  <small class="char-counter text-muted float-end"></small>
                </div>
              </div><!-- /right col -->
            </div><!-- /row -->

          </form>
        </div><!-- /.modal-body -->
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="addTrainingForm" class="btn-modal-save" id="addSubmitBtn">
            <i class="bi bi-check-circle"></i>
            <span class="save-label">Publish Training</span>
          </button>
        </div>
      </div>
    </div>
  </div>


  <!-- ══════════════════════════════════════════════════════════════
       MODAL: EDIT TRAINING  (modal-xl)
  ══════════════════════════════════════════════════════════════ -->
  <div class="modal fade" id="editTraining" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-pencil-square"></i></div>
            <div class="modal-title-text">
              <h6 id="editTrainingTitle">Edit Training</h6>
              <small>Update training details and settings</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editMe" novalidate>
            <input type="hidden" id="trainingId" name="trainingId">

            <!-- Metadata row -->
            <p id="editMeta" class="text-muted small mb-3 ps-1">
              <i class="bi bi-clock-history"></i>
              Published: <span id="editCreatedAt">—</span>
              &nbsp;·&nbsp;
              Last updated: <span id="editUpdatedAt">—</span>
            </p>

            <!-- Classification Banner -->
            <div class="mb-3 p-3 rounded border bg-light">
              <label class="form-label fw-semibold">Training Classification</label>
              <div class="d-flex gap-2 flex-wrap" id="editTypeSelector">
                <input type="radio" class="btn-check" name="training_type" id="editTypePublicOffered" value="Public-Offered">
                <label class="btn btn-outline-info btn-sm" for="editTypePublicOffered">
                  <i class="bi bi-tag"></i> Public-Offered
                </label>

                <input type="radio" class="btn-check" name="training_type" id="editTypeFree" value="Free">
                <label class="btn btn-outline-success btn-sm" for="editTypeFree">
                  <i class="bi bi-gift"></i> Free
                </label>

                <input type="radio" class="btn-check" name="training_type" id="editTypeCongress" value="Congress-Conducted">
                <label class="btn btn-outline-warning btn-sm" for="editTypeCongress">
                  <i class="bi bi-bank"></i> Congress-Conducted
                </label>
              </div>
              <div id="editTypeHint" class="form-text mt-2"></div>
            </div>

            <div class="mb-3 p-3 rounded border bg-light d-none" id="editInviteLinkPanel">
              <label class="form-label fw-semibold small mb-1">
                <i class="bi bi-lock-fill"></i> Invite Link
              </label>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="editInviteLinkBox" readonly>
                <button class="btn btn-outline-secondary" type="button" id="editInviteLinkCopyBtn">
                  <i class="bi bi-clipboard"></i> Copy
                </button>
                <button class="btn btn-outline-dark" type="button" id="editInviteLinkRegenBtn">
                  <i class="bi bi-arrow-repeat"></i> Regenerate
                </button>
              </div>
              <div class="form-text">Regenerating replaces this link — the old one stops working immediately.</div>
            </div>

            <div class="row g-3">
              <!-- LEFT -->
              <div class="col-md-6">
                <div class="mb-2">
                  <label class="form-label fw-semibold">Name</label>
                  <input type="text" class="form-control" id="editName" name="editName" required>
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Location</label>
                  <input type="text" class="form-control" id="editLocation" name="editLocation" required>
                </div>
                <div class="row g-2 mb-2" id="editRangeDatesRow">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" class="form-control" id="editStartDate" name="editStartDate" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" class="form-control" id="editEndDate" name="editEndDate" required>
                  </div>
                </div>

                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" role="switch" id="editCustomDatesToggle">
                  <label class="form-check-label" for="editCustomDatesToggle">
                    Custom (non-consecutive) dates
                  </label>
                </div>

                <div class="mb-2 d-none" id="editCustomDatesWrap">
                  <label class="form-label fw-semibold">Training Dates <span class="text-danger">*</span></label>
                  <div id="editCustomDatesList" class="d-flex flex-column gap-2 mb-2"></div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="editCustomDateBtn">
                    <i class="bi bi-plus-circle"></i> Add Date
                  </button>
                  <div class="form-text">Pick every actual training day. Days not listed here are not counted as training days.</div>
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Registration Link <span class="text-muted fw-normal">(optional)</span></label>
                  <input type="url" class="form-control" id="editLink" name="editLink" placeholder="https://">
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Mode</label>
                    <select class="form-select" id="editMode" name="editMode">
                      <option value="Face to Face">Face to Face</option>
                      <option value="Online">Online</option>
                      <option value="Hybrid">Hybrid</option>
                    </select>
                  </div>
                  <div class="col-6" id="editFeeWrapper">
                    <label class="form-label fw-semibold">Fee</label>
                    <div class="input-group">
                      <span class="input-group-text">&#8369;</span>
                      <input type="text" class="form-control" id="editFee" name="editFee"
                             inputmode="decimal">
                    </div>
                  </div>
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Training Status</label>
                  <select class="form-select" id="editStatusTrng" name="editStatusTrng">
                    <option value="upcoming">Upcoming</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="ongoing registration">Ongoing Registration</option>
                    <option value="accepting payments">Accepting Payments</option>
                    <option value="scheduled until further notice">Scheduled Until Further Notice</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="rescheduled">Rescheduled</option>
                  </select>
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Deadline</label>
                    <input type="date" class="form-control" id="editDeadline" name="editDeadline" required>
                    <small id="editDeadlineWarn" class="text-warning d-none">
                      <i class="bi bi-exclamation-triangle"></i> Deadline is in the past — training may be auto-archived.
                    </small>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">Public Status</label>
                    <select class="form-select" id="editStatus" name="editStatus">
                      <option value="active">Active</option>
                      <option value="inactive">Inactive</option>
                    </select>
                  </div>
                </div>
                <div class="mt-2 p-2 rounded border bg-warning bg-opacity-10">
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="editForceDisplay" name="force_display" value="1">
                    <label class="form-check-label fw-semibold" for="editForceDisplay">
                      <i class="bi bi-pin-angle-fill text-warning"></i> Force Display
                      <small class="text-muted fw-normal">— show on public page even after deadline/end date passes</small>
                    </label>
                  </div>
                </div>
              </div><!-- /left -->

              <!-- RIGHT -->
              <div class="col-md-6">
                <div class="mb-2">
                  <label class="form-label fw-semibold">Target Participants</label>
                  <textarea class="form-control" id="editTarget" name="editTarget" rows="5" maxlength="600" required></textarea>
                  <small class="char-counter text-muted float-end"></small>
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-5">
                    <label class="form-label fw-semibold">Area</label>
                    <select class="form-select" id="editLevel" name="editLevel">
                      <option value="Leadership and Management">Leadership and Management</option>
                      <option value="Foundation">Foundation</option>
                      <option value="Technical">Technical</option>
                    </select>
                  </div>
                  <div class="col-4">
                    <label class="form-label fw-semibold">Slots</label>
                    <input type="number" class="form-control" id="editSlots" name="editSlots" min="0" max="9999" required>
                    <div id="editSlotsHint" class="form-text mt-1 d-none" style="font-size:.75rem"></div>
                  </div>
                  <div class="col-3">
                    <label class="form-label fw-semibold">
                      No. of Hrs
                      <i class="bi bi-info-circle text-muted small"
                         data-bs-toggle="tooltip"
                         title="Training hours credited on the certificate"></i>
                    </label>
                    <input type="number" class="form-control" id="editNoHrs" name="editNoHrs"
                           min="0" max="999" value="0">
                  </div>
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Description</label>
                  <textarea class="form-control" id="editDescription" name="editDescription" rows="11" maxlength="1500" required></textarea>
                  <small class="char-counter text-muted float-end"></small>
                </div>
              </div><!-- /right -->
            </div><!-- /row -->
          </form>
        </div><!-- /.modal-body -->
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="editMe" class="btn-modal-save" id="editSubmitBtn">
            <i class="bi bi-save"></i>
            <span class="save-label">Save Changes</span>
          </button>
        </div>
      </div>
    </div>
  </div>


  <!-- ══════════════════════════════════════════════════════════════
       MODAL: MANAGE TRAINING NAMES
  ══════════════════════════════════════════════════════════════ -->
  <div class="modal fade" id="addTrainingNameMain" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-layout-text-sidebar-reverse"></i></div>
            <div class="modal-title-text">
              <h6>Training Template Library</h6>
              <small>CSC RO VIII — manage training templates and their default values</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <button type="button" class="btn btn-primary btn-sm mb-3" id="openAddNameModal">
            <i class="bi bi-plus-circle"></i> Add Template
          </button>
          <table id="trainingNameTable"
                 class="table table-bordered table-hover table-striped"
                 style="font-size: 13px; width: 100%">
            <thead>
              <tr>
                <th style="width:8%" class="text-center">#</th>
                <th>Training Name</th>
                <th class="text-center" style="width:110px">Action</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>


  <!-- MODAL: ADD Training Name -->
  <div class="modal fade" id="addTrainingNameModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-plus-circle"></i></div>
            <div class="modal-title-text">
              <h6>Add Training Template</h6>
              <small>Add a new training template to the library</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <form id="addTrainingNameForm" novalidate>
            <div class="mb-3">
              <label class="form-label fw-semibold">Training Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="trainingNameInput"
                     name="trainingName" placeholder="Enter training name" required>
            </div>
            <hr class="my-3">
            <p class="text-muted small mb-3">
              <i class="bi bi-magic"></i>
              <strong>Template fields</strong> — these will auto-fill the Publish Training form when this name is selected.
            </p>
            <div class="mb-3">
              <label class="form-label fw-semibold">Curriculum Area <span class="text-muted fw-normal">(optional)</span></label>
              <select class="form-select" name="defaultLevel">
                <option value="">— none —</option>
                <option value="Leadership and Management">Leadership and Management</option>
                <option value="Foundation">Foundation</option>
                <option value="Technical">Technical</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Target Participants <span class="text-muted fw-normal">(optional)</span></label>
              <textarea class="form-control" name="defaultTarget" rows="3" maxlength="600"
                        placeholder="e.g. All government employees in salary grade 11–18"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Description <span class="text-muted fw-normal">(optional)</span></label>
              <textarea class="form-control" name="defaultDescription" rows="4" maxlength="1500"
                        placeholder="Enter a default training description…"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-back me-auto" id="backToNameLibFromAdd">
            <i class="bi bi-arrow-left-circle"></i> Back
          </button>
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="addTrainingNameForm" class="btn-modal-save">
            <i class="bi bi-plus-circle"></i>
            <span class="save-label">Add Template</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: EDIT Training Name -->
  <div class="modal fade" id="editTrainingNameModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-pencil"></i></div>
            <div class="modal-title-text">
              <h6>Edit Training Template</h6>
              <small>Update the training template and its default values</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <form id="editTrainingNameForm" novalidate>
            <input type="hidden" id="editTrainingNameId" name="id">
            <div class="mb-3">
              <label class="form-label fw-semibold">Training Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="editTrainingNameInput"
                     name="name" placeholder="Enter training name" required>
            </div>
            <hr class="my-3">
            <p class="text-muted small mb-3">
              <i class="bi bi-magic"></i>
              <strong>Template fields</strong> — these will auto-fill the Publish Training form when this name is selected.
            </p>
            <div class="mb-3">
              <label class="form-label fw-semibold">Curriculum Area <span class="text-muted fw-normal">(optional)</span></label>
              <select class="form-select" id="editTrainingDefaultLevel" name="defaultLevel">
                <option value="">— none —</option>
                <option value="Leadership and Management">Leadership and Management</option>
                <option value="Foundation">Foundation</option>
                <option value="Technical">Technical</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Target Participants <span class="text-muted fw-normal">(optional)</span></label>
              <textarea class="form-control" id="editTrainingDefaultTarget" name="defaultTarget"
                        rows="3" maxlength="600"
                        placeholder="e.g. All government employees in salary grade 11–18"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Description <span class="text-muted fw-normal">(optional)</span></label>
              <textarea class="form-control" id="editTrainingDefaultDesc" name="defaultDescription"
                        rows="4" maxlength="1500"
                        placeholder="Enter a default training description…"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-back me-auto" id="backToNameLibFromEdit">
            <i class="bi bi-arrow-left-circle"></i> Back
          </button>
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="editTrainingNameForm" class="btn-modal-save">
            <i class="bi bi-save"></i>
            <span class="save-label">Save Changes</span>
          </button>
        </div>
      </div>
    </div>
  </div>


  <!-- ══════════════════════════════════════════════════════════════
       MODAL: RESCHEDULE TRAINING
  ══════════════════════════════════════════════════════════════ -->
  <div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-calendar-event"></i></div>
            <div class="modal-title-text">
              <h6>Reschedule Training</h6>
              <small id="rescheduleModalSub" class="text-white-50"></small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <form id="rescheduleForm" novalidate>
            <input type="hidden" id="rescheduleTrainingId" name="training_id">
            <div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-3">
              <i class="bi bi-exclamation-triangle-fill mt-1"></i>
              <div>
                This will mark the training as <strong>Rescheduled</strong>.
                Paid participants can be transferred to the new training from the
                <a href="rescheduled_trainings" class="alert-link">Rescheduled Trainings</a> page.
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Reason for Rescheduling <span class="text-danger">*</span></label>
              <textarea class="form-control" id="rescheduleReason" name="reason"
                        rows="3" maxlength="255"
                        placeholder="e.g. Low registration turnout" required></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="rescheduleForm" class="btn-modal-save" style="background:#f59e0b;">
            <i class="bi bi-calendar-event"></i>
            <span class="save-label">Mark as Rescheduled</span>
          </button>
        </div>
      </div>
    </div>
  </div>


  <!-- ══════════════════════════════════════════════════════════════
       MODAL: SUSPENDED DAYS
  ══════════════════════════════════════════════════════════════ -->
  <div class="modal fade" id="suspendedDaysModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title-wrap flex-grow-1">
            <div class="modal-avatar"><i class="bi bi-calendar-x"></i></div>
            <div class="modal-title-text">
              <h6 id="suspModalTitle">Suspended Days</h6>
              <small id="suspModalDates" class="text-white-50"></small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">

          <!-- Add form -->
          <form id="addSuspensionForm" class="mb-4">
            <input type="hidden" id="suspTrainingId" name="training_id">
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Suspended Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="suspended_date" id="suspDateInput" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="reason" id="suspReasonInput"
                       maxlength="255" placeholder="e.g. Proclamation Holiday No. 727" required>
              </div>
              <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-plus-circle"></i> Add
                </button>
              </div>
            </div>
          </form>

          <!-- Log table -->
          <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm" style="font-size:13px">
              <thead class="table-light">
                <tr>
                  <th style="width:160px">Date</th>
                  <th>Reason</th>
                  <th style="width:130px" class="text-muted">Logged</th>
                  <th style="width:60px" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody id="suspensionsList">
                <tr id="suspNoData">
                  <td colspan="4" class="text-center text-muted py-3">No suspended days logged.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="text-muted small mb-0 mt-2">
            <i class="bi bi-info-circle"></i>
            To reschedule a suspended day, update the training's <strong>End Date</strong> via
            <strong>Edit Training</strong> after logging the suspension here.
          </p>
        </div>
        <div class="modal-footer modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>


  <?php imis_include('footer') ?>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

  <script>
  (() => {
    'use strict';

    // ══════════════════════════════════════════════════════════════
    // UTILITIES
    // ══════════════════════════════════════════════════════════════

    /** Safely escape HTML to prevent XSS. */
    const esc = (val) =>
      val == null ? '' :
      String(val).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                 .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
                 .replace(/'/g,'&#039;');

    /**
     * Format a raw numeric value as Philippine Peso for table display.
     * e.g. 1500 → "₱ 1,500.00"
     */
    const fmtPeso = (val) => {
      const n = parseFloat(String(val).replace(/[^\d.]/g, '')) || 0;
      return '₱\u00a0' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    /**
     * Format a value for the fee *input field* — plain number with commas, no symbol.
     * e.g. "1500.00" → "1,500.00"
     */
    const fmtFeeInput = (val) => {
      const n = parseFloat(String(val).replace(/[^\d.]/g, '')) || 0;
      return n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    /** Strip formatting from a fee input value before sending to the server. */
    const stripFee = (val) => String(val).replace(/[^\d.]/g, '');

    // Invite-only isn't editable, but it must survive a save — the edit form
    // has no field for it, so the fetch-data handler stashes it here and the
    // submit handler reads it back when rebuilding the row.
    let editTrainingInviteState = { is_invite_only: 0, invite_token: '' };

    /** POST data using Fetch API — returns parsed JSON or throws. */
    const postJSON = async (url, data) => {
      const token = window.csrfToken ?? '';
      let body;
      if (data instanceof FormData) {
        data.append('_csrf', token);
        body = data;
      } else {
        body = new URLSearchParams({ ...data, _csrf: token });
      }
      const res = await fetch(url, { method: 'POST', body, headers: { 'X-CSRF-Token': token } });
      if (!res.ok) throw new Error(`HTTP ${res.status} — ${res.statusText}`);
      return res.json();
    };

    // Bootstrap's modal focus-trap lives on `document` and yanks focus back
    // into the open modal whenever it detects focus escaping it — including
    // into a SweetAlert2 popup, which renders outside the modal's DOM. That
    // makes typing in a Swal input impossible while a Bootstrap modal (e.g.
    // Edit Training) is open underneath it. Stop the trap specifically for
    // swal2 elements so both can coexist.
    document.addEventListener('focusin', (e) => {
      if (e.target.closest('.swal2-container')) {
        e.stopImmediatePropagation();
      }
    });

    /**
     * Format training date range to match PHP renderTrainingRow() output.
     * @param {string} s  start date  YYYY-MM-DD
     * @param {string} e  end date    YYYY-MM-DD
     */
    const fmtDate = (s, e) => {
      if (!s || s === '0000-00-00') return '';
      const parse = (d) => { const [y, m, day] = d.split('-'); return new Date(y, m - 1, day); };
      const MS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      const ML = ['January','February','March','April','May','June','July','August',
                  'September','October','November','December'];
      const sd = parse(s), ed = (e && e !== '0000-00-00') ? parse(e) : parse(s);
      if (sd.getTime() === ed.getTime())
        return `${ML[sd.getMonth()]} ${sd.getDate()}, ${sd.getFullYear()}`;
      if (sd.getFullYear() !== ed.getFullYear())
        return `${MS[sd.getMonth()]} ${sd.getDate()}, ${sd.getFullYear()} – ${MS[ed.getMonth()]} ${ed.getDate()}, ${ed.getFullYear()}`;
      if (sd.getMonth() !== ed.getMonth())
        return `${MS[sd.getMonth()]} ${sd.getDate()} – ${MS[ed.getMonth()]} ${ed.getDate()}, ${ed.getFullYear()}`;
      return `${MS[sd.getMonth()]} ${sd.getDate()}–${ed.getDate()}, ${ed.getFullYear()}`;
    };


    // ══════════════════════════════════════════════════════════════
    // ROW BUILDER — mirrors PHP renderTrainingRow()
    // ══════════════════════════════════════════════════════════════
    const STATUS_BADGE = {
      'completed': 'ts-completed', 'ongoing registration': 'ts-ongoing',
      'accepting payments': 'ts-ongoing', 'ongoing': 'ts-ongoing',
      'upcoming': 'ts-upcoming', 'cancelled': 'ts-cancelled',
      'rescheduled': 'ts-rescheduled',
      'scheduled until further notice': 'ts-default',
    };
    function toTitleCase(str) {
      return (str || '').replace(/\b\w/g, c => c.toUpperCase());
    }
    const TYPE_CFG = {
      'Public-Offered':     { cls: 'type-public',   icon: 'bi-tag'  },
      'Free':               { cls: 'type-free',     icon: 'bi-gift' },
      'Congress-Conducted': { cls: 'type-congress', icon: 'bi-bank' },
    };

    const buildRow = (d) => {
      const type  = d.training_type || 'Public-Offered';
      const tb    = TYPE_CFG[type] || TYPE_CFG['Public-Offered'];
      const badge = STATUS_BADGE[(d.status_trng || '').toLowerCase()] || 'bg-secondary';
      const date  = fmtDate(d.start_date, d.end_date) || esc(d.date || '');
      const feeHtml = type === 'Free'
        ? `<span class="text-success fw-bold">Free</span>`
        : `<span class="text-nowrap">${fmtPeso(d.fee)}</span>`;
      const enrolled = parseInt(d.enrolled_count ?? 0);
      const slots    = parseInt(d.slots ?? 0);
      const proofPending = parseInt(d.proof_pending_count ?? 0);
      const proofBadge = proofPending > 0
        ? `<a href="proof_payment.php?id=${esc(d.id)}" class="badge bg-warning text-dark ms-1"
              title="${proofPending} pending proof(s)" style="text-decoration:none">
             <i class="bi bi-receipt"></i> ${proofPending}
           </a>`
        : '';
      const isInviteOnly = !!(d.is_invite_only && d.is_invite_only !== '0');
      const inviteBadge = isInviteOnly
        ? ` <span class="badge bg-dark" title="Invite-only — hidden from public listings"><i class="bi bi-lock-fill"></i></span>`
        : '';
      const inviteLinkBtn = isInviteOnly
        ? `<button class="btn-tbl btn-tbl-dark copy-invite-link-btn" data-id="${esc(d.id)}"
                    data-token="${esc(d.invite_token || '')}" title="Copy Invite Link">
             <i class="bi bi-link-45deg"></i>
           </button>`
        : '';

      const tr = document.createElement('tr');
      tr.dataset.trainingId   = d.id;
      tr.dataset.trainingName = d.name;
      tr.innerHTML = `
        <td class="text-center"></td>
        <td>${esc(d.name)}</td>
        <td class="text-nowrap">${date}</td>
        <td>${esc(d.location)}</td>
        <td>${esc(d.mode)}</td>
        <td><span class="type-badge ${tb.cls}"><i class="bi ${tb.icon}"></i> ${esc(type)}</span>${inviteBadge}</td>
        <td><span class="ts-badge ${badge}">${toTitleCase(esc(d.status_trng))}</span></td>
        <td class="text-end">${feeHtml}</td>
        <td>${esc(d.level)}</td>
        <td class="text-center">${esc(d.slots)}</td>
        <td class="text-center text-nowrap">${enrolled} / ${slots}</td>
        <td class="text-center">${esc(d.no_hrs ?? 0)}</td>
        <td>${esc(d.status ? d.status.charAt(0).toUpperCase() + d.status.slice(1) : '')}${d.force_display ? ' <i class="bi bi-pin-angle-fill text-warning" title="Force Display ON"></i>' : ''}</td>
        <td class="text-nowrap text-center">
          <button class="btn-tbl btn-tbl-primary update-button" data-id="${esc(d.id)}" title="Edit">
            <i class="bi bi-pencil-fill"></i>
          </button>
          <button class="btn-tbl btn-tbl-gray suspended-days-btn"
                  data-id="${esc(d.id)}"
                  data-name="${esc(d.name)}"
                  data-dates="${esc((d.start_date || '') + ' – ' + (d.end_date || ''))}"
                  title="Suspended Days">
            <i class="bi bi-calendar-x"></i>
          </button>
          <button class="btn-tbl btn-tbl-warning reschedule-btn"
                  data-id="${esc(d.id)}"
                  data-name="${esc(d.name)}"
                  title="Reschedule Training"
                  ${(d.status_trng || '').toLowerCase() === 'rescheduled' ? 'disabled' : ''}>
            <i class="bi bi-calendar-event"></i>
          </button>
          <button class="btn-tbl btn-tbl-danger delete-training-btn" data-id="${esc(d.id)}" title="Delete">
            <i class="bi bi-trash-fill"></i>
          </button>
          ${inviteLinkBtn}
          ${proofBadge}
        </td>`;
      return tr;
    };

    /** Flash a newly inserted row green for visual feedback. */
    const flashRow = (tr) => {
      tr.classList.add('row-inserted');
      setTimeout(() => tr.classList.remove('row-inserted'), 1500);
    };


    // ══════════════════════════════════════════════════════════════
    // TRAINING TYPE — Dynamic Form Behaviour
    // ══════════════════════════════════════════════════════════════
    const TYPE_HINTS = {
      'Public-Offered':     '',
      'Free':               '<i class="bi bi-info-circle"></i> Fee will be set to <strong>₱ 0.00</strong> automatically.',
      'Congress-Conducted': '<i class="bi bi-bank"></i> Congress-funded training. Slot capacity is typically higher.',
    };

    const applyTypeConfig = (type, ctx) => {
      const hint     = document.getElementById(`${ctx}TypeHint`);
      const feeWrap  = document.getElementById(`${ctx}FeeWrapper`);
      const feeInput = document.getElementById(ctx === 'add' ? 'addFeeInput' : 'editFee');
      const sIcon    = document.getElementById(`${ctx}SlotsHintIcon`);

      if (hint) hint.innerHTML = TYPE_HINTS[type] || '';

      if (feeInput) {
        const isFree = (type === 'Free');
        feeInput.readOnly = isFree;
        feeInput.value    = isFree ? '0.00' : (feeInput.value === '0.00' || feeInput.value === '0' ? '' : feeInput.value);
        if (feeWrap) feeWrap.style.opacity = isFree ? '0.55' : '1';
      }

      if (sIcon) {
        const hint = type === 'Congress-Conducted'
          ? 'Congress-conducted trainings typically have 100+ slots.' : '';
        if (hint) {
          sIcon.style.display = '';
          sIcon.setAttribute('title', hint);
          const existing = bootstrap.Tooltip.getInstance(sIcon);
          if (existing) existing.dispose();
          new bootstrap.Tooltip(sIcon);
        } else {
          sIcon.style.display = 'none';
        }
      }

      // Invite-only is only offered at creation time, and only for Public-Offered.
      if (ctx === 'add') {
        const inviteWrap  = document.getElementById('addInviteOnlyWrap');
        const inviteCheck = document.getElementById('addInviteOnlyToggle');
        const inviteSlug  = document.getElementById('addInviteSlugInput');
        const isPublic    = (type === 'Public-Offered');
        if (inviteWrap)  inviteWrap.classList.toggle('d-none', !isPublic);
        if (inviteCheck && !isPublic) inviteCheck.checked = false;
        if (inviteSlug  && !isPublic) inviteSlug.value = '';
      }
    };

    document.querySelectorAll('#addTypeSelector input[type=radio]').forEach(r =>
      r.addEventListener('change', () => applyTypeConfig(r.value, 'add'))
    );
    document.querySelectorAll('#editTypeSelector input[type=radio]').forEach(r =>
      r.addEventListener('change', () => applyTypeConfig(r.value, 'edit'))
    );

    // Format fee inputs on blur — show 2 decimal places with comma separators (no ₱ symbol in the input)
    ['addFeeInput', 'editFee'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('blur', () => { if (!el.readOnly) el.value = fmtFeeInput(el.value); });
    });

    // Reset add-form state when modal opens. Invite-only fields are cleared
    // unconditionally here (not just inside applyTypeConfig's "switched away
    // from Public-Offered" branch) since the modal always defaults back to
    // Public-Offered — that branch never fires on a fresh open, so a checked
    // box + typed slug from a cancelled previous attempt would otherwise
    // still be sitting there.
    document.getElementById('addTrainingModal')
      .addEventListener('show.bs.modal', () => {
        document.getElementById('typePublicOffered').checked = true;
        document.getElementById('addInviteOnlyToggle').checked = false;
        document.getElementById('addInviteSlugInput').value = '';
        applyTypeConfig('Public-Offered', 'add');
      });


    // ══════════════════════════════════════════════════════════════
    // DATATABLES — initialisation
    // ══════════════════════════════════════════════════════════════
    const dtOpts = {
      order: [[0, 'asc']],
      pageLength: 10,
      autoWidth: false,
      language: { search: '<i class="bi bi-search"></i> _INPUT_', searchPlaceholder: 'Search…' },
      dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
           "<'row'<'col-sm-12'tr>>" +
           "<'row'<'col-sm-5'i><'col-sm-7'p>>",
      buttons: [{
        extend: 'excelHtml5',
        text: '<i class="bi bi-file-earmark-excel"></i> Export',
        className: 'btn btn-success btn-sm',
        title: 'OTRS Training Programs',
        exportOptions: { columns: ':not(:last-child)' },
      }],
    };

    const activeTable   = $('#hrdTrainings').DataTable(dtOpts);
    const inactiveTable = $('#inactiveHrdTrainings').DataTable(dtOpts);

    // Hide the DataTables auto-appended button containers (triggered manually via toolbar)
    activeTable.buttons().container().hide();
    inactiveTable.buttons().container().hide();

    // Re-adjust columns when tabs switch (Bootstrap lazy-render)
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(btn =>
      btn.addEventListener('shown.bs.tab', () => {
        activeTable.columns.adjust().draw(false);
        inactiveTable.columns.adjust().draw(false);
      })
    );

    // Re-adjust columns when modals open/close (body padding-right shift)
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('shown.bs.modal', () => {
        activeTable.columns.adjust().draw(false);
        inactiveTable.columns.adjust().draw(false);
      });
      modal.addEventListener('hidden.bs.modal', () => {
        activeTable.columns.adjust().draw(false);
        inactiveTable.columns.adjust().draw(false);
      });
    });

    // Training Names table (server-side)
    let trainingNameTable;
    document.getElementById('addTrainingNameMain')
      .addEventListener('shown.bs.modal', () => {
        if (!$.fn.DataTable.isDataTable('#trainingNameTable')) {
          trainingNameTable = $('#trainingNameTable').DataTable({
            processing: true, serverSide: true,
            pageLength: 25,
            lengthMenu: [25, 50, 100],
            ajax: { url: 'api/fetch_list_trainings.php', type: 'POST' },
            columns: [
              {
                data: null, className: 'text-center', width: '8%',
                render: function(_, __, ___, meta) {
                  return meta.settings._iDisplayStart + meta.row + 1;
                }
              },
              { data: 'name' },
              {
                data: null, className: 'text-center', width: '110px', orderable: false,
                render: function(_, __, row) {
                  const id   = parseInt(row.id);
                  const name = String(row.name).replace(/"/g, '&quot;');
                  return `<button class="btn btn-warning btn-sm edit-name-btn" data-id="${id}" data-name="${name}" title="Edit"><i class="bi bi-pencil"></i></button>
                          <button class="btn btn-danger btn-sm delete-name-btn" data-id="${id}" title="Delete"><i class="bi bi-trash"></i></button>`;
                }
              },
            ],
          });
        } else {
          trainingNameTable.ajax.reload(null, false);
        }
      });


    // ══════════════════════════════════════════════════════════════
    // EDIT — fetch then populate modal
    // ══════════════════════════════════════════════════════════════
    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('.update-button');
      if (!btn) return;

      btn.disabled = true;
      try {
        const data = await postJSON('api/fetch_data.php', { trainingId: btn.dataset.id });
        if (data.status === 'error') { Swal.fire('Error', data.message, 'error'); return; }

        // Populate fields
        document.getElementById('trainingId').value     = data.id;
        document.getElementById('editName').value        = data.name;
        document.getElementById('editLocation').value    = data.location;
        document.getElementById('editLink').value        = data.link;
        document.getElementById('editMode').value        = data.mode;
        document.getElementById('editFee').value         = fmtFeeInput(data.fee ?? 0);
        document.getElementById('editStatusTrng').value  = data.status_trng;
        document.getElementById('editStatus').value      = data.status;
        document.getElementById('editTarget').value      = data.target;
        document.getElementById('editLevel').value       = data.level;
        document.getElementById('editSlots').value       = data.slots;
        document.getElementById('editNoHrs').value       = data.no_hrs ?? 0;
        editSuspendedCount = parseInt(data.suspended_count) || 0;

        // ── Slots: show enrolled count as info only ───────────────────
        const enrolledCount = parseInt(data.enrolled_count) || 0;
        const slotsHint     = document.getElementById('editSlotsHint');
        if (slotsHint) {
          slotsHint.innerHTML = `<span class="text-secondary"><i class="bi bi-people"></i> ${enrolledCount} participant(s) currently enrolled.</span>`;
          slotsHint.classList.remove('d-none');
        }
        document.getElementById('editDescription').value = data.description;
        document.getElementById('editStartDate').value    = data.start_date;
        document.getElementById('editEndDate').value      = data.end_date;

        // Custom (non-consecutive) dates
        const editCustomList   = document.getElementById('editCustomDatesList');
        const editCustomToggle = document.getElementById('editCustomDatesToggle');
        editCustomList.innerHTML = '';
        const hasCustomDates = Array.isArray(data.training_dates) && data.training_dates.length > 0;
        editCustomToggle.checked = hasCustomDates;
        document.getElementById('editCustomDatesWrap').classList.toggle('d-none', !hasCustomDates);
        document.getElementById('editRangeDatesRow').classList.toggle('d-none', hasCustomDates);
        document.getElementById('editStartDate').required = !hasCustomDates;
        document.getElementById('editEndDate').required   = !hasCustomDates;
        if (hasCustomDates) {
          data.training_dates.forEach(d => buildCustomDateRow(editCustomList, d));
        }

        document.getElementById('editDeadline').value     = data.deadline;
        document.getElementById('editForceDisplay').checked = !!data.force_display;

        const type = data.training_type || 'Public-Offered';
        const radio = document.querySelector(`#editTypeSelector input[value="${type}"]`);
        if (radio) radio.checked = true;
        applyTypeConfig(type, 'edit');

        document.getElementById('editTrainingTitle').textContent = `Edit: ${data.name}`;

        // Invite-only link panel (view + copy + regenerate; not toggleable here)
        const invitePanel = document.getElementById('editInviteLinkPanel');
        const isInviteOnly = !!(data.is_invite_only && data.is_invite_only !== '0');
        invitePanel.classList.toggle('d-none', !isInviteOnly);
        editTrainingInviteState = { is_invite_only: isInviteOnly ? 1 : 0, invite_token: data.invite_token || '' };
        if (isInviteOnly) {
          document.getElementById('editInviteLinkRegenBtn').dataset.id = data.id;
          document.getElementById('editInviteLinkBox').value =
            `https://cscro8.com/otrs/index.php?invite=${data.invite_token || ''}`;
        }

        // Metadata
        const fmtMeta = (v) => v ? new Date(v).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }) : '—';
        document.getElementById('editCreatedAt').textContent = fmtMeta(data.created_at);
        document.getElementById('editUpdatedAt').textContent = fmtMeta(data.updated_at);

        // Trigger char counters for pre-filled textareas
        document.getElementById('editTarget').dispatchEvent(new Event('input'));
        document.getElementById('editDescription').dispatchEvent(new Event('input'));

        // Check if deadline is in the past
        const dlWarn = document.getElementById('editDeadlineWarn');
        const dlVal  = document.getElementById('editDeadline').value;
        dlWarn.classList.toggle('d-none', !dlVal || new Date(dlVal) >= new Date().setHours(0,0,0,0));

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editTraining')).show();
      } catch (err) {
        console.error(err);
        Swal.fire('Error', 'Failed to fetch training data.', 'error');
      } finally {
        btn.disabled = false;
      }
    });


    // ══════════════════════════════════════════════════════════════
    // EDIT SUBMIT — AJAX, no reload
    // ══════════════════════════════════════════════════════════════
    document.getElementById('editMe').addEventListener('submit', async function (e) {
      e.preventDefault();
      const submitBtn = document.getElementById('editSubmitBtn');
      submitBtn.disabled = true;
      submitBtn.classList.add('loading');
      submitBtn.innerHTML = '<span class="spinner-border"></span><span class="save-label"> Saving…</span>';

      let sDate = document.getElementById('editStartDate').value;
      let eDate = document.getElementById('editEndDate').value;

      try {
        const fd  = new FormData(this);
        // Normalise fee — strip formatting before sending to server
        fd.set('editFee', stripFee(fd.get('editFee')));

        if (document.getElementById('editCustomDatesToggle').checked) {
          const dates = getCustomDates(document.getElementById('editCustomDatesList'));
          if (dates.length === 0) {
            Swal.fire('Error', 'Add at least one training date.', 'error');
            submitBtn.disabled = false;
            submitBtn.classList.remove('loading');
            submitBtn.innerHTML = '<i class="bi bi-save"></i><span class="save-label"> Save Changes</span>';
            return;
          }
          fd.delete('editStartDate');
          fd.delete('editEndDate');
          dates.forEach(d => fd.append('training_dates[]', d));
          // Keep the local row-update values (sDate/eDate below) in sync with
          // what the server derives (min/max of the custom dates), since
          // editStartDate/editEndDate are hidden/stale in custom mode.
          const sorted = [...dates].sort();
          sDate = sorted[0];
          eDate = sorted[sorted.length - 1];
        }

        const res = await postJSON('api/update_training.php', fd);

        if (res.status !== 'success') { Swal.fire('Error', res.message, 'error'); return; }

        bootstrap.Modal.getInstance(document.getElementById('editTraining')).hide();

        const id          = document.getElementById('trainingId').value;
        const newStatus   = document.getElementById('editStatus').value;
        const statusTrng  = document.getElementById('editStatusTrng').value;
        const selType     = document.querySelector('input[name="training_type"]:checked', this);
        const updatedData = {
          id,
          name:          document.getElementById('editName').value,
          start_date:    sDate,
          end_date:      eDate,
          date:          '',
          location:      document.getElementById('editLocation').value,
          mode:          document.getElementById('editMode').value,
          training_type: selType ? selType.value : 'Public-Offered',
          status_trng:   statusTrng,
          fee:           stripFee(document.getElementById('editFee').value),
          target:        document.getElementById('editTarget').value,
          level:         document.getElementById('editLevel').value,
          slots:         document.getElementById('editSlots').value,
          no_hrs:        document.getElementById('editNoHrs').value,
          status:        newStatus,
          force_display: document.getElementById('editForceDisplay').checked ? 1 : 0,
          description:   document.getElementById('editDescription').value,
          is_invite_only: editTrainingInviteState.is_invite_only,
          invite_token:   editTrainingInviteState.invite_token,
        };

        const $row            = $(`tr[data-training-id='${id}']`);
        const isCurrentActive = $row.closest('table').attr('id') === 'hrdTrainings';
        const willBeActive    = (newStatus === 'active');

        if (isCurrentActive !== willBeActive) {
          // ── Move row between tables ──
          const srcTable = isCurrentActive ? activeTable : inactiveTable;
          const dstTable = willBeActive    ? activeTable : inactiveTable;
          srcTable.row($row).remove().draw(false);
          const newTr = dstTable.row.add(buildRow(updatedData)).draw(false).node();
          activeTable.columns.adjust();
          inactiveTable.columns.adjust();
          flashRow(newTr);
          Swal.fire({ icon: 'success', title: 'Updated!',
            text: `Moved to ${willBeActive ? 'Active' : 'Inactive'}.`,
            timer: 1800, showConfirmButton: false });
          return;
        }

        if ($row.length) {
          // ── In-place cell update ──
          const tb = TYPE_CFG[updatedData.training_type] || TYPE_CFG['Public-Offered'];
          const badgeCls = STATUS_BADGE[statusTrng] || 'bg-secondary';
          // Column indexes: 0=#, 1=Name, 2=Date, 3=Place, 4=Mode, 5=Type, 6=Status,
          //                 7=Fee, 8=Area, 9=Slots, 10=Enrolled, 11=Hrs, 12=Public, 13=Action
          $row.find('td').eq(1).text(updatedData.name);
          $row.find('td').eq(2).text(fmtDate(sDate, eDate));
          $row.find('td').eq(3).text(updatedData.location);
          $row.find('td').eq(4).text(updatedData.mode);
          const editInviteBadge = updatedData.is_invite_only
            ? ` <span class="badge bg-dark" title="Invite-only — hidden from public listings"><i class="bi bi-lock-fill"></i></span>`
            : '';
          $row.find('td').eq(5).html(
            `<span class="badge ${tb.cls}"><i class="bi ${tb.icon}"></i> ${updatedData.training_type}</span>${editInviteBadge}`);
          $row.find('td').eq(6).html(
            `<span class="badge ${badgeCls}">${toTitleCase(esc(statusTrng))}</span>`);
          $row.find('td').eq(7).html(
            updatedData.training_type === 'Free'
              ? `<span class="text-success fw-bold">Free</span>`
              : `<span class="text-nowrap">${fmtPeso(updatedData.fee)}</span>`);
          $row.find('td').eq(8).text(updatedData.level);
          $row.find('td').eq(9).text(updatedData.slots);
          // eq(10) = Enrolled — preserved as-is (server value unchanged by client edit)
          $row.find('td').eq(11).text(updatedData.no_hrs);
          $row.find('td').eq(12).html(
            (newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) +
            (updatedData.force_display ? ' <i class="bi bi-pin-angle-fill text-warning" title="Force Display ON"></i>' : '')
          );
          $row.attr('data-training-name', updatedData.name);
          flashRow($row[0]);
          Swal.fire({ icon: 'success', title: 'Updated!', timer: 1400, showConfirmButton: false });
          return;
        }

        // ── Row not found — append to correct table ──
        const dstTable = willBeActive ? activeTable : inactiveTable;
        const newTr = dstTable.row.add(buildRow(updatedData)).draw(false).node();
        activeTable.columns.adjust();
        inactiveTable.columns.adjust();
        flashRow(newTr);
        Swal.fire({ icon: 'success', title: 'Updated!', timer: 1400, showConfirmButton: false });

      } catch (err) {
        console.error(err);
        Swal.fire('Error', 'Connection failed. Please try again.', 'error');
      } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('loading');
        submitBtn.innerHTML = '<i class="bi bi-save"></i><span class="save-label"> Save Changes</span>';
      }
    });


    // ══════════════════════════════════════════════════════════════
    // ADD TRAINING SUBMIT
    // ══════════════════════════════════════════════════════════════
    document.getElementById('addTrainingForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      const submitBtn = document.getElementById('addSubmitBtn');
      submitBtn.disabled = true;
      submitBtn.classList.add('loading');
      submitBtn.innerHTML = '<span class="spinner-border"></span><span class="save-label"> Publishing…</span>';

      try {
        const fd = new FormData(this);
        fd.set('fee', stripFee(fd.get('fee')));

        if (document.getElementById('addCustomDatesToggle').checked) {
          const dates = getCustomDates(document.getElementById('addCustomDatesList'));
          if (dates.length === 0) {
            Swal.fire('Error', 'Add at least one training date.', 'error');
            return;
          }
          fd.delete('start_date');
          fd.delete('end_date');
          dates.forEach(d => fd.append('training_dates[]', d));
        }

        const res = await postJSON('api/add_training.php', fd);

        if (res.status !== 'success') { Swal.fire('Error', res.message, 'error'); return; }

        bootstrap.Modal.getInstance(document.getElementById('addTrainingModal')).hide();
        this.reset();

        // Fetch the freshly-inserted row and add to active table
        if (res.id) {
          try {
            const newRow = await postJSON('api/fetch_data.php', { trainingId: res.id });
            if (newRow?.id) {
              const newTr = activeTable.row.add(buildRow(newRow)).draw(false).node();
              activeTable.columns.adjust();
              flashRow(newTr);
            }
          } catch (_) { /* fetch_data failed — row will appear on next natural page load */ }
        }

        if (res.invite_link) {
          await Swal.fire({
            icon: 'success',
            title: 'Published — Invite-Only',
            html: `<p>This training is hidden from public listings. Share this link with the invited group:</p>
                   <div class="input-group">
                     <input type="text" class="form-control form-control-sm" id="newInviteLinkBox" value="${res.invite_link}" readonly>
                     <button class="btn btn-outline-secondary btn-sm" type="button" id="newInviteLinkCopyBtn">
                       <i class="bi bi-clipboard"></i> Copy
                     </button>
                   </div>
                   <p class="text-muted small mt-2 mb-0">You can copy this link again later from the "Copy Invite Link" button on this training's row.</p>`,
            confirmButtonText: 'Done',
            didOpen: () => {
              document.getElementById('newInviteLinkCopyBtn').addEventListener('click', (e) => {
                navigator.clipboard.writeText(res.invite_link);
                const btn = e.currentTarget;
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
                setTimeout(() => { btn.innerHTML = original; }, 1200);
              });
            },
          });
        } else {
          Swal.fire({ icon: 'success', title: 'Published!', text: res.message,
                      timer: 1600, showConfirmButton: false });
        }
      } catch (err) {
        console.error(err);
        Swal.fire('Error', 'Failed to add training. Please try again.', 'error');
      } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('loading');
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i><span class="save-label"> Publish Training</span>';
      }
    });


    // ══════════════════════════════════════════════════════════════
    // DELETE TRAINING
    // ══════════════════════════════════════════════════════════════
    async function doDeleteTraining(id, force = 0) {
      const $row = $(`tr[data-training-id='${id}']`);
      try {
        const res = await postJSON('api/delete_training.php', { id, force });

        if (res.status === 'has_registrations') {
          const { value: choice } = await Swal.fire({
            title: 'Training has enrollments',
            html: `<p>This training has <strong>${res.count} enrolled participant(s)</strong>.</p>
                   <p class="text-muted small">Deleting it is irreversible. You can mark it <strong>Inactive</strong> to hide it from public view instead.</p>`,
            icon: 'warning',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Mark Inactive',
            denyButtonText: 'Delete Anyway',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#0077b6',
            denyButtonColor: '#d33',
          });
          if (choice === true) {
            // Fetch full training data then mark inactive
            try {
              const d = await postJSON('api/fetch_data.php', { trainingId: id });
              if (d.id) {
                const fd = new FormData();
                fd.set('trainingId',    d.id);
                fd.set('editName',      d.name);
                fd.set('editLocation',  d.location);
                fd.set('editStartDate', d.start_date);
                fd.set('editEndDate',   d.end_date);
                fd.set('editLink',      d.link);
                fd.set('editDeadline',  d.deadline);
                fd.set('editMode',      d.mode);
                fd.set('editStatusTrng',d.status_trng);
                fd.set('editTarget',    d.target);
                fd.set('editLevel',     d.level);
                fd.set('editSlots',     d.slots);
                fd.set('editNoHrs',     d.no_hrs ?? 0);
                fd.set('editDescription', d.description);
                fd.set('editStatus',    'inactive');
                fd.set('training_type', d.training_type);
                fd.set('editFee',       d.fee);
                fd.set('force_display', d.force_display ? '1' : '0');
                if (Array.isArray(d.training_dates) && d.training_dates.length > 0) {
                  d.training_dates.forEach(dt => fd.append('training_dates[]', dt));
                }
                const upd = await postJSON('api/update_training.php', fd);
                if (upd.status === 'success') {
                  // Move row to inactive table
                  const srcTbl = activeTable.row($row).length ? activeTable : inactiveTable;
                  srcTbl.row($row).remove().draw(false);
                  const d2 = await postJSON('api/fetch_data.php', { trainingId: id });
                  if (d2?.id) { const nr = inactiveTable.row.add(buildRow(d2)).draw(false).node(); flashRow(nr); }
                  activeTable.columns.adjust(); inactiveTable.columns.adjust();
                  Swal.fire({ icon: 'success', title: 'Marked Inactive', timer: 1400, showConfirmButton: false });
                } else { Swal.fire('Error', upd.message, 'error'); }
              }
            } catch (err) { Swal.fire('Error', 'Could not mark inactive.', 'error'); }
          } else if (choice === false) {
            // "Delete Anyway" — denyButton returns false
            await doDeleteTraining(id, 1);
          }
          return;
        }

        if (res.status !== 'success') { Swal.fire('Error', res.message, 'error'); return; }
        if (activeTable.row($row).length)   activeTable.row($row).remove().draw(false);
        if (inactiveTable.row($row).length) inactiveTable.row($row).remove().draw(false);
        activeTable.columns.adjust();
        inactiveTable.columns.adjust();
        Swal.fire({ icon: 'success', title: 'Deleted!', timer: 1000, showConfirmButton: false });
      } catch (err) {
        console.error(err);
        Swal.fire('Error', 'Delete request failed.', 'error');
      }
    }

    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('.delete-training-btn');
      if (!btn) return;

      const { isConfirmed } = await Swal.fire({
        title: 'Delete this training?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it',
      });
      if (!isConfirmed) return;
      await doDeleteTraining(btn.dataset.id);
    });


    // ══════════════════════════════════════════════════════════════
    // COPY INVITE LINK
    // ══════════════════════════════════════════════════════════════
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.copy-invite-link-btn');
      if (!btn) return;

      const token = btn.dataset.token;
      if (!token) {
        Swal.fire('Error', 'No invite link found for this training.', 'error');
        return;
      }
      const link = `https://cscro8.com/otrs/index.php?invite=${token}`;
      navigator.clipboard.writeText(link);
      Swal.fire({ icon: 'success', title: 'Invite link copied!', text: link,
                  timer: 2000, showConfirmButton: false });
    });

    document.getElementById('editInviteLinkCopyBtn')?.addEventListener('click', () => {
      const link = document.getElementById('editInviteLinkBox').value;
      if (!link) return;
      navigator.clipboard.writeText(link);
      Swal.fire({ icon: 'success', title: 'Invite link copied!', timer: 1400, showConfirmButton: false });
    });

    document.getElementById('editInviteLinkRegenBtn')?.addEventListener('click', async () => {
      const trainingId = document.getElementById('editInviteLinkRegenBtn').dataset.id;
      if (!trainingId) return;

      const { value: formValues, isConfirmed } = await Swal.fire({
        title: 'Regenerate Invite Link',
        html: '<p class="text-start">The current link will stop working immediately. '
            + 'Optionally set a custom link below, or leave blank to auto-generate one.</p>'
            + '<input id="swal-regen-slug" class="swal2-input" placeholder="e.g. hr-leaders-2026" maxlength="64">',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Regenerate',
        confirmButtonColor: '#d33',
        preConfirm: () => ({ slug: document.getElementById('swal-regen-slug').value.trim() }),
      });
      if (!isConfirmed) return;

      try {
        const res = await postJSON('api/regenerate_invite_link.php', {
          trainingId, invite_slug: formValues.slug,
        });
        if (res.status !== 'success') { Swal.fire('Error', res.message, 'error'); return; }

        document.getElementById('editInviteLinkBox').value = res.invite_link;
        editTrainingInviteState.invite_token = res.invite_token;

        const $row = $(`tr[data-training-id='${trainingId}']`);
        const linkBtn = $row.find('.copy-invite-link-btn')[0];
        if (linkBtn) linkBtn.dataset.token = res.invite_token;

        Swal.fire({ icon: 'success', title: 'Link Regenerated', html:
          `<div class="input-group input-group-sm"><input class="form-control form-control-sm" value="${res.invite_link}" readonly></div>`,
        });
      } catch (err) {
        console.error(err);
        Swal.fire('Error', 'Failed to regenerate link. Please try again.', 'error');
      }
    });


    // ══════════════════════════════════════════════════════════════
    // TRAINING NAME MANAGEMENT
    // ══════════════════════════════════════════════════════════════

    /** Refresh the Add Training name <select> from the library. */
    async function refreshAddTrainingNameSelect() {
      try {
        const fd = new FormData();
        fd.append('draw', '1');
        fd.append('start', '0');
        fd.append('length', '2000');
        const res = await postJSON('api/fetch_list_trainings.php', fd);
        if (!res.data) return;
        const sel = document.getElementById('addTrainingNameSelect');
        const prev = sel.value;
        sel.innerHTML = '<option value="" hidden>— Select Training —</option>';
        res.data.forEach(item => {
          const opt = document.createElement('option');
          opt.value = item.name;
          opt.textContent = item.name;
          opt.dataset.template = JSON.stringify({
            target:      item.default_target      || '',
            level:       item.default_level        || '',
            description: item.default_description  || '',
          });
          sel.appendChild(opt);
        });
        sel.value = prev;
      } catch (_) {}
    }

    // Auto-fill template fields when a training name is selected in the Publish modal
    document.getElementById('addTrainingNameSelect').addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      if (!opt || !opt.dataset.template) return;
      try {
        const tpl = JSON.parse(opt.dataset.template);
        const form = document.getElementById('addTrainingForm');
        if (tpl.level)       form.querySelector('[name="level"]').value       = tpl.level;
        if (tpl.target)      form.querySelector('[name="target"]').value      = tpl.target;
        if (tpl.description) form.querySelector('[name="description"]').value = tpl.description;
      } catch (_) {}
    });

    /** Hide one modal, then show another only after the first finishes hiding. */
    function switchModal(hideEl, showEl) {
      hideEl.addEventListener('hidden.bs.modal', () => {
        bootstrap.Modal.getOrCreateInstance(showEl).show();
      }, { once: true });
      (bootstrap.Modal.getInstance(hideEl) ?? bootstrap.Modal.getOrCreateInstance(hideEl)).hide();
    }

    // "Manage Training Names" link inside the Publish Training modal — switch without overlap
    let _nameLibOpenedFromPublish = false;
    // Set to true when addTrainingNameMain is being hidden to navigate to a sub-modal
    // (Add Name / Edit Name), so the hidden.bs.modal handler below doesn't misfire.
    let _nameLibSwitching = false;

    document.getElementById('openNameLibFromPublish').addEventListener('click', (e) => {
      e.preventDefault();
      _nameLibOpenedFromPublish = true;
      switchModal(
        document.getElementById('addTrainingModal'),
        document.getElementById('addTrainingNameMain')
      );
    });

    // When the Name Library modal closes, re-open Publish Training if that was the entry point.
    // Skip when the modal was hidden only to switch to a sub-modal (Add/Edit Name).
    document.getElementById('addTrainingNameMain').addEventListener('hidden.bs.modal', async () => {
      if (_nameLibSwitching) { _nameLibSwitching = false; return; }
      if (!_nameLibOpenedFromPublish) return;
      _nameLibOpenedFromPublish = false;
      await refreshAddTrainingNameSelect();
      bootstrap.Modal.getOrCreateInstance(document.getElementById('addTrainingModal')).show();
    });

    // "Add Name" button inside the Name Library modal
    document.getElementById('openAddNameModal').addEventListener('click', () => {
      document.getElementById('trainingNameInput').value = '';
      _nameLibSwitching = true;
      switchModal(
        document.getElementById('addTrainingNameMain'),
        document.getElementById('addTrainingNameModal')
      );
    });

    // "Back" button inside the Add Name modal
    document.getElementById('backToNameLibFromAdd').addEventListener('click', () => {
      switchModal(
        document.getElementById('addTrainingNameModal'),
        document.getElementById('addTrainingNameMain')
      );
    });

    // "Back" button inside the Edit Name modal
    document.getElementById('backToNameLibFromEdit').addEventListener('click', () => {
      switchModal(
        document.getElementById('editTrainingNameModal'),
        document.getElementById('addTrainingNameMain')
      );
    });

    document.getElementById('addTrainingNameForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      try {
        const res = await postJSON('api/add_training_name.php', new FormData(this));
        if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
        this.reset();
        trainingNameTable?.ajax.reload(null, false);
        Swal.fire({ icon: 'success', title: 'Saved!', timer: 900, showConfirmButton: false });
        switchModal(
          document.getElementById('addTrainingNameModal'),
          document.getElementById('addTrainingNameMain')
        );
      } catch (err) { Swal.fire('Error', 'Request failed.', 'error'); }
    });

    // Edit / Delete buttons inside the Training Name Library table
    document.getElementById('addTrainingNameMain').addEventListener('click', async (e) => {
      const editBtn = e.target.closest('.edit-name-btn');
      if (editBtn) {
        const rowData = trainingNameTable?.row(editBtn.closest('tr')).data() || {};
        document.getElementById('editTrainingNameId').value          = editBtn.dataset.id;
        document.getElementById('editTrainingNameInput').value       = editBtn.dataset.name;
        document.getElementById('editTrainingDefaultLevel').value    = rowData.default_level       || '';
        document.getElementById('editTrainingDefaultTarget').value   = rowData.default_target      || '';
        document.getElementById('editTrainingDefaultDesc').value     = rowData.default_description || '';
        _nameLibSwitching = true;
        switchModal(
          document.getElementById('addTrainingNameMain'),
          document.getElementById('editTrainingNameModal')
        );
        return;
      }

      const delBtn = e.target.closest('.delete-name-btn');
      if (!delBtn) return;

      const { isConfirmed } = await Swal.fire({
        title: 'Remove this name?',
        text: 'It will be marked inactive and removed from the library.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, remove it',
      });
      if (!isConfirmed) return;

      try {
        const res = await postJSON('api/delete_single_training.php', { id: delBtn.dataset.id });
        if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
        trainingNameTable?.ajax.reload(null, false);
        await refreshAddTrainingNameSelect();
        Swal.fire({ icon: 'success', title: 'Removed!', timer: 900, showConfirmButton: false });
      } catch (err) { Swal.fire('Error', 'Request failed.', 'error'); }
    });

    document.getElementById('editTrainingNameForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      try {
        const res = await postJSON('api/update_single_training.php', new FormData(this));
        if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
        trainingNameTable?.ajax.reload(null, false);
        await refreshAddTrainingNameSelect();
        Swal.fire({ icon: 'success', title: 'Updated!', timer: 900, showConfirmButton: false });
        switchModal(
          document.getElementById('editTrainingNameModal'),
          document.getElementById('addTrainingNameMain')
        );
      } catch (err) { Swal.fire('Error', 'Request failed.', 'error'); }
    });

    // ══════════════════════════════════════════════════════════════
    // CHARACTER COUNTERS for all maxlength textareas
    // ══════════════════════════════════════════════════════════════
    function initCharCounter(ta) {
      const counter = ta.nextElementSibling;
      if (!counter || !counter.classList.contains('char-counter')) return;
      const max = parseInt(ta.getAttribute('maxlength'), 10);
      const update = () => {
        counter.textContent = `${ta.value.length} / ${max}`;
        counter.classList.toggle('text-danger', (max - ta.value.length) < 50);
      };
      ta.addEventListener('input', update);
      update();
    }
    document.querySelectorAll('textarea[maxlength]').forEach(initCharCounter);


    // ══════════════════════════════════════════════════════════════
    // DEADLINE-IN-PAST WARNING
    // ══════════════════════════════════════════════════════════════
    function checkDeadlineWarn(inputEl, warnEl) {
      if (!inputEl || !warnEl) return;
      const check = () => {
        const v = inputEl.value;
        const isPast = v && new Date(v) < new Date(new Date().toDateString());
        warnEl.classList.toggle('d-none', !isPast);
      };
      inputEl.addEventListener('change', check);
      inputEl.addEventListener('input', check);
      check();
    }
    checkDeadlineWarn(
      document.querySelector('#addTrainingForm [name="deadline"]'),
      document.getElementById('addDeadlineWarn')
    );
    checkDeadlineWarn(
      document.getElementById('editDeadline'),
      document.getElementById('editDeadlineWarn')
    );


    // ══════════════════════════════════════════════════════════════
    // EXPORT BUTTON — exports whichever tab is currently active
    // ══════════════════════════════════════════════════════════════
    document.getElementById('exportActiveBtn').addEventListener('click', () => {
      const activeTab = document.querySelector('.seg-tab.active');
      if (activeTab && activeTab.dataset.bsTarget === '#inactiveTrainings') {
        inactiveTable.button('.buttons-excel').trigger();
      } else {
        activeTable.button('.buttons-excel').trigger();
      }
    });


    // Initialise Bootstrap tooltips on page
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el =>
      new bootstrap.Tooltip(el)
    );


    // ══════════════════════════════════════════════════════════════
    // RESCHEDULE TRAINING
    // ══════════════════════════════════════════════════════════════
    const rescheduleModal = bootstrap.Modal.getOrCreateInstance(
      document.getElementById('rescheduleModal')
    );

    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.reschedule-btn');
      if (!btn || btn.disabled) return;
      document.getElementById('rescheduleTrainingId').value      = btn.dataset.id;
      document.getElementById('rescheduleModalSub').textContent  = btn.dataset.name || '';
      document.getElementById('rescheduleReason').value          = '';
      rescheduleModal.show();
    });

    document.getElementById('rescheduleForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      const submitBtn = this.closest('.modal').querySelector('[type="submit"]');
      submitBtn.disabled = true;
      try {
        const res = await postJSON('api/reschedule_training.php', new FormData(this));
        if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
        rescheduleModal.hide();
        Swal.fire({
          icon: 'success',
          title: 'Marked as Rescheduled',
          text: 'Go to Rescheduled Trainings to transfer paid participants.',
          confirmButtonText: 'Go there now',
          showCancelButton: true,
          cancelButtonText: 'Stay here',
        }).then(r => { if (r.isConfirmed) location.href = 'rescheduled_trainings'; });
        // Update badge in table row
        const id = document.getElementById('rescheduleTrainingId').value;
        const row = document.querySelector(`tr[data-training-id="${id}"]`);
        if (row) {
          const badge = row.querySelector('td span.badge');
          if (badge) {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = 'Rescheduled';
          }
          row.querySelector('.reschedule-btn').disabled = true;
        }
      } catch (_) { Swal.fire('Error', 'Request failed.', 'error'); }
      finally { submitBtn.disabled = false; }
    });


    // ══════════════════════════════════════════════════════════════
    // CUSTOM (NON-CONSECUTIVE) DATES — Add modal
    // ══════════════════════════════════════════════════════════════
    function buildCustomDateRow(container, value = '') {
      const row = document.createElement('div');
      row.className = 'input-group input-group-sm';
      row.innerHTML = `
        <input type="date" class="form-control custom-date-input" value="${value}" required>
        <button type="button" class="btn btn-outline-danger remove-custom-date"><i class="bi bi-x-lg"></i></button>
      `;
      row.querySelector('.remove-custom-date').addEventListener('click', () => row.remove());
      container.appendChild(row);
      return row;
    }

    function getCustomDates(listEl) {
      return Array.from(listEl.querySelectorAll('.custom-date-input'))
        .map(i => i.value)
        .filter(v => v);
    }

    function wireCustomDatesToggle(toggleEl, rangeRowEl, wrapEl, listEl, addBtnEl, seedFromRange = null) {
      addBtnEl.addEventListener('click', () => buildCustomDateRow(listEl));

      toggleEl.addEventListener('change', function () {
        const on = this.checked;
        wrapEl.classList.toggle('d-none', !on);
        rangeRowEl.classList.toggle('d-none', on);
        document.getElementById('addStartDate').required = !on;
        document.getElementById('addEndDate').required   = !on;
        if (on && listEl.children.length === 0) {
          if (seedFromRange) {
            seedFromRange().forEach(d => buildCustomDateRow(listEl, d));
          } else {
            buildCustomDateRow(listEl);
          }
        }
      });
    }

    wireCustomDatesToggle(
      document.getElementById('addCustomDatesToggle'),
      document.getElementById('addRangeDatesRow'),
      document.getElementById('addCustomDatesWrap'),
      document.getElementById('addCustomDatesList'),
      document.getElementById('addCustomDateBtn')
    );


    // ══════════════════════════════════════════════════════════════
    // CUSTOM (NON-CONSECUTIVE) DATES — Edit modal
    // ══════════════════════════════════════════════════════════════
    // Edit modal: toggling custom-on with an empty list seeds from the
    // current start/end range so the admin only has to remove gap days.
    function seedEditDatesFromRange() {
      const s = document.getElementById('editStartDate').value;
      const e = document.getElementById('editEndDate').value || s;
      if (!s) return [];
      const dates = [];
      const cur = new Date(s + 'T00:00:00');
      const last = new Date(e + 'T00:00:00');
      while (cur <= last) {
        dates.push(cur.toISOString().slice(0, 10));
        cur.setDate(cur.getDate() + 1);
      }
      return dates;
    }

    wireCustomDatesToggle(
      document.getElementById('editCustomDatesToggle'),
      document.getElementById('editRangeDatesRow'),
      document.getElementById('editCustomDatesWrap'),
      document.getElementById('editCustomDatesList'),
      document.getElementById('editCustomDateBtn'),
      seedEditDatesFromRange
    );

    // wireCustomDatesToggle() hardcodes addStartDate/addEndDate for the
    // required-toggle (Task 6 shipped it that way), so the Edit modal needs
    // its own listener to correctly toggle required on editStartDate/editEndDate.
    document.getElementById('editCustomDatesToggle').addEventListener('change', function () {
      document.getElementById('editStartDate').required = !this.checked;
      document.getElementById('editEndDate').required   = !this.checked;
    });


    // ══════════════════════════════════════════════════════════════
    // AUTO-DEADLINE — set deadline to start_date − 7 days
    // ══════════════════════════════════════════════════════════════
    function autoFillDeadline(startInput, deadlineInput) {
      if (!startInput || !deadlineInput) return;
      startInput.addEventListener('change', function () {
        if (!this.value) return;
        const d = new Date(this.value);
        d.setDate(d.getDate() - 7);
        const pad = n => String(n).padStart(2, '0');
        deadlineInput.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        deadlineInput.dispatchEvent(new Event('change')); // trigger deadline-warning check
      });
    }
    autoFillDeadline(
      document.querySelector('#addTrainingForm [name="start_date"]'),
      document.querySelector('#addTrainingForm [name="deadline"]')
    );
    autoFillDeadline(
      document.getElementById('editStartDate'),
      document.getElementById('editDeadline')
    );

    // Auto-calculate No. of Hours = (training days − suspended days) × 8
    // getSuspended: optional callback returning the number of suspended days to exclude
    function autoCalcHours(startInput, endInput, hoursInput, getSuspended = () => 0) {
      if (!startInput || !endInput || !hoursInput) return;
      function recalc() {
        if (!startInput.value || !endInput.value) return;
        const start = new Date(startInput.value);
        const end   = new Date(endInput.value);
        if (isNaN(start) || isNaN(end) || end < start) return;
        const days = Math.round((end - start) / 86400000) + 1;
        hoursInput.value = Math.max(0, days - getSuspended()) * 8;
      }
      startInput.addEventListener('change', recalc);
      endInput.addEventListener('change', recalc);
    }

    // New trainings have no suspended days yet
    autoCalcHours(
      document.querySelector('#addTrainingForm [name="start_date"]'),
      document.querySelector('#addTrainingForm [name="end_date"]'),
      document.querySelector('#addTrainingForm [name="no_hrs"]')
    );

    // Edit modal: subtract suspended days already logged for this training
    let editSuspendedCount = 0;
    autoCalcHours(
      document.getElementById('editStartDate'),
      document.getElementById('editEndDate'),
      document.getElementById('editNoHrs'),
      () => editSuspendedCount
    );


    // ══════════════════════════════════════════════════════════════
    // SUSPENDED DAYS
    // ══════════════════════════════════════════════════════════════
    const suspModal = bootstrap.Modal.getOrCreateInstance(
      document.getElementById('suspendedDaysModal')
    );

    // Format YYYY-MM-DD → "Month D, YYYY"
    function fmtSuspDate(iso) {
      if (!iso) return iso;
      const [y, m, d] = iso.split('-').map(Number);
      return new Date(y, m - 1, d).toLocaleDateString('en-PH', {
        year: 'numeric', month: 'long', day: 'numeric'
      });
    }

    // Track already-suspended dates so the date picker can warn before submitting
    let _suspendedDatesSet = new Set();

    async function loadSuspensions(trainingId) {
      const tbody = document.getElementById('suspensionsList');
      const noData = document.getElementById('suspNoData');
      tbody.querySelectorAll('tr:not(#suspNoData)').forEach(r => r.remove());
      noData.style.display = '';
      _suspendedDatesSet.clear();

      try {
        const res = await postJSON('api/fetch_suspensions.php', { training_id: trainingId });
        if (!res.success || !res.data.length) return;
        noData.style.display = 'none';
        res.data.forEach(row => {
          _suspendedDatesSet.add(row.suspended_date);
          const tr = document.createElement('tr');
          tr.dataset.id = row.id;
          tr.innerHTML = `
            <td class="fw-semibold">${fmtSuspDate(row.suspended_date)}</td>
            <td>${esc(row.reason)}</td>
            <td class="text-muted small">${row.created_at ? row.created_at.slice(0, 10) : ''}</td>
            <td class="text-center">
              <button class="btn btn-danger btn-sm del-susp-btn" data-id="${row.id}" title="Remove">
                <i class="bi bi-trash"></i>
              </button>
            </td>`;
          tbody.appendChild(tr);
        });
      } catch (_) {}
    }

    // Open modal on "Suspended Days" button click
    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('.suspended-days-btn');
      if (!btn) return;
      document.getElementById('suspTrainingId').value    = btn.dataset.id;
      document.getElementById('suspModalTitle').textContent = btn.dataset.name || 'Suspended Days';
      document.getElementById('suspModalDates').textContent = btn.dataset.dates || '';
      document.getElementById('addSuspensionForm').reset();
      document.getElementById('suspTrainingId').value = btn.dataset.id; // re-set after reset
      await loadSuspensions(btn.dataset.id);
      suspModal.show();
    });

    // Warn immediately when a date that's already suspended is selected
    document.getElementById('suspDateInput').addEventListener('change', function () {
      if (this.value && _suspendedDatesSet.has(this.value)) {
        Swal.fire({
          icon: 'warning', title: 'Already Suspended',
          text: `${fmtSuspDate(this.value)} is already logged as a suspended day for this training.`,
          confirmButtonText: 'OK',
        });
        this.value = '';
      }
    });

    // Add suspension
    document.getElementById('addSuspensionForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      const trainingId = document.getElementById('suspTrainingId').value;
      try {
        const res = await postJSON('api/add_suspension.php', new FormData(this));
        if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
        document.getElementById('suspDateInput').value   = '';
        document.getElementById('suspReasonInput').value = '';
        await loadSuspensions(trainingId);
        Swal.fire({ icon: 'success', title: 'Logged!', timer: 800, showConfirmButton: false });
      } catch (_) { Swal.fire('Error', 'Request failed.', 'error'); }
    });

    // Delete suspension
    document.getElementById('suspensionsList').addEventListener('click', async (e) => {
      const btn = e.target.closest('.del-susp-btn');
      if (!btn) return;
      const { isConfirmed } = await Swal.fire({
        title: 'Remove this entry?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, remove',
      });
      if (!isConfirmed) return;
      const trainingId = document.getElementById('suspTrainingId').value;
      try {
        const res = await postJSON('api/delete_suspension.php', { id: btn.dataset.id });
        if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
        await loadSuspensions(trainingId);
      } catch (_) { Swal.fire('Error', 'Request failed.', 'error'); }
    });

  })();
  </script>
</body>
</html>