<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

try {
    $rescheduled = $conn->query("
        SELECT
            t.id,
            t.name,
            t.start_date,
            t.end_date,
            t.reschedule_reason,
            t.rescheduled_to_id,
            nt.name        AS new_training_name,
            nt.start_date  AS new_start_date,
            nt.end_date    AS new_end_date,
            (SELECT COUNT(*) FROM otrs_registrations r
             WHERE r.training_id = t.id
               AND r.payment_status IN ('paid','promissory note')
               AND r.registration_status != 'Cancelled') AS paid_count,
            (SELECT COUNT(*) FROM otrs_participant_transfers pt
             WHERE pt.from_training_id = t.id) AS transferred_count,
            (SELECT COUNT(*) FROM otrs_registrations r2
             WHERE r2.training_id = t.id
               AND r2.payment_status = 'pending'
               AND r2.registration_status NOT IN ('Cancelled','not qualified')) AS pending_count
        FROM otrs_trainings t
        LEFT JOIN otrs_trainings nt ON nt.id = t.rescheduled_to_id
        WHERE t.training_status = 'Rescheduled'
        ORDER BY t.start_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming trainings available as transfer targets
    $targetTrainings = $conn->query("
        SELECT id, name, start_date, end_date, slots,
               (SELECT COUNT(*) FROM otrs_registrations r
                WHERE r.training_id = id
                  AND r.registration_status NOT IN ('Cancelled','not qualified')) AS enrolled
        FROM otrs_trainings
        WHERE training_status IN ('Upcoming','upcoming')
          AND status = 'active'
        ORDER BY start_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('rescheduled_trainings.php: ' . $e->getMessage());
    $rescheduled     = [];
    $targetTrainings = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rescheduled Trainings – OTRS HRD</title>
  <link href="../../assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
  <style>
    #transferModal .modal-header {
      background: #1e3a5f; color: #fff;
    }
    .modal-avatar {
      width: 38px; height: 38px; border-radius: 50%;
      background: rgba(255,255,255,.15);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; flex-shrink: 0;
    }
    .modal-title-wrap  { display: flex; align-items: center; gap: .75rem; }
    .modal-title-text h6 { margin: 0; font-size: .95rem; font-weight: 600; }
    .modal-title-text small { font-size: .75rem; }
    .transfer-check { width: 1.1rem; height: 1.1rem; cursor: pointer; }
    .stat-pill {
      display: inline-flex; align-items: center; gap: .35rem;
      padding: .25rem .65rem; border-radius: 999px;
      font-size: .78rem; font-weight: 600;
    }
  </style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">
  <div class="pagetitle otrs-ph">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="otrs-ph-icon icon-amber"><i class="bi bi-calendar-event"></i></div>
        <div>
          <h1>Rescheduled Trainings</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="index">Home</a></li>
              <li class="breadcrumb-item active">Rescheduled Trainings</li>
            </ol>
          </nav>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="ts-badge ts-rescheduled"><i class="bi bi-calendar-event me-1"></i><?= count($rescheduled) ?> rescheduled</span>
        <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
      </div>
    </div>
  </div>

  <section class="section">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-warning text-dark fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-calendar-event"></i> Rescheduled Training Monitor
        <small class="ms-auto fw-normal opacity-75">Transfer paid participants to the new training date</small>
      </div>
      <div class="card-body p-3">

        <?php if (empty($rescheduled)): ?>
          <div class="text-center text-muted py-5 px-3">
            <i class="bi bi-calendar-check display-4 d-block mb-3 text-success"></i>
            No rescheduled trainings at this time.
          </div>
        <?php else: ?>

        <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0" style="font-size:13.5px">
          <thead class="table-dark">
            <tr>
              <th class="text-center" style="width:40px">#</th>
              <th>Training</th>
              <th class="text-nowrap">Original Dates</th>
              <th>Reason</th>
              <th class="text-center">Paid</th>
              <th class="text-center">Transferred</th>
              <th class="text-center">Pending<br><small class="fw-normal opacity-75">(will cancel)</small></th>
              <th>Replacement Training</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rescheduled as $i => $t):
              $s = new DateTime($t['start_date']);
              $e = new DateTime($t['end_date']);
              $displayDate = ($s == $e)
                  ? $s->format('M j, Y')
                  : $s->format('M j') . '–' . $e->format('j, Y');
              $paid        = (int)$t['paid_count'];
              $transferred = (int)$t['transferred_count'];
              $allDone     = ($paid > 0 && $transferred >= $paid);
              $pendActual  = (int)$t['pending_count'];
          ?>
          <tr>
            <td class="text-center"><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
            <td class="text-nowrap"><?= htmlspecialchars($displayDate) ?></td>
            <td><?= htmlspecialchars($t['reschedule_reason'] ?? '—') ?></td>
            <td class="text-center">
              <span class="stat-pill bg-primary text-white"><?= $paid ?></span>
            </td>
            <td class="text-center">
              <span class="stat-pill <?= $transferred > 0 ? 'bg-success text-white' : 'bg-light text-muted border' ?>">
                <?= $transferred ?>
              </span>
            </td>
            <td class="text-center">
              <span class="stat-pill <?= $pendActual > 0 ? 'bg-warning text-dark' : 'bg-light text-muted border' ?>">
                <?= $pendActual ?>
              </span>
            </td>
            <td>
              <?php if ($t['rescheduled_to_id']): ?>
                <span class="text-success fw-semibold">
                  <i class="bi bi-arrow-right-circle-fill me-1"></i>
                  <?= htmlspecialchars($t['new_training_name']) ?>
                  <small class="text-muted fw-normal d-block">
                    <?= (new DateTime($t['new_start_date']))->format('M j') ?>–<?= (new DateTime($t['new_end_date']))->format('j, Y') ?>
                  </small>
                </span>
              <?php else: ?>
                <span class="text-muted fst-italic small">Not yet set</span>
              <?php endif ?>
            </td>
            <td class="text-center text-nowrap">
              <?php if ($allDone): ?>
                <span class="badge bg-success py-2">
                  <i class="bi bi-check-circle me-1"></i> All Transferred
                </span>
              <?php else: ?>
                <button class="btn btn-primary btn-sm transfer-btn"
                        data-id="<?= (int)$t['id'] ?>"
                        data-name="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>">
                  <i class="bi bi-arrow-right-circle"></i> Transfer
                </button>
              <?php endif ?>
            </td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        </div>

        <?php endif ?>

      </div>
    </div>
  </section>
</main>


<!-- ══════════════════════════════════════════════════════
     MODAL: TRANSFER PARTICIPANTS
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title-wrap flex-grow-1">
          <div class="modal-avatar"><i class="bi bi-arrow-right-circle"></i></div>
          <div class="modal-title-text">
            <h6>Transfer Participants</h6>
            <small id="transferModalSub" class="text-white-50"></small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <form id="transferForm" novalidate>
          <input type="hidden" id="transferFromId" name="from_training_id">

          <div class="mb-4">
            <label class="form-label fw-semibold">Transfer to Training <span class="text-danger">*</span></label>
            <select class="form-select" id="transferToId" name="to_training_id" required>
              <option value="">— Select replacement training —</option>
              <?php foreach ($targetTrainings as $tt):
                $avail = (int)$tt['slots'] - (int)$tt['enrolled'];
                $s     = new DateTime($tt['start_date']);
                $e     = new DateTime($tt['end_date']);
                $dStr  = $s->format('M j') . '–' . $e->format('j, Y');
              ?>
              <option value="<?= (int)$tt['id'] ?>" data-available="<?= $avail ?>">
                <?= htmlspecialchars($tt['name']) ?> (<?= $dStr ?>) — <?= $avail ?> slot(s) available
              </option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- Participants list -->
          <div id="transferLoading" class="text-center text-muted py-3 d-none">
            <div class="spinner-border spinner-border-sm me-2"></div> Loading participants…
          </div>
          <div id="transferEmpty" class="text-muted text-center py-3 d-none">
            <i class="bi bi-check-circle-fill text-success me-1"></i>
            No paid participants remaining to transfer.
          </div>
          <div id="transferParticipantsWrap" class="d-none">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="fw-semibold">Paid / Promissory Note Participants</span>
              <div class="d-flex gap-1">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllTransfer">Select All</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="deselectAllTransfer">Deselect All</button>
              </div>
            </div>
            <div class="table-responsive mb-3">
              <table class="table table-bordered table-sm align-middle" style="font-size:13px">
                <thead class="table-light">
                  <tr>
                    <th style="width:40px" class="text-center">
                      <input type="checkbox" class="transfer-check" id="checkAllTransfer" checked>
                    </th>
                    <th>Name</th>
                    <th>Position / Agency</th>
                    <th class="text-center">Payment</th>
                    <th class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody id="transferParticipantsList"></tbody>
              </table>
            </div>
            <div id="pendingCancelNotice" class="alert alert-warning d-flex gap-2 align-items-start py-2 d-none">
              <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
              <div id="pendingCancelMsg"></div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer bg-light border-top">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmTransferBtn" disabled>
          <i class="bi bi-arrow-right-circle"></i> Confirm Transfer
        </button>
      </div>
    </div>
  </div>
</div>


<?php imis_include('footer_db') ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../../assets/js/main.js"></script>

<script>
(() => {
  const csrfToken = window.csrfToken ?? '';

  const postJSON = async (url, data) => {
    let body;
    if (data instanceof FormData) {
      data.append('_csrf', csrfToken);
      body = data;
    } else {
      body = new URLSearchParams({ ...data, _csrf: csrfToken });
    }
    const res = await fetch(url, { method: 'POST', body, headers: { 'X-CSRF-Token': csrfToken } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  };

  const esc = v => String(v ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const transferModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('transferModal'));

  // Open transfer modal
  document.querySelectorAll('.transfer-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      document.getElementById('transferFromId').value         = btn.dataset.id;
      document.getElementById('transferModalSub').textContent = btn.dataset.name || '';
      document.getElementById('transferToId').value           = '';
      document.getElementById('transferParticipantsWrap').classList.add('d-none');
      document.getElementById('transferLoading').classList.add('d-none');
      document.getElementById('transferEmpty').classList.add('d-none');
      document.getElementById('pendingCancelNotice').classList.add('d-none');
      document.getElementById('confirmTransferBtn').disabled  = true;
      document.getElementById('transferParticipantsList').innerHTML = '';
      transferModal.show();
      await loadTransferParticipants(btn.dataset.id);
    });
  });

  async function loadTransferParticipants(trainingId) {
    const wrap    = document.getElementById('transferParticipantsWrap');
    const loading = document.getElementById('transferLoading');
    const empty   = document.getElementById('transferEmpty');
    const tbody   = document.getElementById('transferParticipantsList');

    loading.classList.remove('d-none');
    try {
      const res = await postJSON('api/fetch_rescheduled_participants.php', { training_id: trainingId });
      loading.classList.add('d-none');

      const untransferred = (res.participants || []).filter(p => !parseInt(p.is_transferred));
      if (!untransferred.length) { empty.classList.remove('d-none'); return; }

      tbody.innerHTML = '';
      untransferred.forEach(p => {
        const name = `${esc(p.last_name)}, ${esc(p.first_name)}`
          + (p.middle_name ? ` ${esc(p.middle_name.charAt(0))}.` : '');
        const payBadge = p.payment_status === 'paid'
          ? '<span class="badge bg-success">Paid</span>'
          : '<span class="badge bg-info text-dark">Promissory</span>';
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class="text-center">
            <input type="checkbox" class="transfer-check participant-check"
                   name="registration_ids[]" value="${esc(p.registration_id)}" checked>
          </td>
          <td class="fw-semibold">${name}</td>
          <td>${esc(p.position)}<small class="text-muted d-block">${esc(p.agency)}</small></td>
          <td class="text-center">${payBadge}</td>
          <td class="text-center"><span class="badge bg-secondary">${esc(p.registration_status)}</span></td>`;
        tbody.appendChild(tr);
      });

      wrap.classList.remove('d-none');

      if (res.pending_count > 0) {
        document.getElementById('pendingCancelMsg').innerHTML =
          `<strong>${res.pending_count} pending (unpaid) participant(s)</strong> will be automatically
           <strong>cancelled</strong> when the transfer is confirmed.`;
        document.getElementById('pendingCancelNotice').classList.remove('d-none');
      }

      updateConfirmBtn();
    } catch (_) {
      loading.classList.add('d-none');
      Swal.fire('Error', 'Failed to load participants.', 'error');
    }
  }

  function updateConfirmBtn() {
    const anyChecked = document.querySelectorAll('.participant-check:checked').length > 0;
    const hasTarget  = document.getElementById('transferToId').value !== '';
    document.getElementById('confirmTransferBtn').disabled = !(anyChecked && hasTarget);
  }

  document.getElementById('transferToId').addEventListener('change', updateConfirmBtn);
  document.getElementById('transferParticipantsList').addEventListener('change', e => {
    if (e.target.classList.contains('participant-check')) updateConfirmBtn();
  });
  document.getElementById('checkAllTransfer').addEventListener('change', function () {
    document.querySelectorAll('.participant-check').forEach(c => c.checked = this.checked);
    updateConfirmBtn();
  });
  document.getElementById('selectAllTransfer').addEventListener('click', () => {
    document.querySelectorAll('.participant-check').forEach(c => c.checked = true);
    document.getElementById('checkAllTransfer').checked = true;
    updateConfirmBtn();
  });
  document.getElementById('deselectAllTransfer').addEventListener('click', () => {
    document.querySelectorAll('.participant-check').forEach(c => c.checked = false);
    document.getElementById('checkAllTransfer').checked = false;
    updateConfirmBtn();
  });

  // Confirm transfer
  document.getElementById('confirmTransferBtn').addEventListener('click', async () => {
    const toId   = document.getElementById('transferToId').value;
    const checked = [...document.querySelectorAll('.participant-check:checked')];
    if (!toId || !checked.length) return;

    const { isConfirmed } = await Swal.fire({
      title: `Transfer ${checked.length} participant(s)?`,
      text: 'Pending (unpaid) participants in the original training will be cancelled.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, transfer',
      confirmButtonColor: '#0d6efd',
    });
    if (!isConfirmed) return;

    const btn = document.getElementById('confirmTransferBtn');
    btn.disabled = true;
    try {
      const res = await postJSON('api/transfer_participants.php',
        new FormData(document.getElementById('transferForm')));
      if (!res.success) { Swal.fire('Error', res.error, 'error'); return; }
      transferModal.hide();
      await Swal.fire({
        icon: 'success',
        title: 'Transfer Complete',
        text: `${res.transferred} participant(s) moved successfully.`,
      });
      location.reload();
    } catch (_) {
      Swal.fire('Error', 'Request failed.', 'error');
    } finally {
      btn.disabled = false;
    }
  });

})();
</script>
</body>
</html>
