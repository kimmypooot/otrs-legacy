<?php
// imis/otrs/hrd/sme_management.php
// Global SME (Subject Matter Expert) Registry — CRUD management page.
// Supports two SME types: Internal (CSC RO VIII employees) and External.

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SME Management — OTRS HRD</title>

  <link href="../assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

  <style>
    /* ── Avatar ─────────────────────────────────────────────────────── */
    .sme-avatar {
      width: 40px; height: 40px; border-radius: 50%;
      color: #fff; font-weight: 700; font-size: .95rem;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; overflow: hidden;
    }
    .sme-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
    .sme-avatar.avatar-internal { background: linear-gradient(135deg, #0891b2, #0369a1); }
    .sme-avatar.avatar-external { background: linear-gradient(135deg, #7c3aed, #4f46e5); }

    /* ── Photo upload (external modal) ──────────────────────────────── */
    .sme-photo-wrap {
      width: 88px; height: 88px; border-radius: 50%; overflow: hidden;
      border: 2px dashed #cbd5e1; cursor: pointer; position: relative;
      flex-shrink: 0; background: #f8fafc;
      display: flex; align-items: center; justify-content: center;
    }
    .sme-photo-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .sme-photo-wrap .photo-placeholder { color: #94a3b8; text-align: center; font-size: .7rem; line-height: 1.3; pointer-events: none; }
    .sme-photo-wrap .photo-overlay {
      position: absolute; inset: 0; background: rgba(0,0,0,.38);
      display: none; align-items: center; justify-content: center;
      color: #fff; font-size: 1.1rem;
    }
    .sme-photo-wrap:hover .photo-overlay { display: flex; }

    /* ── Employee search dropdown thumbnail ──────────────────────────── */
    .user-result-avatar {
      width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
      background: linear-gradient(135deg, #0891b2, #0369a1);
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: .72rem; font-weight: 700;
    }
    .user-result-avatar img { width: 100%; height: 100%; object-fit: cover; }

    /* ── Stat cards ──────────────────────────────────────────────────── */
    .stat-card {
      border-radius: 12px; padding: 1.2rem 1.5rem;
      display: flex; align-items: center; gap: 1rem;
    }
    .stat-icon {
      width: 48px; height: 48px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.4rem; flex-shrink: 0;
    }
    .stat-label { font-size: .74rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
    .stat-value { font-size: 1.6rem; font-weight: 800; color: #0f172a; line-height: 1; }

    /* ── Type badges ─────────────────────────────────────────────────── */
    .badge-sme-internal {
      display: inline-flex; align-items: center; gap: .28rem;
      background: #cffafe; color: #0e7490;
      font-size: .68rem; font-weight: 600;
      padding: .22rem .6rem; border-radius: 999px;
      white-space: nowrap;
    }
    .badge-sme-external {
      display: inline-flex; align-items: center; gap: .28rem;
      background: #ede9fe; color: #6d28d9;
      font-size: .68rem; font-weight: 600;
      padding: .22rem .6rem; border-radius: 999px;
      white-space: nowrap;
    }
    .badge-active   { background: #d1fae5; color: #065f46; font-size: .68rem; font-weight:600; padding:.22rem .55rem; border-radius:999px; }
    .badge-inactive { background: #fee2e2; color: #991b1b; font-size: .68rem; font-weight:600; padding:.22rem .55rem; border-radius:999px; }

    /* ── Filter bar ──────────────────────────────────────────────────── */
    .filter-bar { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
    .filter-bar .form-select { font-size: .8rem; }

    /* ── Type toggle cards in modal ──────────────────────────────────── */
    .sme-type-toggle { display: flex; gap: .75rem; }
    .sme-type-card {
      flex: 1; border: 2px solid #e2e8f0; border-radius: 10px;
      padding: .9rem 1rem; cursor: pointer; transition: all .18s ease;
      display: flex; align-items: flex-start; gap: .75rem;
      background: #f8fafc;
    }
    .sme-type-card:hover { border-color: #94a3b8; background: #f1f5f9; }
    .sme-type-card.selected-internal {
      border-color: #0891b2; background: #ecfeff;
      box-shadow: 0 0 0 3px rgba(8,145,178,.12);
    }
    .sme-type-card.selected-external {
      border-color: #7c3aed; background: #f5f3ff;
      box-shadow: 0 0 0 3px rgba(124,58,237,.12);
    }
    .sme-type-card-icon {
      width: 36px; height: 36px; border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; flex-shrink: 0;
    }
    .sme-type-card-icon.icon-internal { background: #cffafe; color: #0891b2; }
    .sme-type-card-icon.icon-external { background: #ede9fe; color: #7c3aed; }
    .sme-type-card-label { font-size: .8rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
    .sme-type-card-sub   { font-size: .71rem; color: #64748b; margin-top: .15rem; }

    /* ── User search dropdown ────────────────────────────────────────── */
    .user-search-wrap { position: relative; }
    .user-search-input { font-size: .85rem; }
    .user-search-dropdown {
      position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 1055;
      background: #fff; border: 1px solid #cbd5e1; border-radius: 8px;
      max-height: 220px; overflow-y: auto;
      box-shadow: 0 4px 16px rgba(0,0,0,.12);
      display: none;
    }
    .user-search-dropdown.open { display: block; }
    .user-search-result {
      padding: .55rem .85rem; cursor: pointer; border-bottom: 1px solid #f1f5f9;
      transition: background .12s;
    }
    .user-search-result:last-child { border-bottom: none; }
    .user-search-result:hover { background: #f0f9ff; }
    .user-search-result-name { font-size: .82rem; font-weight: 600; color: #0f172a; }
    .user-search-result-sub  { font-size: .72rem; color: #64748b; }
    .user-search-empty { padding: .7rem .85rem; font-size: .8rem; color: #94a3b8; text-align: center; }

    /* ── Selected user chip ──────────────────────────────────────────── */
    .selected-user-chip {
      display: flex; align-items: center; gap: .6rem;
      background: #ecfeff; border: 1.5px solid #a5f3fc;
      border-radius: 8px; padding: .5rem .85rem;
      font-size: .8rem;
    }
    .selected-user-chip .chip-name { font-weight: 600; color: #0e7490; }
    .selected-user-chip .chip-sub  { color: #64748b; font-size: .72rem; }
    .chip-clear { background: none; border: none; color: #0e7490; font-size: .75rem;
                  cursor: pointer; margin-left: auto; padding: 0; text-decoration: underline; }
    .chip-clear:hover { color: #dc2626; }
  </style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

  <div class="pagetitle otrs-ph">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="otrs-ph-icon" style="background:#cffafe;color:#0891b2;"><i class="bi bi-person-video3"></i></div>
        <div>
          <h1>SME Management</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="index">Home</a></li>
              <li class="breadcrumb-item active">SME Management</li>
            </ol>
          </nav>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button class="btn-f btn-f-info" id="btnAddSme">
          <i class="bi bi-plus-lg"></i> Add SME
        </button>
        <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
      </div>
    </div>
  </div>

  <section class="section">

    <!-- ── STAT CARDS ──────────────────────────────────────────────── -->
    <div class="row g-3 mb-4" id="statRow">
      <div class="col-6 col-sm-4 col-lg">
        <div class="stat-card" style="background:#f0f9ff;border:1.5px solid #bae6fd;">
          <div class="stat-icon" style="background:#e0f2fe;color:#0369a1;"><i class="bi bi-person-video3"></i></div>
          <div>
            <div class="stat-label">Total SMEs</div>
            <div class="stat-value" id="statTotal">—</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-sm-4 col-lg">
        <div class="stat-card" style="background:#ecfeff;border:1.5px solid #a5f3fc;">
          <div class="stat-icon" style="background:#cffafe;color:#0891b2;"><i class="bi bi-building-fill"></i></div>
          <div>
            <div class="stat-label">Internal</div>
            <div class="stat-value" id="statInternal">—</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-sm-4 col-lg">
        <div class="stat-card" style="background:#f5f3ff;border:1.5px solid #ddd6fe;">
          <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;"><i class="bi bi-globe"></i></div>
          <div>
            <div class="stat-label">External</div>
            <div class="stat-value" id="statExternal">—</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-sm-4 col-lg">
        <div class="stat-card" style="background:#f0fdf4;border:1.5px solid #bbf7d0;">
          <div class="stat-icon" style="background:#dcfce7;color:#15803d;"><i class="bi bi-check-circle-fill"></i></div>
          <div>
            <div class="stat-label">Active</div>
            <div class="stat-value" id="statActive">—</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-sm-4 col-lg">
        <div class="stat-card" style="background:#fff7ed;border:1.5px solid #fed7aa;">
          <div class="stat-icon" style="background:#ffedd5;color:#c2410c;"><i class="bi bi-pause-circle-fill"></i></div>
          <div>
            <div class="stat-label">Inactive</div>
            <div class="stat-value" id="statInactive">—</div>
          </div>
        </div>
      </div>
    </div>

    <div class="otrs-card">
      <div class="otrs-card-top">
        <p class="otrs-section-label mb-0"><i class="bi bi-people-fill"></i> Subject Matter Expert (SME) Registry</p>
        <div class="filter-bar">
          <select id="filterType" class="form-select form-select-sm" style="width:150px;">
            <option value="">All Types</option>
            <option value="internal">Internal</option>
            <option value="external">External</option>
          </select>
          <select id="filterStatus" class="form-select form-select-sm" style="width:140px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="otrs-card-body">
        <div class="table-responsive">
          <table id="smeTable" class="table table-bordered table-hover table-striped otrs-table w-100">
            <thead>
              <tr>
                <th class="text-center">#</th>
                <th>Name &amp; Expertise</th>
                <th>Designation</th>
                <th>Agency / Division</th>
                <th>Email</th>
                <th>Mobile</th>
                <th class="text-center">Type</th>
                <th class="text-center">Trainings</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

</main>

<?php imis_include('footer_db') ?>

<!-- ══ ADD / EDIT MODAL ══════════════════════════════════════════════ -->
<div class="modal fade" id="smeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header modal-header-teal">
        <div class="modal-title-wrap">
          <div class="modal-avatar"><i class="bi bi-person-video3"></i></div>
          <div class="modal-title-text">
            <h6 id="smeModalTitle">Add Subject Matter Expert (SME)</h6>
            <small id="smeModalSubtitle">Global SME registry entry</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <form id="smeForm" novalidate>
          <input type="hidden" id="smeId"     name="id">
          <input type="hidden" id="smeUserId" name="user_id">
          <!-- sme_type is appended programmatically via JS — not a named form field -->

          <!-- ── Type toggle ──────────────────────────────────────── -->
          <div class="mb-4">
            <label class="form-label fw-semibold mb-2">SME Type <span class="text-danger">*</span></label>
            <div class="sme-type-toggle" id="smeTypeToggle">
              <div class="sme-type-card" id="cardInternal" onclick="setSmeType('internal')">
                <div class="sme-type-card-icon icon-internal"><i class="bi bi-building-fill"></i></div>
                <div>
                  <div class="sme-type-card-label">Internal</div>
                  <div class="sme-type-card-sub">CSC RO VIII Employee</div>
                </div>
              </div>
              <div class="sme-type-card" id="cardExternal" onclick="setSmeType('external')">
                <div class="sme-type-card-icon icon-external"><i class="bi bi-globe"></i></div>
                <div>
                  <div class="sme-type-card-label">External</div>
                  <div class="sme-type-card-sub">Other Organization / Speaker</div>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Internal: employee search ───────────────────────── -->
          <div id="sectionInternal" style="display:none;">
            <div class="mb-3">
              <label class="form-label fw-semibold">Search CSC RO VIII Employee</label>
              <div class="user-search-wrap">
                <input type="text" class="form-control user-search-input" id="userSearchInput"
                       placeholder="Type a name to search active employees…" autocomplete="off">
                <div class="user-search-dropdown" id="userSearchDropdown"></div>
              </div>
              <div id="selectedUserChip" class="mt-2" style="display:none;"></div>
            </div>
          </div>

          <!-- ── External: optional photo upload ─────────────────── -->
          <div id="sectionExternalPhoto" class="mb-3" style="display:none;">
            <label class="form-label fw-semibold">Profile Photo <span class="text-muted fw-normal">(optional)</span></label>
            <div class="d-flex align-items-center gap-3">
              <div class="sme-photo-wrap" id="smePhotoWrap" title="Click to upload photo">
                <div class="photo-placeholder" id="smePhotoPlaceholder">
                  <i class="bi bi-person-fill" style="font-size:1.6rem;display:block;margin-bottom:.2rem;"></i>
                  Click to upload
                </div>
                <img id="smePhotoPreview" src="" alt="" style="display:none;">
                <div class="photo-overlay"><i class="bi bi-camera-fill"></i></div>
              </div>
              <div>
                <div style="font-size:.75rem;color:#64748b;">JPG, PNG, WebP — max 2 MB</div>
                <button type="button" id="btnClearSmePhoto"
                        class="btn btn-sm btn-outline-danger mt-2 py-0 px-2" style="font-size:.72rem;display:none;">
                  <i class="bi bi-x-lg me-1"></i>Remove photo
                </button>
              </div>
            </div>
            <input type="file" id="smePhotoInput" name="sme_photo" accept="image/*" class="d-none">
            <input type="hidden" id="smeClearPhoto" name="clear_photo" value="0">
          </div>

          <!-- ── Shared fields ────────────────────────────────────── -->
          <div class="row g-3 mb-3">
            <div class="col-sm-7">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="smeName" name="name"
                     placeholder="e.g. Juan dela Cruz" maxlength="128">
              <div class="invalid-feedback">Name is required.</div>
            </div>
            <div class="col-sm-5">
              <label class="form-label fw-semibold">Designation / Title</label>
              <input type="text" class="form-control" id="smeDesignation" name="designation"
                     placeholder="e.g. Division Chief" maxlength="128">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" class="form-control" id="smeEmail" name="email"
                     placeholder="juan@agency.gov.ph" maxlength="128">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Mobile No.</label>
              <input type="text" class="form-control" id="smeMobile" name="mobile_no"
                     placeholder="09XXXXXXXXX" maxlength="20">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Agency / Organization</label>
              <input type="text" class="form-control" id="smeAgency" name="agency"
                     placeholder="e.g. Civil Service Commission" maxlength="255">
              <div class="form-text" id="agencyNote" style="display:none; color:#0891b2;">
                <i class="bi bi-info-circle"></i> Auto-set to "Civil Service Commission – Regional Office VIII" for internal SMEs.
              </div>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Areas of Expertise</label>
              <input type="text" class="form-control" id="smeExpertise" name="expertise"
                     placeholder="e.g. Human Resource Management, Strategic Planning" maxlength="512">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Bio / Notes</label>
              <textarea class="form-control" id="smeBio" name="bio" rows="3"
                        placeholder="Brief background or notes…"></textarea>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-sm-4">
              <label class="form-label fw-semibold">Status</label>
              <select class="form-select" id="smeStatus" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

        </form>
      </div>
      <div class="modal-footer-branded">
        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
          <i class="bi bi-x me-1"></i> Cancel
        </button>
        <button type="button" class="btn-modal-save" id="btnSaveSme">
          <span class="spinner-border spinner-border-sm d-none me-1" id="saveSmeSpinner"></span>
          <i class="bi bi-save me-1" id="saveSmeIcon"></i>
          <span id="saveSmeLabel">Save</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ TRAININGS MODAL ═══════════════════════════════════════════════ -->
<div class="modal fade" id="smeTrainingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#0891b2,#0369a1);color:#fff;border-radius:8px 8px 0 0;">
        <div class="d-flex align-items-center gap-2">
          <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
            <i class="bi bi-calendar2-week-fill"></i>
          </div>
          <div>
            <div style="font-size:.9rem;font-weight:700;" id="smeTrainingsTitle">Training Assignments</div>
            <div style="font-size:.72rem;opacity:.85;" id="smeTrainingsSubtitle"></div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3" id="smeTrainingsBody">
        <div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
      </div>
    </div>
  </div>
</div>

<!-- ── JS ──────────────────────────────────────────────────────────── -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

<script>
(function () {
  'use strict';

  const CSRF = <?php echo json_encode($_SESSION['_csrf_token'] ?? ''); ?>;

  let table;
  let allSmes    = [];
  let _currentSmeType = 'external'; // tracks selected type in modal
  let _searchTimeout  = null;

  // ── Helpers ───────────────────────────────────────────────────────
  function esc(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function setLoading(on) {
    document.getElementById('saveSmeSpinner').classList.toggle('d-none', !on);
    document.getElementById('saveSmeIcon').classList.toggle('d-none', on);
    document.getElementById('btnSaveSme').disabled = on;
  }

  // ── SME Type toggle ───────────────────────────────────────────────
  window.setSmeType = function (type) {
    _currentSmeType = type;

    document.getElementById('cardInternal').className =
      'sme-type-card' + (type === 'internal' ? ' selected-internal' : '');
    document.getElementById('cardExternal').className =
      'sme-type-card' + (type === 'external' ? ' selected-external' : '');

    const isInternal = type === 'internal';
    document.getElementById('sectionInternal').style.display     = isInternal ? '' : 'none';
    document.getElementById('sectionExternalPhoto').style.display = isInternal ? 'none' : '';
    document.getElementById('agencyNote').style.display           = isInternal ? '' : 'none';

    const agencyEl = document.getElementById('smeAgency');
    if (isInternal) {
      agencyEl.value    = 'Civil Service Commission – Regional Office VIII';
      agencyEl.readOnly = true;
    } else {
      agencyEl.readOnly = false;
      // Only clear if it was the locked CSC value
      if (agencyEl.value === 'Civil Service Commission – Regional Office VIII') {
        agencyEl.value = '';
      }
    }

    // Clear user selection when switching type
    if (type === 'external') {
      clearUserSelection();
    }
  };

  // ── Employee search ───────────────────────────────────────────────
  const userSearchInput    = document.getElementById('userSearchInput');
  const userSearchDropdown = document.getElementById('userSearchDropdown');
  const selectedUserChip   = document.getElementById('selectedUserChip');

  userSearchInput.addEventListener('input', function () {
    clearTimeout(_searchTimeout);
    const q = this.value.trim();
    if (q.length < 2) {
      closeDropdown();
      return;
    }
    _searchTimeout = setTimeout(() => searchUsers(q), 300);
  });

  function searchUsers(q) {
    fetch('api/fetch_csc_users.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(res => {
        if (res.status !== 'success') { closeDropdown(); return; }
        renderDropdown(res.data || []);
      })
      .catch(() => closeDropdown());
  }

  function renderDropdown(users) {
    if (!users.length) {
      userSearchDropdown.innerHTML =
        '<div class="user-search-empty">No active employees found.</div>';
      userSearchDropdown.classList.add('open');
      return;
    }
    // Use data-* attributes — avoids JSON.stringify double-quotes inside onclick=""
    userSearchDropdown.innerHTML = users.map(u => {
      const initials = (u.full_name || '').split(' ').map(w => w[0]).join('').substr(0, 2).toUpperCase();
      const avatarHtml = u.profile
        ? `<img src="/admin/uploads/${esc(u.profile)}" alt="" loading="lazy">`
        : esc(initials);
      return `
      <div class="user-search-result d-flex align-items-center gap-2"
           data-uid="${esc(u.id)}"
           data-name="${esc(u.full_name)}"
           data-position="${esc(u.position || '')}"
           data-forsu="${esc(u.fo_rsu || '')}"
           data-email="${esc(u.email || '')}"
           data-profile="${esc(u.profile || '')}">
        <div class="user-result-avatar">${avatarHtml}</div>
        <div>
          <div class="user-search-result-name">${esc(u.full_name)}</div>
          <div class="user-search-result-sub">
            ${esc(u.position || '')}${u.fo_rsu ? ' &middot; ' + esc(u.fo_rsu) : ''}
          </div>
        </div>
      </div>`;
    }).join('');
    userSearchDropdown.classList.add('open');
  }

  // Delegated click on the dropdown — reads from data attributes (no inline JS)
  userSearchDropdown.addEventListener('click', function (e) {
    const item = e.target.closest('.user-search-result');
    if (!item) return;
    selectUser(
      item.dataset.uid,
      item.dataset.name,
      item.dataset.position,
      item.dataset.forsu,
      item.dataset.email,
      item.dataset.profile || ''
    );
  });

  function selectUser(id, name, position, foRsu, email, profile) {
    document.getElementById('smeUserId').value       = id;
    document.getElementById('smeName').value         = name;
    document.getElementById('smeDesignation').value  = position;
    document.getElementById('smeEmail').value        = email;

    const initials    = name.split(' ').map(w => w[0]).join('').substr(0, 2).toUpperCase();
    const chipAvatar  = profile
      ? `<img src="/admin/uploads/${esc(profile)}" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">`
      : `<div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#0891b2,#0369a1);color:#fff;font-size:.75rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${esc(initials)}</div>`;

    selectedUserChip.style.display = '';
    selectedUserChip.innerHTML = `
      <div class="selected-user-chip" style="display:flex;align-items:center;gap:.6rem;">
        ${chipAvatar}
        <div style="min-width:0;">
          <div class="chip-name">${esc(name)}</div>
          <div class="chip-sub">${esc(position)}${foRsu ? ' &middot; ' + esc(foRsu) : ''}</div>
        </div>
        <button type="button" class="chip-clear" id="btnClearUser" style="margin-left:auto;">Clear selection</button>
      </div>`;
    document.getElementById('btnClearUser').addEventListener('click', clearUserSelection);

    userSearchInput.value = '';
    closeDropdown();
  }

  window.clearUserSelection = function () {
    document.getElementById('smeUserId').value = '';
    userSearchInput.value = '';
    selectedUserChip.style.display = 'none';
    selectedUserChip.innerHTML = '';
    closeDropdown();
  };

  function closeDropdown() {
    userSearchDropdown.classList.remove('open');
    userSearchDropdown.innerHTML = '';
  }

  // Close dropdown on outside click
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.user-search-wrap')) {
      closeDropdown();
    }
  });

  // ── Load & render ─────────────────────────────────────────────────
  function loadSmes() {
    const status = document.getElementById('filterStatus').value;
    const type   = document.getElementById('filterType').value;
    fetch('api/fetch_smes_master.php?status=' + encodeURIComponent(status)
        + '&sme_type=' + encodeURIComponent(type))
      .then(r => r.json())
      .then(res => {
        if (res.status !== 'success') throw new Error(res.message);
        allSmes = res.data || [];
        renderTable(allSmes);
        updateStats(allSmes);
      })
      .catch(e => Swal.fire('Error', e.message, 'error'));
  }

  // ── Training assignments modal ────────────────────────────────────
  document.getElementById('smeTable').addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-training-count');
    if (!btn) return;
    const smeId   = btn.dataset.smeId;
    const smeName = btn.dataset.smeName;
    loadSmeTrainings(smeId, smeName);
  });

  function loadSmeTrainings(smeId, smeName) {
    const body    = document.getElementById('smeTrainingsBody');
    const title   = document.getElementById('smeTrainingsTitle');
    const subtitle = document.getElementById('smeTrainingsSubtitle');

    title.textContent    = 'Training Assignments';
    subtitle.textContent = smeName;
    body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
    new bootstrap.Modal(document.getElementById('smeTrainingsModal')).show();

    fetch('api/fetch_sme_trainings.php?sme_id=' + encodeURIComponent(smeId))
      .then(r => r.json())
      .then(res => {
        if (res.status !== 'success') throw new Error(res.message);
        renderTrainingsBody(res.data || []);
      })
      .catch(e => {
        body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
      });
  }

  function renderTrainingsBody(trainings) {
    const body = document.getElementById('smeTrainingsBody');
    if (!trainings.length) {
      body.innerHTML = '<p class="text-center text-muted py-3">No training assignments found.</p>';
      return;
    }

    const statusMap = {
      'Upcoming':  { cls: 'ts-upcoming',  icon: 'bi-clock-fill' },
      'Ongoing':   { cls: 'ts-ongoing',   icon: 'bi-play-circle-fill' },
      'Completed': { cls: 'ts-completed', icon: 'bi-check-circle-fill' },
      'Cancelled': { cls: 'ts-cancelled', icon: 'bi-x-circle-fill' },
    };

    const rows = trainings.map((t, i) => {
      const s    = statusMap[t.training_status] || { cls: 'ts-upcoming', icon: 'bi-dash-circle' };
      const mode = t.mode ? t.mode.charAt(0).toUpperCase() + t.mode.slice(1) : '—';
      const dateRange = t.start_date === t.end_date
        ? fmtDate(t.start_date)
        : fmtDate(t.start_date) + ' – ' + fmtDate(t.end_date);
      return `<tr>
        <td class="text-center align-middle" style="font-size:.78rem;">${i + 1}</td>
        <td class="align-middle" style="font-size:.8rem;">
          <div class="fw-semibold">${esc(t.training_name)}</div>
          ${t.location ? `<div style="font-size:.71rem;color:#64748b;"><i class="bi bi-geo-alt-fill"></i> ${esc(t.location)}</div>` : ''}
        </td>
        <td class="text-center align-middle" style="font-size:.78rem;">
          <i class="bi bi-calendar2-event text-muted me-1"></i>${esc(dateRange)}
        </td>
        <td class="text-center align-middle" style="font-size:.78rem;">${esc(mode)}</td>
        <td class="text-center align-middle">
          <span class="ts-badge ${s.cls}">
            <i class="bi ${s.icon} me-1"></i>${esc(t.training_status)}
          </span>
        </td>
      </tr>`;
    }).join('');

    body.innerHTML = `
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm otrs-table">
          <thead>
            <tr>
              <th class="text-center">#</th>
              <th>Training</th>
              <th class="text-center">Date</th>
              <th class="text-center">Mode</th>
              <th class="text-center">Status</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }

  function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function updateStats(data) {
    document.getElementById('statTotal').textContent    = data.length;
    document.getElementById('statInternal').textContent = data.filter(s => s.sme_type === 'internal').length;
    document.getElementById('statExternal').textContent = data.filter(s => s.sme_type === 'external').length;
    document.getElementById('statActive').textContent   = data.filter(s => s.status === 'active').length;
    document.getElementById('statInactive').textContent = data.filter(s => s.status === 'inactive').length;
  }

  function typeBadge(sme) {
    if (sme.sme_type === 'internal') {
      return '<span class="badge-sme-internal"><i class="bi bi-building-fill"></i> Internal</span>';
    }
    return '<span class="badge-sme-external"><i class="bi bi-globe"></i> External</span>';
  }

  function agencyDisplay(sme) {
    if (sme.sme_type === 'internal' && sme.fo_rsu) return esc(sme.fo_rsu);
    return esc(sme.agency || '—');
  }

  function renderTable(data) {
    if (table) { table.destroy(); }

    const tbody = document.querySelector('#smeTable tbody');
    tbody.innerHTML = data.map((s, i) => {
      const initials    = s.name.split(' ').map(w => w[0]).join('').substr(0, 2).toUpperCase();
      const avatarCls   = s.sme_type === 'internal' ? 'avatar-internal' : 'avatar-external';
      const statusCls   = s.status === 'active' ? 'badge-active' : 'badge-inactive';
      const statusLbl   = s.status.charAt(0).toUpperCase() + s.status.slice(1);

      // Resolve photo: internal → user_profile from admin/uploads; external → photo from sme_photos
      let avatarContent;
      if (s.sme_type === 'internal' && s.user_profile) {
        avatarContent = `<img src="/admin/uploads/${esc(s.user_profile)}" alt="${esc(s.name)}" loading="lazy">`;
      } else if (s.sme_type === 'external' && s.photo) {
        avatarContent = `<img src="/assets/uploads/sme_photos/${esc(s.photo)}" alt="${esc(s.name)}" loading="lazy">`;
      } else {
        avatarContent = esc(initials);
      }

      return `<tr>
        <td class="text-center align-middle">${i + 1}</td>
        <td class="align-middle">
          <div class="d-flex align-items-center gap-2">
            <div class="sme-avatar ${avatarCls}">${avatarContent}</div>
            <div>
              <div class="fw-semibold" style="font-size:.83rem;">${esc(s.name)}</div>
              ${s.expertise ? `<div style="font-size:.71rem;color:#64748b;">${esc(s.expertise.substr(0,60))}${s.expertise.length > 60 ? '…' : ''}</div>` : ''}
            </div>
          </div>
        </td>
        <td class="align-middle" style="font-size:.8rem;">${esc(s.designation || '—')}</td>
        <td class="align-middle" style="font-size:.8rem;">${agencyDisplay(s)}</td>
        <td class="align-middle" style="font-size:.78rem;">${s.email ? `<a href="mailto:${esc(s.email)}">${esc(s.email)}</a>` : '—'}</td>
        <td class="text-center align-middle" style="font-size:.78rem;">${esc(s.mobile_no || '—')}</td>
        <td class="text-center align-middle">${typeBadge(s)}</td>
        <td class="text-center align-middle">
          ${+s.training_count > 0
            ? `<button class="ts-badge ts-upcoming btn-training-count"
                       style="border:none;cursor:pointer;"
                       data-sme-id="${s.id}"
                       data-sme-name="${esc(s.name)}"
                       title="View training assignments">${esc(s.training_count)}</button>`
            : `<span class="ts-badge" style="background:#f1f5f9;color:#94a3b8;">0</span>`}
        </td>
        <td class="text-center align-middle">
          <span class="${statusCls}">${esc(statusLbl)}</span>
        </td>
        <td class="text-center align-middle">
          <div class="d-flex justify-content-center gap-1">
            <button class="btn-tbl btn-tbl-primary" onclick="editSme(${s.id})" title="Edit">
              <i class="bi bi-pencil-fill"></i>
            </button>
            <button class="btn-tbl btn-tbl-danger" onclick="deleteSme(${s.id}, '${esc(s.name.replace(/'/g, "\\'"))}')" title="Delete">
              <i class="bi bi-trash-fill"></i>
            </button>
          </div>
        </td>
      </tr>`;
    }).join('');

    table = $('#smeTable').DataTable({
      pageLength: 25,
      order: [[1, 'asc']],
      columnDefs: [{ targets: [0, 6, 7, 8, 9], orderable: false }],
    });
  }

  // ── Open modal ────────────────────────────────────────────────────
  document.getElementById('btnAddSme').addEventListener('click', () => openModal(null));

  window.editSme = function (id) {
    const sme = allSmes.find(s => +s.id === +id);
    if (!sme) return;
    openModal(sme);
  };

  function openModal(sme) {
    const isEdit = !!sme;

    // Reset form first
    document.getElementById('smeId').value         = sme ? sme.id : '';
    document.getElementById('smeUserId').value     = sme ? (sme.user_id || '') : '';
    document.getElementById('smeName').value       = sme ? sme.name : '';
    document.getElementById('smeDesignation').value = sme ? sme.designation : '';
    document.getElementById('smeEmail').value      = sme ? sme.email : '';
    document.getElementById('smeMobile').value     = sme ? sme.mobile_no : '';
    document.getElementById('smeAgency').value     = sme ? sme.agency : '';
    document.getElementById('smeExpertise').value  = sme ? sme.expertise : '';
    document.getElementById('smeBio').value        = sme ? sme.bio : '';
    document.getElementById('smeStatus').value     = sme ? sme.status : 'active';
    document.getElementById('smeName').classList.remove('is-invalid');

    // Reset search UI
    clearUserSelection();

    // Set type (default external for new)
    const type = sme ? (sme.sme_type || 'external') : 'external';
    setSmeType(type);

    // If editing an internal SME that has a user_id, show the chip with profile photo
    if (isEdit && sme.sme_type === 'internal' && sme.user_id) {
      const chipName    = sme.name;
      const chipSub     = sme.designation + (sme.fo_rsu ? ' · ' + sme.fo_rsu : '');
      const initials    = chipName.split(' ').map(w => w[0]).join('').substr(0, 2).toUpperCase();
      const chipAvatar  = sme.user_profile
        ? `<img src="/admin/uploads/${esc(sme.user_profile)}" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">`
        : `<div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#0891b2,#0369a1);color:#fff;font-size:.75rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${esc(initials)}</div>`;
      document.getElementById('smeUserId').value = sme.user_id;
      selectedUserChip.style.display = '';
      selectedUserChip.innerHTML = `
        <div class="selected-user-chip" style="display:flex;align-items:center;gap:.6rem;">
          ${chipAvatar}
          <div style="min-width:0;">
            <div class="chip-name">${esc(chipName)}</div>
            <div class="chip-sub">${esc(chipSub)}</div>
          </div>
          <button type="button" class="chip-clear" id="btnClearUser" style="margin-left:auto;">Clear selection</button>
        </div>`;
      document.getElementById('btnClearUser').addEventListener('click', clearUserSelection);
    }

    // Reset photo UI
    resetPhotoUI();
    // If editing external SME with existing photo, show it
    if (isEdit && sme.sme_type === 'external' && sme.photo) {
      setPhotoPreview('/assets/uploads/sme_photos/' + sme.photo);
    }

    document.getElementById('smeModalTitle').innerHTML =
      `<i class="bi bi-person-video3 me-2"></i>${isEdit ? 'Edit SME' : 'Add Subject Matter Expert (SME)'}`;
    document.getElementById('smeModalSubtitle').textContent = isEdit
      ? 'Editing: ' + sme.name
      : 'Global SME registry entry';
    document.getElementById('saveSmeLabel').textContent = isEdit ? 'Save Changes' : 'Add SME';

    new bootstrap.Modal(document.getElementById('smeModal')).show();
  }

  // ── External photo upload wiring ──────────────────────────────────
  const smePhotoWrap    = document.getElementById('smePhotoWrap');
  const smePhotoInput   = document.getElementById('smePhotoInput');
  const smePhotoPreview = document.getElementById('smePhotoPreview');
  const smePhotoPlaceholder = document.getElementById('smePhotoPlaceholder');
  const btnClearSmePhoto    = document.getElementById('btnClearSmePhoto');

  smePhotoWrap.addEventListener('click', () => smePhotoInput.click());

  smePhotoInput.addEventListener('change', function () {
    if (!this.files || !this.files[0]) return;
    const url = URL.createObjectURL(this.files[0]);
    setPhotoPreview(url);
    document.getElementById('smeClearPhoto').value = '0';
  });

  btnClearSmePhoto.addEventListener('click', function (e) {
    e.stopPropagation();
    resetPhotoUI();
    document.getElementById('smeClearPhoto').value = '1';
  });

  function setPhotoPreview(src) {
    smePhotoPreview.src = src;
    smePhotoPreview.style.display = '';
    smePhotoPlaceholder.style.display = 'none';
    btnClearSmePhoto.style.display = '';
  }

  function resetPhotoUI() {
    smePhotoPreview.src = '';
    smePhotoPreview.style.display = 'none';
    smePhotoPlaceholder.style.display = '';
    btnClearSmePhoto.style.display = 'none';
    smePhotoInput.value = '';
    document.getElementById('smeClearPhoto').value = '0';
  }

  // ── Save ──────────────────────────────────────────────────────────
  document.getElementById('btnSaveSme').addEventListener('click', saveSme);

  function saveSme() {
    const name = document.getElementById('smeName').value.trim();
    if (!name) {
      document.getElementById('smeName').classList.add('is-invalid');
      document.getElementById('smeName').focus();
      return;
    }
    document.getElementById('smeName').classList.remove('is-invalid');

    const id  = document.getElementById('smeId').value;
    const url = id ? 'api/update_sme.php' : 'api/add_sme.php';

    const body = new FormData(document.getElementById('smeForm'));
    // Append sme_type explicitly since it's not a named form input
    body.append('sme_type', _currentSmeType);
    body.append('_csrf', CSRF);

    setLoading(true);
    fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }, body })
      .then(r => r.json())
      .then(res => {
        setLoading(false);
        if (res.status !== 'success') throw new Error(res.message);
        bootstrap.Modal.getInstance(document.getElementById('smeModal')).hide();
        Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1800, showConfirmButton: false });
        loadSmes();
      })
      .catch(e => { setLoading(false); Swal.fire('Error', e.message, 'error'); });
  }

  // ── Delete ────────────────────────────────────────────────────────
  window.deleteSme = function (id, name) {
    Swal.fire({
      title: 'Delete SME?',
      html: `Remove <strong>${esc(name)}</strong> from the registry?`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      confirmButtonText: 'Delete',
    }).then(r => {
      if (!r.isConfirmed) return;
      const body = new FormData();
      body.append('id', id);
      body.append('_csrf', CSRF);
      fetch('api/delete_sme.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }, body })
        .then(r => r.json())
        .then(res => {
          if (res.status !== 'success') throw new Error(res.message);
          Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1600, showConfirmButton: false });
          loadSmes();
        })
        .catch(e => Swal.fire('Error', e.message, 'error'));
    });
  };

  // ── Filters ───────────────────────────────────────────────────────
  document.getElementById('filterStatus').addEventListener('change', loadSmes);
  document.getElementById('filterType').addEventListener('change', loadSmes);

  // ── Init ──────────────────────────────────────────────────────────
  loadSmes();
})();
</script>

</body>
</html>
