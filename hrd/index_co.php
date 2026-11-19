<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

// Load current collecting officers
try {
    $stmtAll = $conn->prepare(
        "SELECT id, location, name, position
         FROM   otrs_collecting_officer
         ORDER  BY location ASC, name ASC"
    );
    $stmtAll->execute();
    $officers = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $officers = [];
    error_log('[index_co] ' . $e->getMessage());
}

$foOptions = [
    'LFOI'  => 'CSC Field Office - Leyte I',
    'LFOII' => 'CSC Field Office - Leyte II',
    'WLSO'  => 'CSC Satellite Office - Western Leyte',
    'SLFO'  => 'CSC Field Office - Southern Leyte',
    'BFO'   => 'CSC Field Office - Biliran',
    'SFO'   => 'CSC Field Office - Samar',
    'ESFO'  => 'CSC Field Office - Eastern Samar',
    'NSFO'  => 'CSC Field Office - Northern Samar',
    'HRD'   => 'CSC RO VIII - Human Resource Division',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>OTRS - Manage Collecting Officer</title>
  <link href="../assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
</head>
<body>

  <?php imis_include('header_js') ?>
  <?php include 'inc/sidebar.php' ?>

  <main id="main" class="main">
    <div class="pagetitle otrs-ph">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <div class="otrs-ph-icon icon-blue"><i class="bi bi-person-vcard"></i></div>
          <div>
            <h1>Manage Collecting Officer</h1>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index">Home</a></li>
                <li class="breadcrumb-item">HRD</li>
                <li class="breadcrumb-item active">Collecting Officer</li>
              </ol>
            </nav>
          </div>
        </div>
        <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
      </div>
    </div>

    <section class="section">
      <div class="otrs-card">
        <div class="otrs-card-top">
          <p class="otrs-section-label mb-0"><i class="bi bi-person-vcard"></i> Officer Registry</p>
          <button type="button" class="btn-f btn-f-primary" id="addCollectingOfficerBtn">
            <i class="bi bi-plus-circle"></i> Add Officer
          </button>
        </div>
        <div class="otrs-card-body">
          <div class="table-responsive">
            <table id="collectingOfficer" class="table table-bordered table-hover table-striped otrs-table w-100">
              <thead>
                <tr>
                  <th>RO/FO</th>
                  <th>Name of Collecting Officer</th>
                  <th>Position</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($officers as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($foOptions[$row['location']] ?? $row['location']) ?></td>
                  <td><?= htmlspecialchars($row['name']) ?></td>
                  <td><?= htmlspecialchars($row['position']) ?></td>
                  <td class="text-center">
                    <button class="btn-tbl btn-tbl-primary update-button"
                            data-id="<?= (int)$row['id'] ?>"
                            data-location="<?= htmlspecialchars($row['location']) ?>"
                            data-name="<?= htmlspecialchars($row['name']) ?>" title="Edit">
                      <i class="bi bi-pencil-square"></i>
                    </button>
                    <button class="btn-tbl btn-tbl-danger delete-button"
                            data-id="<?= (int)$row['id'] ?>" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </main>

  <?php imis_include('footer') ?>

  <!-- Add Modal -->
  <div class="modal fade" id="addCOModal" tabindex="-1" aria-labelledby="addCOModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header modal-header-blue">
          <div class="modal-title-wrap">
            <div class="modal-avatar"><i class="bi bi-person-plus-fill"></i></div>
            <div class="modal-title-text">
              <h6 id="addCOModalLabel">Add Collecting Officer</h6>
              <small>Assign a staff member as collecting officer</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="addCOForm">
            <div class="mb-3">
              <label class="form-label"><b>Select FO / Office:</b> <span class="text-danger">*</span></label>
              <select class="form-select" id="addLocation" required>
                <option value="" hidden>Select Field Office</option>
                <?php foreach ($foOptions as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3" id="addUserGroup" style="display:none">
              <label class="form-label"><b>Select Staff:</b> <span class="text-danger">*</span></label>
              <select class="form-select" id="addUserSelect">
                <option value="" hidden>-- Select staff member --</option>
              </select>
            </div>
            <div class="mb-3" id="addPositionGroup" style="display:none">
              <label class="form-label"><b>Position:</b></label>
              <input type="text" class="form-control" id="addPosition" readonly>
            </div>
            <input type="hidden" id="addUserId">
          </form>
        </div>
        <div class="modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i> Close</button>
          <button type="button" class="btn-modal-save" id="addSubmitBtn"><i class="bi bi-save me-1"></i> Save</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editCOModal" tabindex="-1" aria-labelledby="editCOModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header modal-header-blue">
          <div class="modal-title-wrap">
            <div class="modal-avatar"><i class="bi bi-pencil-square"></i></div>
            <div class="modal-title-text">
              <h6 id="editCOModalLabel">Edit Collecting Officer</h6>
              <small>Update the assigned collecting officer</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editCOForm">
            <input type="hidden" id="editId">
            <input type="hidden" id="editUserId">
            <div class="mb-3">
              <label class="form-label"><b>Select FO / Office:</b> <span class="text-danger">*</span></label>
              <select class="form-select" id="editLocation" required>
                <option value="" hidden>Select Field Office</option>
                <?php foreach ($foOptions as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label"><b>Select Staff:</b> <span class="text-danger">*</span></label>
              <select class="form-select" id="editUserSelect">
                <option value="" disabled>Select FO first to load staff</option>
              </select>
              <div class="form-text text-muted mt-1" id="editCurrentName"></div>
            </div>
            <div class="mb-3">
              <label class="form-label"><b>Position:</b></label>
              <input type="text" class="form-control" id="editPosition" readonly>
            </div>
          </form>
        </div>
        <div class="modal-footer-branded">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i> Close</button>
          <button type="button" class="btn-modal-save" id="editSubmitBtn"><i class="bi bi-save me-1"></i> Update</button>
        </div>
      </div>
    </div>
  </div>

  <a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

  <script>
    // FO label map from PHP
    const FO_LABELS = <?= json_encode($foOptions) ?>;

    // Fetch API helper — posts as FormData with CSRF token
    async function postJSON(url, data) {
      const token = window.csrfToken ?? '';
      const fd = new FormData();
      Object.entries(data).forEach(([k, v]) => fd.append(k, v));
      fd.append('_csrf', token);
      const res = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': token } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    }

    // HTML-escape for attribute/innerHTML injection
    function esc(str) {
      return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Build action buttons HTML for a newly added row
    function actionCell(id, location, name) {
      return '<button class="btn-tbl btn-tbl-primary update-button" title="Edit" '
           +   'data-id="' + esc(id) + '" '
           +   'data-location="' + esc(location) + '" '
           +   'data-name="' + esc(name) + '">'
           +   '<i class="bi bi-pencil-square"></i>'
           + '</button>'
           + '<button class="btn-tbl btn-tbl-danger delete-button" title="Delete" data-id="' + esc(id) + '">'
           +   '<i class="bi bi-trash"></i>'
           + '</button>';
    }

    // Populate a <select> with staff from users_cscro8 for a given FO location
    async function loadStaff(location, selectEl, currentName) {
      selectEl.innerHTML = '<option value="" disabled>Loading...</option>';
      try {
        const users = await postJSON('api/fetch_co_users.php', { location });
        selectEl.innerHTML = '<option value="" hidden>-- Select staff member --</option>';
        if (!Array.isArray(users) || users.length === 0) {
          selectEl.innerHTML += '<option value="" disabled>No staff found for this office</option>';
          return;
        }
        users.forEach(function (u) {
          const opt = document.createElement('option');
          opt.value = u.id;
          opt.textContent = u.name;
          opt.dataset.position = u.position;
          if (currentName && u.name === currentName) opt.selected = true;
          selectEl.appendChild(opt);
        });
        // If a user was pre-selected by name, auto-fill position + userId
        const preSelected = selectEl.querySelector('option[selected]') || selectEl.querySelector('option:checked');
        if (preSelected && preSelected.value) {
          const body = selectEl.closest('.modal-body');
          const posEl = body.querySelector('[id$="Position"]');
          const uidEl = body.querySelector('[id$="UserId"]');
          if (posEl) posEl.value = preSelected.dataset.position || '';
          if (uidEl) uidEl.value = preSelected.value;
        }
      } catch (err) {
        selectEl.innerHTML = '<option value="" disabled>Failed to load staff</option>';
      }
    }

    $(function () {
      // Initialize DataTable
      const table = $('#collectingOfficer').DataTable({ responsive: true });

      /* ============================================================
         ADD
      ============================================================ */
      document.getElementById('addCollectingOfficerBtn').addEventListener('click', function () {
        document.getElementById('addCOForm').reset();
        document.getElementById('addUserGroup').style.display    = 'none';
        document.getElementById('addPositionGroup').style.display = 'none';
        document.getElementById('addUserSelect').innerHTML = '<option value="" hidden>-- Select staff member --</option>';
        document.getElementById('addUserId').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addCOModal')).show();
      });

      document.getElementById('addCOModal').addEventListener('shown.bs.modal', function () {
        document.getElementById('addLocation').focus();
      });

      document.getElementById('addLocation').addEventListener('change', function () {
        var loc = this.value;
        document.getElementById('addUserGroup').style.display    = loc ? '' : 'none';
        document.getElementById('addPositionGroup').style.display = loc ? '' : 'none';
        if (!loc) return;
        loadStaff(loc, document.getElementById('addUserSelect'), null);
        document.getElementById('addPosition').value = '';
        document.getElementById('addUserId').value   = '';
      });

      document.getElementById('addUserSelect').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        document.getElementById('addPosition').value = opt.dataset.position || '';
        document.getElementById('addUserId').value   = this.value;
      });

      document.getElementById('addSubmitBtn').addEventListener('click', async function () {
        var location = document.getElementById('addLocation').value;
        var userId   = document.getElementById('addUserId').value;
        if (!location || !userId) {
          Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a field office and a staff member.' });
          return;
        }
        try {
          var res = await postJSON('api/add_co.php', { user_id: userId, location: location });
          bootstrap.Modal.getInstance(document.getElementById('addCOModal')).hide();
          if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            return;
          }
          // Insert new row into DataTable without page reload
          var d = res.data;
          var $tr = $('<tr>')
            .append($('<td>').text(FO_LABELS[d.location] || d.location))
            .append($('<td>').text(d.name))
            .append($('<td>').text(d.position))
            .append($('<td>').html(actionCell(d.id, d.location, d.name)));

          var node = table.row.add($tr[0]).draw(false).node();
          $(node).addClass('table-success');
          setTimeout(function () { $(node).removeClass('table-success'); }, 2500);

          Swal.fire({ icon: 'success', title: 'Added', text: res.message, timer: 2000, showConfirmButton: false });
        } catch (err) {
          Swal.fire({ icon: 'error', title: 'Error', text: 'Oops! Something went wrong.' });
        }
      });

      /* ============================================================
         EDIT
      ============================================================ */
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.update-button');
        if (!btn) return;

        var id   = btn.dataset.id;
        var loc  = btn.dataset.location;
        var name = btn.dataset.name;

        document.getElementById('editId').value           = id;
        document.getElementById('editLocation').value     = loc;
        document.getElementById('editCurrentName').textContent = 'Current: ' + name;
        document.getElementById('editPosition').value     = '';
        document.getElementById('editUserId').value       = '';

        loadStaff(loc, document.getElementById('editUserSelect'), name);
        document.getElementById('editCOModalLabel').innerHTML =
          'Edit Collecting Officer &mdash; <strong>' + esc(name) + '</strong>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editCOModal')).show();
      });

      document.getElementById('editLocation').addEventListener('change', function () {
        if (!this.value) return;
        loadStaff(this.value, document.getElementById('editUserSelect'), null);
        document.getElementById('editPosition').value = '';
        document.getElementById('editUserId').value   = '';
      });

      document.getElementById('editUserSelect').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        document.getElementById('editPosition').value = opt.dataset.position || '';
        document.getElementById('editUserId').value   = this.value;
      });

      document.getElementById('editSubmitBtn').addEventListener('click', async function () {
        var id       = document.getElementById('editId').value;
        var location = document.getElementById('editLocation').value;
        var userId   = document.getElementById('editUserId').value;
        if (!id || !location || !userId) {
          Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a field office and a staff member.' });
          return;
        }
        try {
          var res = await postJSON('api/update_co.php', { id: id, user_id: userId, location: location });
          bootstrap.Modal.getInstance(document.getElementById('editCOModal')).hide();
          if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            return;
          }
          // Update the existing row in-place — no reload
          var d   = res.data;
          var btn = document.querySelector('.update-button[data-id="' + id + '"]');
          if (btn) {
            var tr = btn.closest('tr');
            // Refresh data attributes for future edits
            btn.dataset.location = d.location;
            btn.dataset.name     = d.name;
            // Update visible cell text
            tr.cells[0].textContent = FO_LABELS[d.location] || d.location;
            tr.cells[1].textContent = d.name;
            tr.cells[2].textContent = d.position;
            // Tell DataTables the row data changed for search/sort
            table.row(tr).invalidate().draw(false);
            $(tr).addClass('table-info');
            setTimeout(function () { $(tr).removeClass('table-info'); }, 2500);
          }
          Swal.fire({ icon: 'success', title: 'Updated', text: res.message, timer: 2000, showConfirmButton: false });
        } catch (err) {
          Swal.fire({ icon: 'error', title: 'Error', text: 'Oops! Something went wrong.' });
        }
      });

      /* ============================================================
         DELETE
      ============================================================ */
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.delete-button');
        if (!btn) return;
        var id = btn.dataset.id;
        Swal.fire({
          icon: 'warning',
          title: 'Are you sure?',
          text: 'This will permanently remove this collecting officer.',
          showCancelButton: true,
          confirmButtonText: 'Delete',
          cancelButtonText: 'Cancel',
          reverseButtons: true,
        }).then(async function (result) {
          if (!result.isConfirmed) return;
          try {
            var res = await postJSON('api/delete_co.php', { id: id });
            if (!res.success) {
              Swal.fire({ icon: 'error', title: 'Error', text: res.message });
              return;
            }
            // Remove the row from DataTable without reload
            table.row(btn.closest('tr')).remove().draw(false);
            Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 2000, showConfirmButton: false });
          } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Oops! Something went wrong.' });
          }
        });
      });
    });
  </script>
</body>
</html>
