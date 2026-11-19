/**
 * participants-sme-attendance.js
 * Handles the SMEs tab and Attendance tab in participants.php.
 * Depends on: jQuery, Bootstrap 5, SweetAlert2, TomSelect, window.CONFIG, window.csrfToken
 */
(function () {
  'use strict';

  const CSRF        = window.csrfToken || '';
  const TRAINING_ID = CONFIG.trainingId;

  // ── SME state ─────────────────────────────────────────────────────────
  let trainingSmes     = [];
  let masterSmes       = [];
  let smesDayModal     = null;
  let currentTsmId     = null;
  let currentTotalDays = 0;
  let trainingDates    = [];

  // ── Attendance state ──────────────────────────────────────────────────
  let attendanceLoaded = false;

  // ══════════════════════════════════════════════════════════════════════
  // SME TAB
  // ══════════════════════════════════════════════════════════════════════

  function loadTrainingSmes() {
    document.getElementById('smeLoadingMsg').style.display = '';
    document.getElementById('smeListCards').innerHTML = '';

    fetch('api/fetch_training_smes.php?training_id=' + TRAINING_ID)
      .then(r => r.json())
      .then(res => {
        document.getElementById('smeLoadingMsg').style.display = 'none';
        if (res.status !== 'success') throw new Error(res.message);
        trainingSmes = res.data || [];
        document.getElementById('smeBadge').textContent = trainingSmes.length;
        renderSmesCards(trainingSmes);
      })
      .catch(e => {
        document.getElementById('smeLoadingMsg').style.display = 'none';
        document.getElementById('smeListCards').innerHTML =
          `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>${esc(e.message)}</div>`;
      });
  }

  function renderSmesCards(smes) {
    const container = document.getElementById('smeListCards');
    if (!smes.length) {
      container.innerHTML = `
        <div class="text-center py-4" style="color:#94a3b8;">
          <i class="bi bi-person-video3" style="font-size:2rem;"></i>
          <p class="mt-2 mb-0" style="font-size:.85rem;">No SMEs assigned yet.</p>
          <p style="font-size:.78rem;">Click <strong>Assign SME</strong> to add one.</p>
        </div>`;
      return;
    }

    container.innerHTML = smes.map(s => {
      const initials = s.sme_name.split(' ').map(w => w[0]).join('').substr(0,2).toUpperCase();
      let avatarHtml;
      if (s.sme_type === 'internal' && s.user_profile) {
        avatarHtml = `<img src="/admin/uploads/${esc(s.user_profile)}" alt="${esc(s.sme_name)}"
                           style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">`;
      } else if (s.sme_type === 'external' && s.photo) {
        avatarHtml = `<img src="/assets/uploads/sme_photos/${esc(s.photo)}" alt="${esc(s.sme_name)}"
                           style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">`;
      } else {
        avatarHtml = esc(initials);
      }
      const daysHtml = s.days.length
        ? s.days.map(d => `<span class="day-badge">Day ${d.day_number}${d.training_date ? ' — ' + fmtDate(d.training_date) : ''}</span>`).join('')
        : `<span class="day-badge" style="background:#f1f5f9;color:#64748b;">All Days</span>`;
      // "View Trainings" button — only for registry-linked SMEs (sme_id not null)
      const trainingsBtn = s.sme_id
        ? `<button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-sme-trainings" style="font-size:.72rem;"
                   data-sme-id="${esc(s.sme_id)}" data-sme-name="${esc(s.sme_name)}" title="View all training assignments">
             <i class="bi bi-calendar2-week"></i> Trainings
           </button>`
        : '';
      return `
        <div class="sme-card">
          <div class="sme-card-avatar" style="overflow:hidden;">${avatarHtml}</div>
          <div class="flex-grow-1">
            <div class="sme-card-name">${esc(s.sme_name)}</div>
            ${s.designation ? `<div class="sme-card-desig">${esc(s.designation)}</div>` : ''}
            <div class="mt-1">${daysHtml}</div>
          </div>
          <div class="d-flex gap-1 ms-2 flex-shrink-0 flex-wrap justify-content-end">
            ${trainingsBtn}
            <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.72rem;"
                    onclick="openDaysModal(${s.id})">
              <i class="bi bi-calendar-week"></i> Days
            </button>
            <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.72rem;"
                    onclick="removeTsmSme(${s.id}, '${s.sme_name.replace(/'/g,"\\'")}')">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </div>`;
    }).join('');
  }

  // Delegated click for "View Trainings" button
  document.getElementById('smeListContainer').addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-sme-trainings');
    if (!btn) return;
    openSmeTrainingsModal(btn.dataset.smeId, btn.dataset.smeName);
  });

  // ── SME Training Assignments modal ────────────────────────────────────
  function ensureSmeTrainingsModal() {
    if (document.getElementById('smeTrainingsModal')) return;
    document.body.insertAdjacentHTML('beforeend', `
      <div class="modal fade" id="smeTrainingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0891b2,#0369a1);color:#fff;border-radius:8px 8px 0 0;">
              <div class="d-flex align-items-center gap-2">
                <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.2);
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
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
              <div class="text-center py-4 text-muted">
                <span class="spinner-border spinner-border-sm me-2"></span>Loading…
              </div>
            </div>
          </div>
        </div>
      </div>`);
  }

  function openSmeTrainingsModal(smeId, smeName) {
    ensureSmeTrainingsModal();
    document.getElementById('smeTrainingsTitle').textContent    = 'Training Assignments';
    document.getElementById('smeTrainingsSubtitle').textContent = smeName;
    document.getElementById('smeTrainingsBody').innerHTML =
      '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';

    new bootstrap.Modal(document.getElementById('smeTrainingsModal')).show();

    fetch('api/fetch_sme_trainings.php?sme_id=' + encodeURIComponent(smeId))
      .then(r => r.json())
      .then(res => {
        if (res.status !== 'success') throw new Error(res.message);
        renderSmeTrainingsBody(res.data || []);
      })
      .catch(e => {
        document.getElementById('smeTrainingsBody').innerHTML =
          `<div class="alert alert-danger">${esc(e.message)}</div>`;
      });
  }

  function renderSmeTrainingsBody(trainings) {
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
      const s   = statusMap[t.training_status] || { cls: 'ts-upcoming', icon: 'bi-dash-circle' };
      const mode = t.mode ? t.mode.charAt(0).toUpperCase() + t.mode.slice(1) : '—';
      const dateRange = t.start_date === t.end_date
        ? fmtDate(t.start_date)
        : fmtDate(t.start_date) + ' – ' + fmtDate(t.end_date);
      const isCurrent = String(t.training_id) === String(TRAINING_ID);
      return `<tr${isCurrent ? ' style="background:#ecfeff;"' : ''}>
        <td class="text-center align-middle" style="font-size:.78rem;">${i + 1}</td>
        <td class="align-middle" style="font-size:.8rem;">
          <div class="fw-semibold">${esc(t.training_name)}${isCurrent ? ' <span class="badge bg-info text-white ms-1" style="font-size:.62rem;">Current</span>' : ''}</div>
          ${t.location ? `<div style="font-size:.71rem;color:#64748b;"><i class="bi bi-geo-alt-fill"></i> ${esc(t.location)}</div>` : ''}
        </td>
        <td class="text-center align-middle" style="font-size:.78rem;white-space:nowrap;">
          <i class="bi bi-calendar2-event text-muted me-1"></i>${esc(dateRange)}
        </td>
        <td class="text-center align-middle" style="font-size:.78rem;">${esc(mode)}</td>
        <td class="text-center align-middle">
          <span class="ts-badge ${s.cls}" style="font-size:.65rem;">
            <i class="bi ${s.icon} me-1"></i>${esc(t.training_status)}
          </span>
        </td>
      </tr>`;
    }).join('');

    body.innerHTML = `
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm">
          <thead class="table-light">
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

  // ── Assign SME ────────────────────────────────────────────────────────
  document.getElementById('btnAddTrainingSme').addEventListener('click', openAssignModal);

  function openAssignModal() {
    if (!document.getElementById('assignSmeModal')) {
      document.body.insertAdjacentHTML('beforeend', `
        <div class="modal fade" id="assignSmeModal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header text-white" style="background:linear-gradient(135deg,#0891b2,#0369a1);">
                <h6 class="modal-title"><i class="bi bi-person-video3 me-2"></i>Assign Subject Matter Expert (SME)</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body p-4">
                <div class="mb-3">
                  <label class="form-label fw-semibold">Select from Registry (optional)</label>
                  <select id="assignSmeSelect" placeholder="Search by name…"></select>
                  <div style="font-size:.72rem;color:#64748b;margin-top:.3rem;">
                    Leave blank to enter a custom name below.
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="assignSmeName" maxlength="128" placeholder="Full name">
                </div>
                <div class="mb-3">
                  <label class="form-label fw-semibold">Designation</label>
                  <input type="text" class="form-control" id="assignSmeDesig" maxlength="128" placeholder="e.g. Division Chief">
                </div>
              </div>
              <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnConfirmAssignSme">
                  <span class="spinner-border spinner-border-sm d-none" id="assignSmeSpinner"></span>
                  <i class="bi bi-person-plus me-1"></i> Assign
                </button>
              </div>
            </div>
          </div>
        </div>`);

      document.getElementById('btnConfirmAssignSme').addEventListener('click', confirmAssignSme);
    }

    const sel = document.getElementById('assignSmeSelect');
    if (!sel._tomSelectInst) {
      fetch('api/fetch_smes_master.php?status=active')
        .then(r => r.json())
        .then(res => {
          masterSmes = res.data || [];
          if (sel._tsInstance) { sel._tsInstance.destroy(); }
          sel._tsInstance = new TomSelect(sel, {
            valueField: 'id',
            labelField: 'name',
            searchField: ['name', 'designation', 'agency'],
            options: masterSmes,
            placeholder: 'Search registry…',
            allowEmptyOption: true,
            render: {
              option: (item) => `<div>
                <div style="font-weight:700;font-size:.83rem;">${esc(item.name)}</div>
                <div style="font-size:.72rem;color:#64748b;">${esc(item.designation || '')}${item.agency ? ' · ' + esc(item.agency) : ''}</div>
              </div>`,
            },
            onChange: (val) => {
              const sme = masterSmes.find(s => +s.id === +val);
              if (sme) {
                document.getElementById('assignSmeName').value  = sme.name;
                document.getElementById('assignSmeDesig').value = sme.designation;
              }
            },
          });
          sel._tomSelectInst = sel._tsInstance;
        });
    }

    document.getElementById('assignSmeName').value  = '';
    document.getElementById('assignSmeDesig').value = '';
    if (sel._tsInstance) sel._tsInstance.clear();

    new bootstrap.Modal(document.getElementById('assignSmeModal')).show();
  }

  function confirmAssignSme() {
    const name  = document.getElementById('assignSmeName').value.trim();
    const desig = document.getElementById('assignSmeDesig').value.trim();
    const sel   = document.getElementById('assignSmeSelect');
    const smeId = sel._tsInstance ? (sel._tsInstance.getValue() || null) : null;

    if (!name) {
      Swal.fire({ icon: 'warning', title: 'Name Required', text: 'Please enter the SME name.', confirmButtonColor: '#0077b6' });
      return;
    }

    document.getElementById('assignSmeSpinner').classList.remove('d-none');
    document.getElementById('btnConfirmAssignSme').disabled = true;

    fetch('api/add_training_sme.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        training_id: TRAINING_ID,
        sme_id: smeId ? +smeId : null,
        sme_name: name,
        designation: desig,
        _csrf: CSRF,
      }),
    })
    .then(r => r.json())
    .then(res => {
      document.getElementById('assignSmeSpinner').classList.add('d-none');
      document.getElementById('btnConfirmAssignSme').disabled = false;
      if (res.status !== 'success') throw new Error(res.message);
      bootstrap.Modal.getInstance(document.getElementById('assignSmeModal')).hide();
      Swal.fire({ icon: 'success', title: 'Assigned', text: res.message, timer: 1600, showConfirmButton: false });
      loadTrainingSmes();
    })
    .catch(e => {
      document.getElementById('assignSmeSpinner').classList.add('d-none');
      document.getElementById('btnConfirmAssignSme').disabled = false;
      Swal.fire('Error', e.message, 'error');
    });
  }

  // ── Remove SME from training ──────────────────────────────────────────
  window.removeTsmSme = function (id, name) {
    Swal.fire({
      title: 'Remove SME?',
      html: `Remove <strong>${esc(name)}</strong> from this training?`,
      icon: 'warning', showCancelButton: true,
      confirmButtonColor: '#dc2626', confirmButtonText: 'Remove',
    }).then(async r => {
      if (!r.isConfirmed) return;
      try {
        let res = await fetch('api/delete_training_sme.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ id, _csrf: CSRF }),
        }).then(x => x.json());

        if (res.has_ratings) {
          const conf = await Swal.fire({
            title: 'Evaluation Ratings Exist',
            html: `<strong>${res.rating_count}</strong> evaluation rating(s) for this SME will be permanently deleted. Proceed?`,
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#dc2626', confirmButtonText: 'Yes, delete all',
          });
          if (!conf.isConfirmed) return;
          res = await fetch('api/delete_training_sme.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ id, _csrf: CSRF, force: true }),
          }).then(x => x.json());
        }

        if (res.status !== 'success') throw new Error(res.message);
        Swal.fire({ icon: 'success', title: 'Removed', text: res.message, timer: 1400, showConfirmButton: false });
        loadTrainingSmes();
      } catch (e) { Swal.fire('Error', e.message, 'error'); }
    });
  };

  // ── Day Assignment Modal ──────────────────────────────────────────────
  window.openDaysModal = function (tsmId) {
    currentTsmId = tsmId;
    const sme    = trainingSmes.find(s => +s.id === +tsmId);
    if (!sme) return;

    if (!document.getElementById('smeDaysModal')) {
      document.body.insertAdjacentHTML('beforeend', `
        <div class="modal fade" id="smeDaysModal" tabindex="-1">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header text-white" style="background:linear-gradient(135deg,#0891b2,#0369a1);">
                <h6 class="modal-title" id="smeDaysTitle"><i class="bi bi-calendar-week me-2"></i>Day Assignments</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body p-4">
                <p style="font-size:.82rem;color:#64748b;" class="mb-3">
                  Select the training days this SME will be present.
                  Leaving all unchecked means the SME appears for all days.
                </p>
                <div id="smeDaysGrid" class="row g-2"></div>
              </div>
              <div class="modal-footer">
                <div style="font-size:.76rem;color:#64748b;flex:1;">
                  <i class="bi bi-info-circle me-1"></i>Unchecked = all days (no restriction).
                </div>
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnSaveDays">
                  <span class="spinner-border spinner-border-sm d-none" id="saveDaysSpinner"></span>
                  <i class="bi bi-save me-1"></i> Save Days
                </button>
              </div>
            </div>
          </div>
        </div>`);
      document.getElementById('btnSaveDays').addEventListener('click', saveDays);
      smesDayModal = new bootstrap.Modal(document.getElementById('smeDaysModal'));
    }

    document.getElementById('smeDaysTitle').innerHTML =
      `<i class="bi bi-calendar-week me-2"></i>Day Assignments — ${esc(sme.sme_name)}`;

    const grid    = document.getElementById('smeDaysGrid');
    const assigned = new Set(sme.days.map(d => +d.day_number));

    if (!trainingDates.length) {
      fetch('api/fetch_attendance.php?training_id=' + TRAINING_ID)
        .then(r => r.json())
        .then(res => {
          if (res.status === 'success') {
            trainingDates    = res.training_dates || [];
            currentTotalDays = res.total_days     || 0;
          }
          renderDayCheckboxes(grid, assigned, trainingDates);
        });
    } else {
      renderDayCheckboxes(grid, assigned, trainingDates);
    }

    if (!smesDayModal) smesDayModal = new bootstrap.Modal(document.getElementById('smeDaysModal'));
    smesDayModal.show();
  };

  function renderDayCheckboxes(grid, assigned, dates) {
    const total = dates.length || currentTotalDays || 1;
    grid.innerHTML = Array.from({ length: total }, (_, i) => {
      const dayNo = i + 1;
      const date  = dates[i] || '';
      const chk   = assigned.has(dayNo) ? 'checked' : '';
      return `
        <div class="col-sm-3 col-md-2">
          <label class="d-flex align-items-center gap-2 p-2 rounded border cursor-pointer"
                 style="font-size:.8rem;background:#f8faff;cursor:pointer;">
            <input type="checkbox" class="day-chk" value="${dayNo}" ${chk} style="width:16px;height:16px;">
            <span>
              <strong>Day ${dayNo}</strong>
              ${date ? `<br><small style="color:#64748b;">${fmtDate(date)}</small>` : ''}
            </span>
          </label>
        </div>`;
    }).join('');
  }

  function saveDays() {
    const checks = [...document.querySelectorAll('.day-chk:checked')];
    const days   = checks.map(c => {
      const dn   = +c.value;
      const date = trainingDates[dn - 1] || null;
      return { day_number: dn, training_date: date };
    });

    document.getElementById('saveDaysSpinner').classList.remove('d-none');
    document.getElementById('btnSaveDays').disabled = true;

    fetch('api/save_sme_days.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ training_sme_id: currentTsmId, days, _csrf: CSRF }),
    })
    .then(r => r.json())
    .then(res => {
      document.getElementById('saveDaysSpinner').classList.add('d-none');
      document.getElementById('btnSaveDays').disabled = false;
      if (res.status !== 'success') throw new Error(res.message);
      smesDayModal.hide();
      Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1400, showConfirmButton: false });
      loadTrainingSmes();
    })
    .catch(e => {
      document.getElementById('saveDaysSpinner').classList.add('d-none');
      document.getElementById('btnSaveDays').disabled = false;
      Swal.fire('Error', e.message, 'error');
    });
  }

  // Load SMEs when the tab is first clicked
  document.getElementById('tab-smes-btn').addEventListener('shown.bs.tab', () => {
    if (!trainingSmes.length) loadTrainingSmes();
  });

  // Also load immediately if already on the tab
  loadTrainingSmes();

  // ══════════════════════════════════════════════════════════════════════
  // ATTENDANCE TAB  —  Matrix view with colspan Evaluation Form section
  //
  // Eval days = the last day each SME presents. The API returns eval_days[]
  // so we just render whatever the server computed.
  // ══════════════════════════════════════════════════════════════════════

  window.loadAttendanceTab = function () {
    if (attendanceLoaded) return;
    attendanceLoaded = true;
    fetchAndRenderAttendance();
  };

  window.refreshAttendance = function () {
    attendanceLoaded = false;
    fetchAndRenderAttendance();
  };

  function fetchAndRenderAttendance() {
    document.getElementById('attendanceTabBody').innerHTML = `
      <div class="text-center py-5 text-muted">
        <div class="spinner-border text-success" role="status"></div>
        <p class="mt-2">Loading attendance sheet…</p>
      </div>`;

    fetch('api/fetch_attendance.php?training_id=' + TRAINING_ID)
      .then(r => r.json())
      .then(res => {
        if (res.status !== 'success') throw new Error(res.message);
        trainingDates    = res.training_dates || [];
        currentTotalDays = res.total_days     || 0;
        renderAttendanceUI(res);
      })
      .catch(e => {
        document.getElementById('attendanceTabBody').innerHTML =
          `<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-1"></i>${esc(e.message)}</div>`;
      });
  }

  function renderAttendanceUI(data) {
    const {
      total_days:     totalDays,
      training_dates: dates,
      participants,
      eval_days:      evalDays = [],
    } = data;

    if (!totalDays) {
      document.getElementById('attendanceTabBody').innerHTML =
        '<div class="alert alert-warning m-3"><i class="bi bi-calendar-x me-1"></i>No training days found. Please verify the training dates.</div>';
      return;
    }
    if (!participants.length) {
      document.getElementById('attendanceTabBody').innerHTML =
        '<div class="alert alert-info m-3"><i class="bi bi-people me-1"></i>No participants found for this training.</div>';
      return;
    }

    // ── Bulk-mark toolbar ──────────────────────────────────────────
    const dayOpts = dates.map((d, i) => {
      const dn = i + 1;
      return `<option value="${dn}" data-date="${d || ''}">Day ${dn}${d ? ' — ' + fmtDate(d) : ''}</option>`;
    }).join('');

    const toolbar = `
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2 px-1 pt-1">
        <span class="fw-semibold" style="font-size:.75rem;color:#475569;white-space:nowrap;">Bulk Mark:</span>
        <select id="bulkMarkDaySel" class="form-select form-select-sm" style="width:auto;font-size:.75rem;">${dayOpts}</select>
        <button class="btn btn-sm btn-outline-success" style="font-size:.72rem;" onclick="bulkMarkDay('present')">
          <i class="bi bi-check-all"></i> All Present
        </button>
        <button class="btn btn-sm btn-outline-danger" style="font-size:.72rem;" onclick="bulkMarkDay('absent')">
          <i class="bi bi-x-circle"></i> All Absent
        </button>
        <div class="d-flex gap-2 ms-auto">
          <a href="api/export_attendance.php?training_id=${TRAINING_ID}"
             class="btn btn-sm btn-outline-success" style="font-size:.72rem;" download>
            <i class="bi bi-file-earmark-spreadsheet"></i> Export
          </a>
          <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;" onclick="refreshAttendance()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
          </button>
        </div>
      </div>`;

    // ── Two-level thead ────────────────────────────────────────────
    // Row 1: fixed cols (rowspan 2) | Attendance colspan | Eval Form colspan | Present (rowspan 2)
    // Row 2: Day sub-headers | Eval-day sub-headers

    const attColspan  = totalDays;
    const evalColspan = evalDays.length;

    let row1 = `
      <th rowspan="2" class="text-center align-middle att-fixed-th" style="width:30px;">#</th>
      <th rowspan="2" class="align-middle att-fixed-th" style="min-width:130px;">Name</th>
      <th rowspan="2" class="align-middle att-fixed-th" style="min-width:110px;">Agency</th>
      <th rowspan="2" class="text-center align-middle att-fixed-th" style="min-width:62px;">Pay</th>
      <th colspan="${attColspan}" class="text-center att-group-th">
        <i class="bi bi-clipboard-check-fill me-1"></i>Attendance
      </th>`;

    if (evalColspan > 0) {
      row1 += `<th colspan="${evalColspan}" class="text-center eval-group-th">
        <i class="bi bi-journal-check me-1"></i>Evaluation Form
      </th>`;
    }

    row1 += `<th rowspan="2" class="text-center align-middle att-fixed-th" style="min-width:50px;">Days<br>Present</th>`;

    let row2 = '';
    for (let i = 0; i < totalDays; i++) {
      const dn = i + 1;
      const d  = dates[i] || '';
      row2 += `<th class="text-center att-day-th" style="min-width:68px;">
        Day ${dn}${d ? '<br><small style="font-weight:400;font-size:.6rem;">' + fmtDate(d) + '</small>' : ''}
      </th>`;
    }
    for (const ed of evalDays) {
      const smeHtml = ed.sme_names.map(s => `<div class="att-sme-chip">${esc(s)}</div>`).join('');
      row2 += `<th class="text-center eval-day-th" style="min-width:80px;">
        Day ${ed.day_number}${ed.date ? '<br><small style="font-weight:400;font-size:.6rem;">' + fmtDate(ed.date) + '</small>' : ''}
        <div class="mt-1">${smeHtml}</div>
      </th>`;
    }

    // ── tbody rows ─────────────────────────────────────────────────
    const tbody = participants.map((p, pi) => {
      let cells = `
        <td class="text-center" style="font-size:.73rem;color:#94a3b8;">${pi + 1}</td>
        <td style="font-size:.78rem;font-weight:500;line-height:1.3;">${esc(p.name)}</td>
        <td style="font-size:.72rem;">
          <div>${esc(p.agency || '—')}</div>
          ${p.position ? `<div style="color:#94a3b8;font-size:.68rem;">${esc(p.position)}</div>` : ''}
        </td>
        <td class="text-center">${payBadge(p.payment_status)}</td>`;

      for (let i = 0; i < totalDays; i++) {
        const dn  = i + 1;
        const d   = dates[i] || '';
        const rec = p.attendance[dn] || { status: 'not_marked', source: '' };
        cells += buildAttCell(p.registration_id, p.participant_id, dn, d, rec.status, rec.source || '');
      }

      const todayStr     = new Date().toISOString().slice(0, 10);
      const submittedSet = new Set(p.eval_submissions || []);
      for (const ed of evalDays) {
        const submitted = submittedSet.has(ed.day_number);
        const edDate    = ed.date || null;
        const isPassed  = edDate ? edDate <= todayStr : true;
        let badgeHtml;
        if (submitted) {
          badgeHtml = '<span class="att-eval-badge done"><i class="bi bi-check-circle-fill me-1"></i>Done</span>';
        } else if (isPassed) {
          badgeHtml = '<span class="att-eval-badge pending">Not Yet</span>';
        } else {
          badgeHtml = '<span class="att-eval-badge na">N/A</span>';
        }
        cells += `<td class="text-center att-eval-cell">${badgeHtml}</td>`;
      }

      const pc = p.days_present || 0;
      cells += `<td class="text-center" id="attPresent_${p.registration_id}" style="vertical-align:middle;">
        <span style="font-size:.78rem;font-weight:700;color:${pc > 0 ? '#16a34a' : '#94a3b8'};">${pc}</span>
        <span style="font-size:.72rem;color:#cbd5e1;">/${totalDays}</span>
      </td>`;

      return `<tr>${cells}</tr>`;
    }).join('');

    // ── Assemble ───────────────────────────────────────────────────
    document.getElementById('attendanceTabBody').innerHTML = `
      ${toolbar}
      <div style="overflow-x:auto;">
        <table class="table table-bordered table-hover table-sm w-100 att-matrix-table" style="font-size:.77rem;">
          <thead class="table-light">
            <tr>${row1}</tr>
            <tr>${row2}</tr>
          </thead>
          <tbody id="attMatrixBody">${tbody}</tbody>
        </table>
      </div>`;
  }

  // ── Build a single attendance cell with hover mark buttons ────────────
  function buildAttCell(regId, paxId, dn, date, status, source) {
    const cls = status === 'present' ? 'att-st-present'
              : status === 'absent'  ? 'att-st-absent'
              : status === 'excused' ? 'att-st-excused'
              : 'att-st-unmarked';
    const lbl = status === 'not_marked' ? '—'
              : status.charAt(0).toUpperCase() + status.slice(1);
    const selfBadge = source === 'self'
      ? '<span title="Self-reported by participant" style="background:#dbeafe;color:#1e40af;font-size:.55rem;font-weight:700;padding:.07em .32em;border-radius:3px;margin-left:3px;vertical-align:middle;">Self</span>'
      : '';

    return `<td class="att-day-cell" id="attCell_${regId}_${dn}">
      <div class="att-cell-inner">
        <span class="${cls}" id="attStatus_${regId}_${dn}">${lbl}</span>${selfBadge}
        <div class="att-cell-btns">
          <button class="att-mark-btn att-mark-p" title="Present"
                  onclick="markOne(${regId},${paxId},${dn},'${date}','present','')">
            <i class="bi bi-check-lg"></i>
          </button>
          <button class="att-mark-btn att-mark-a" title="Absent"
                  onclick="markOne(${regId},${paxId},${dn},'${date}','absent','')">
            <i class="bi bi-x-lg"></i>
          </button>
          <button class="att-mark-btn att-mark-e" title="Excused"
                  onclick="markOne(${regId},${paxId},${dn},'${date}','excused','')">
            <i class="bi bi-slash-circle"></i>
          </button>
        </div>
      </div>
    </td>`;
  }

  // ── payBadge ──────────────────────────────────────────────────────────
  function payBadge(ps) {
    const s = (ps || '').toLowerCase();
    const map = {
      'paid':            'background:#d1fae5;color:#065f46;',
      'free':            'background:#ede9fe;color:#5b21b6;',
      'promissory note': 'background:#dbeafe;color:#1e40af;',
      'pending':         'background:#fef9c3;color:#854d0e;',
      'cancelled':       'background:#fee2e2;color:#991b1b;',
    };
    const style = map[s] || 'background:#f1f5f9;color:#475569;';
    const label = ps ? ps.charAt(0).toUpperCase() + ps.slice(1) : '—';
    return `<span style="${style}padding:.15em .5em;border-radius:20px;font-size:.68rem;font-weight:700;white-space:nowrap;">${esc(label)}</span>`;
  }

  // ── Mark one attendance record ────────────────────────────────────────
  window.markOne = function (regId, paxId, dn, date, status, notes) {
    fetch('api/mark_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        registration_id: regId,
        participant_id:  paxId,
        training_id:     TRAINING_ID,
        day_number:      dn,
        attendance_date: date || null,
        status, notes,
        _csrf: CSRF,
      }),
    })
    .then(r => r.json())
    .then(res => {
      if (res.status !== 'success') throw new Error(res.message);

      const stEl = document.getElementById(`attStatus_${regId}_${dn}`);
      if (stEl) {
        stEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        stEl.className   = status === 'present' ? 'att-st-present'
                         : status === 'absent'  ? 'att-st-absent'
                         : 'att-st-excused';
      }

      // Update "Days Present" counter for this row
      const sumEl = document.getElementById(`attPresent_${regId}`);
      if (sumEl) {
        let count = 0;
        for (let d = 1; d <= currentTotalDays; d++) {
          const s = document.getElementById(`attStatus_${regId}_${d}`);
          if (s && s.textContent.trim().toLowerCase() === 'present') count++;
        }
        sumEl.innerHTML =
          `<span style="font-size:.78rem;font-weight:700;color:${count > 0 ? '#16a34a' : '#94a3b8'};">${count}</span>` +
          `<span style="font-size:.72rem;color:#cbd5e1;">/${currentTotalDays}</span>`;
      }
    })
    .catch(e => Swal.fire('Error', e.message, 'error'));
  };

  // ── Bulk mark using toolbar day selector ──────────────────────────────
  window.bulkMarkDay = function (status) {
    const sel  = document.getElementById('bulkMarkDaySel');
    const dn   = +(sel.value);
    const date = sel.options[sel.selectedIndex].dataset.date || '';
    bulkMark(dn, date, status);
  };

  window.bulkMark = function (dn, date, status) {
    Swal.fire({
      title: `Mark All ${status.charAt(0).toUpperCase() + status.slice(1)}?`,
      text:  `Set all participants as ${status} for Day ${dn}.`,
      icon: 'question', showCancelButton: true,
      confirmButtonColor: status === 'present' ? '#16a34a' : '#dc2626',
      confirmButtonText: 'Yes, apply',
    }).then(r => {
      if (!r.isConfirmed) return;
      fetch('api/bulk_mark_attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({
          training_id: TRAINING_ID, day_number: dn,
          attendance_date: date || null, status, _csrf: CSRF,
        }),
      })
      .then(r => r.json())
      .then(res => {
        if (res.status !== 'success') throw new Error(res.message);
        Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 1600, showConfirmButton: false });
        attendanceLoaded = false;
        fetchAndRenderAttendance();
      })
      .catch(e => Swal.fire('Error', e.message, 'error'));
    });
  };

  // ── Helpers ───────────────────────────────────────────────────────────
  function esc(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmtDate(d) {
    if (!d) return '';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }

})();
