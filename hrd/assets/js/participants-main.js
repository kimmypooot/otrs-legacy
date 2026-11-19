// participants-main.js — main logic for hrd/participants.php
// Depends on: jQuery, SweetAlert2, DataTables, CONFIG (inline in PHP)

$(function () {

    $.ajaxSetup({ headers: { 'X-CSRF-Token': window.csrfToken ?? '' } });

    // ==========================================
    // 1. UTILS
    // ==========================================
    const UI = {
        toggleLoading: (show) => $('.loading-overlay').toggleClass('d-none', !show),
        toast: (icon, title) => Swal.fire({
            icon,
            title,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        }),
        confirm: (title, html, confirmBtnText = 'Yes') => Swal.fire({
            title,
            html,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmBtnText,
            confirmButtonColor: confirmBtnText === 'Yes, Cancel' ? '#dc3545' : '#3085d6'
        }),
        miniToast: (msg, duration = 2200) => {
            const el = document.getElementById('modalToast');
            if (!el) return;
            el.textContent = msg;
            el.classList.add('show');
            setTimeout(() => el.classList.remove('show'), duration);
        }
    };

    const API = {
        post: (url, data) => {
            const token = window.csrfToken ?? '';
            let body;
            if (typeof data === 'string') {
                body = data + (data ? '&' : '') + '_csrf=' + encodeURIComponent(token);
            } else {
                body = { ...(data || {}), _csrf: token };
            }
            return $.ajax({ url, type: 'POST', data: body, dataType: 'json',
                headers: { 'X-CSRF-Token': token } });
        }
    };

    let _currentPid = null;
    let _currentRid = null;
    let _oldPaymentStatus = '';

    // ── Summary pill live-update ──────────────────────────────────────
    function adjustPillCounts(oldStatus, newStatus) {
        oldStatus = (oldStatus || '').toLowerCase().trim();
        newStatus = (newStatus || '').toLowerCase().trim();
        if (!oldStatus || !newStatus || oldStatus === newStatus) return;
        const map = {
            'paid':            'pillCount-paid',
            'pending':         'pillCount-pending',
            'promissory note': 'pillCount-pn',
            'free':            'pillCount-free',
            'cancelled':       'pillCount-cancelled',
            'not qualified':   'pillCount-nq',
        };
        function adj(key, delta) {
            const el = document.getElementById(map[key]);
            if (!el) return;
            el.textContent = Math.max(0, parseInt(el.textContent || '0') + delta);
        }
        adj(oldStatus, -1);
        adj(newStatus, +1);
    }

    // ==========================================
    // 2. DATATABLE INITIALIZATION
    // ==========================================

    function toTitleCase(str) {
        return (str || '').replace(/\b\w/g, c => c.toUpperCase());
    }

    const isFaceToFace = CONFIG.trainingMode === 'Face to Face';

    // Name format chosen via the export prompt modal — 'lname_first' (Lname, Fname Mname Suffix)
    // or 'fname_first' (Fname Mname Lname Suffix). Used only by the column-3 export renderer.
    let exportNameFormat = 'lname_first';

    // Columns 4-17 order: gender, age, civilstatus, email, mobileno,
    //   positiontitle, salary_grade, employment_status, position_level, location,
    //   home_address, agency_name, sector, agency_address
    const EXTRA_INDICES = [4, 5, 6, 10, 12, 14, 16, 17, 21, 22, 23];
    if (isFaceToFace) EXTRA_INDICES.push(24);
    EXTRA_INDICES.push(isFaceToFace ? 25 : 24);

    const columnDefs = EXTRA_INDICES.map(idx => ({ targets: idx, visible: false }));

    // Group header column ranges — used to keep colspans correct when columns toggle
    const GROUP_DEFS = [
        { cls: 'th-group-personal',     cols: [3, 4, 5, 6, 7, 8] },
        { cls: 'th-group-professional', cols: [9, 10, 11, 12, 13] },
        { cls: 'th-group-agency',       cols: [14, 15, 16, 17] },
        { cls: 'th-group-enrollment',   cols: isFaceToFace ? [18, 19, 20, 21, 22, 23, 24, 25] : [18, 19, 20, 21, 22, 23, 24] },
    ];

    const table = $('#participantsList').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: 'api/fetch_participants_ajax.php',
            type: 'POST',
            data: d => Object.assign(d, CONFIG, { filter: $('#filter').val() }),
            dataSrc: json => json.data,
            beforeSend: () => UI.toggleLoading(true),
            complete:   () => UI.toggleLoading(false),
            error:      () => UI.toast('error', 'Failed to load data')
        },
        columnDefs,
        columns: [
            // 0 — checkbox (value = reg_id for batch operations)
            {
                data: 'reg_id',
                orderable: false,
                className: 'text-center',
                render: (data) => `<input type="checkbox" class="participant-checkbox form-check-input" value="${data}" style="transform:scale(1.3);">`
            },
            // 1 — actions
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: (data, type, row) => {
                    const esc = s => (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                    const isCancelled = (row.registration_status || '').toLowerCase() === 'cancelled'
                        || (row.status || '').toLowerCase() === 'cancelled';
                    if (isCancelled) {
                        return `<button class="btn btn-warning btn-sm reactivate-btn" data-id="${row.reg_id}" title="Reactivate to Pending"><i class="bi bi-arrow-counterclockwise"></i></button>`;
                    }
                    return `<button class="btn btn-primary btn-sm edit-btn" data-id="${row.reg_id}" data-pid="${row.id}" title="Edit"><i class="bi bi-pencil-square"></i></button>`;
                }
            },
            // 2 — row number
            { data: null, render: (d, t, r, m) => m.row + 1, className: 'text-center' },
            // 3 — name
            {
                data: null,
                render: (data, type, row) => {
                    const fname  = row.fname ?? '';
                    const mname  = row.mname ? `${row.mname.charAt(0)}.` : '';
                    const lname  = row.lname ?? '';
                    const suffix = row.suffix ? `, ${row.suffix}` : '';
                    const base   = [fname, mname, lname].filter(Boolean).join(' ');
                    if (type === 'display') return base + suffix;
                    if (type === 'export') {
                        if (exportNameFormat === 'fname_first') return base + suffix;
                        const exportBase = [lname, [fname, row.mname ?? ''].filter(Boolean).join(' ').trim()]
                            .filter(Boolean).join(', ');
                        return exportBase + suffix;
                    }
                    return (`${fname} ${row.mname ?? ''} ${lname}`.trim()) + suffix;
                }
            },
            // — Personal group (4-9) —
            { data: 'gender'            }, // 4  extra
            { data: 'age'               }, // 5  extra
            { data: 'civilstatus'       }, // 6  extra
            { data: 'email'             }, // 7  CORE
            { data: 'mobileno'          }, // 8  CORE
            // — Professional group (9-12) —
            { data: 'positiontitle'     }, // 9  CORE
            { data: 'salary_grade'      }, // 10 extra
            { data: 'employment_status' },
            { data: 'position_level'    }, // 11 extra
            { data: 'location'          }, // 12 CORE (FO)
            // — Agency group (13-16) —
            { data: 'home_address'      }, // 13 extra
            { data: 'agency_name'       }, // 14 CORE
            { data: 'sector'            }, // 15 extra
            { data: 'agency_address'    }, // 16 extra
            // 17 — status badge
            {
                data: null,
                render: (data, type, row) => {
                    const effectiveStatus = (row.registration_status || '').toLowerCase() === 'cancelled'
                        ? 'cancelled'
                        : (row.status || '');
                    const colors = {
                        'pending':         'bg-primary',
                        'cancelled':       'bg-danger',
                        'paid':            'bg-success',
                        'promissory note': 'bg-warning text-dark',
                        'not qualified':   'bg-danger',
                        'free':            'bg-info',
                    };
                    const primeTag = row.prime_hrm_discount ? ' <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;" title="PRIME-HRM 20% Discount">PRIME-HRM</span>' : '';
                    return `<span class="badge ${colors[effectiveStatus.toLowerCase()] || 'bg-secondary'}">${toTitleCase(effectiveStatus)}</span>${primeTag}`;
                }
            },
            // 18 — certificate status (CORE)
            {
                data:      null,
                className: 'text-center',
                orderable: false,
                render: (data, type, row) => {
                    if (row.cert_number) {
                        return `<span class="badge bg-success" style="font-size:.7rem" title="${row.cert_number}">
                                    <i class="bi bi-patch-check-fill me-1"></i>Issued
                                </span>`;
                    }
                    return `<span class="badge bg-secondary" style="font-size:.7rem;opacity:.55">—</span>`;
                }
            },
            // 19 — email status indicators (CORE)
            {
                data:      null,
                className: 'text-center',
                orderable: false,
                render: (data, type, row) => {
                    function emailDot(sent, label, abbr) {
                        if (sent) {
                            const d = new Date(sent);
                            const fmt = d.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
                            return `<span class="badge" style="background:#198754;font-size:.65rem;cursor:default" title="${label} sent ${fmt}">${abbr}</span>`;
                        }
                        return `<span class="badge bg-secondary" style="font-size:.65rem;opacity:.4;cursor:default" title="${label} not sent">${abbr}</span>`;
                    }
                    return `<span style="display:flex;gap:3px;justify-content:center;align-items:center">
                        ${emailDot(row.email_ack_sent,  'Acknowledgment', 'A')}
                        ${emailDot(row.email_adv_sent,  'Advisory',       'D')}
                        ${isFaceToFace ? '' : emailDot(row.email_zoom_sent, 'Zoom Link', 'Z')}
                    </span>`;
                }
            },
            { data: 'orno'               }, // 20 extra
            { data: 'ordate'             }, // 21 extra
            { data: 'collecting_officer' }, // 22 extra
            ...(isFaceToFace ? [{ data: 'food_restrictions' }] : []),
            { data: 'remarks'  },
            // Log button — replaces the former Reg Date column
            {
                data:      null,
                orderable: false,
                className: 'text-center',
                render: (data, type, row) => {
                    if (type === 'export' || type === 'filter' || type === 'sort') {
                        const lastAt = row.last_log_at || row.timereg;
                        if (!lastAt) return '';
                        const d = new Date(lastAt.replace(' ', 'T'));
                        if (isNaN(d)) return '';
                        return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
                             + ' ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
                    }
                    const esc = s => (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                    const name = esc(`${row.fname || ''} ${row.lname || ''}`.trim());
                    return `<button class="btn-pay-log pay-log-btn"
                                data-id="${row.reg_id}"
                                data-name="${name}"
                                title="View registration &amp; payment history">
                                <i class="bi bi-clock-history me-1"></i>Log
                            </button>`;
                }
            }
        ],
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-5'i><'col-sm-7'p>>",
        buttons: [{
            extend: 'excel',
            text: '<i class="bi bi-file-earmark-excel"></i> Export to Excel',
            title: () => {
                const d = new Date();
                return `Participants_${CONFIG.trainingId}_${d.toISOString().slice(0, 10)}`;
            },
            exportOptions: { columns: ':gt(1)', orthogonal: 'export' }
        }],
        drawCallback: () => {
            const filter   = $('#filter').val();
            const showAck  = ['All', 'pending', 'promissory note'].includes(filter);
            const showZoom = ['All', 'paid',    'promissory note'].includes(filter);
            $('#sendEmailAck, #sendEmailAdvisory').toggle(showAck);
            $('#sendEmailZoom').toggle(showZoom);
        }
    });

    table.buttons().container().hide();

    const EXPORT_FORMATS = [
        { value: 'lname_first', label: 'Lastname first', example: 'Dela Cruz, Juan M.', sample: 'Lastname, Firstname M. Suffix' },
        { value: 'fname_first', label: 'Firstname first', example: 'Juan M. Dela Cruz',  sample: 'Firstname M. Lastname Suffix' }
    ];

    function promptExportNameFormat() {
        const optionsHtml = EXPORT_FORMATS.map(f => `
            <label class="export-fmt-opt" data-value="${f.value}">
                <input type="radio" name="exportFmt" value="${f.value}" class="export-fmt-radio">
                <span class="export-fmt-radio-dot"></span>
                <span class="export-fmt-text">
                    <span class="export-fmt-label">${f.label}</span>
                    <span class="export-fmt-example">${f.example}</span>
                    <span class="export-fmt-sample">${f.sample}</span>
                </span>
            </label>
        `).join('');

        return Swal.fire({
            title: 'Export to Excel',
            html: `
                <p class="export-fmt-intro">Choose how the participant name should appear in the exported file.</p>
                <div class="export-fmt-group">${optionsHtml}</div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-file-earmark-excel me-1"></i> Export',
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            focusConfirm: false,
            customClass: { popup: 'export-fmt-popup' },
            didOpen: (popup) => {
                const opts = popup.querySelectorAll('.export-fmt-opt');
                const select = (val) => {
                    opts.forEach(o => o.classList.toggle('is-selected', o.dataset.value === val));
                    popup.querySelector(`input[value="${val}"]`).checked = true;
                };
                opts.forEach(o => o.addEventListener('click', () => select(o.dataset.value)));
                select(exportNameFormat);
            },
            preConfirm: () => {
                const checked = Swal.getPopup().querySelector('.export-fmt-radio:checked');
                return checked ? checked.value : exportNameFormat;
            }
        });
    }

    $('#exportButton').click(() => {
        promptExportNameFormat().then((result) => {
            if (!result.isConfirmed) return;
            exportNameFormat = result.value;
            table.button('.buttons-excel').trigger();
        });
    });

    // Inject group header row AFTER DataTables init so it never sees the multi-row thead
    const enrollmentCols = isFaceToFace ? 6 : 5;
    $('#participantsList thead').prepend(`
        <tr>
            <th class="th-group-filler" colspan="3"></th>
            <th class="text-center th-group th-group-personal" colspan="6"><i class="bi bi-person-fill me-1"></i> Personal</th>
            <th class="text-center th-group th-group-professional" colspan="4"><i class="bi bi-briefcase-fill me-1"></i> Professional</th>
            <th class="text-center th-group th-group-agency" colspan="4"><i class="bi bi-building me-1"></i> Agency</th>
            <th class="text-center th-group th-group-enrollment" colspan="${enrollmentCols}"><i class="bi bi-clipboard2-check-fill me-1"></i> Enrollment</th>
            <th class="th-group-filler"></th>
        </tr>
    `);

    // Sync group header colspans with currently visible columns
    const updateGroupHeaders = () => {
        GROUP_DEFS.forEach(({ cls, cols }) => {
            const $th = $('#participantsList thead tr:first-child .' + cls);
            if (!$th.length) return;
            const n = cols.filter(i => table.column(i).visible()).length;
            if (n === 0) $th.hide();
            else $th.show().attr('colspan', n);
        });
    };
    updateGroupHeaders();

    // ==========================================
    // 3. COLUMN VISIBILITY TOGGLE
    // ==========================================
    let columnsExpanded = false;

    $('#toggleColumns').on('click', function () {
        columnsExpanded = !columnsExpanded;
        EXTRA_INDICES.forEach(idx => table.column(idx).visible(columnsExpanded));
        $(this).toggleClass('btn-f-toggle-active', columnsExpanded);
        $('#toggleColumnsLabel').text(columnsExpanded ? 'Core Only' : 'Show All');
        $('#colToggleHint').text(columnsExpanded ? 'Extended view' : 'Core view');
        updateGroupHeaders();
    });

    // ==========================================
    // 4. EVENT HANDLERS & MODAL ENHANCEMENTS
    // ==========================================

    $('#filter').on('change', () => table.ajax.reload());

    // ── SELECT ALL ────────────────────────────────────────────
    $('#checkAll').on('change', function () {
        $('.participant-checkbox').prop('checked', this.checked);
    });

    // ── SHIFT+CLICK RANGE SELECT ──────────────────────────────
    let lastChecked = null;

    $('#participantsList').on('click', '.participant-checkbox', function (e) {
        if (e.shiftKey && lastChecked && lastChecked !== this) {
            const $all   = $('.participant-checkbox');
            const idxA   = $all.index(lastChecked);
            const idxB   = $all.index(this);
            const from   = Math.min(idxA, idxB);
            const to     = Math.max(idxA, idxB);
            const target = $(lastChecked).prop('checked');
            $all.slice(from, to + 1).prop('checked', target);
        } else {
            lastChecked = this;
        }

        const total   = $('.participant-checkbox').length;
        const checked = $('.participant-checkbox:checked').length;
        $('#checkAll').prop({
            checked:       checked === total,
            indeterminate: checked > 0 && checked < total
        });
    });

    $('#participantsList').on('draw.dt', function () {
        lastChecked = null;
        $('#checkAll').prop({ checked: false, indeterminate: false });
    });

    const getSelectedIds = () => {
        const ids = $('.participant-checkbox:checked').map((i, el) => $(el).val()).get();
        if (ids.length === 0) Swal.fire('Warning', 'Please select at least one participant.', 'warning');
        return ids;
    };

    // ── MODAL TAB SWITCHING ───────────────────────────────────

    function switchTab(tabId) {
        $('.modal-tab-btn').each(function () {
            const $btn = $(this);
            $btn.toggleClass('active', $btn.data('tab') === tabId);
            $btn.attr('aria-selected', $btn.data('tab') === tabId);
        });
        $('.tab-pane-content').each(function () {
            $(this).toggleClass('active', this.id === tabId);
        });
        $('.tab-dot').each(function () {
            $(this).toggleClass('active', $(this).data('for') === tabId);
        });
    }

    $('.modal-tab-btn').click(function () { switchTab($(this).data('tab')); });
    $('.tab-dot').click(function ()       { switchTab($(this).data('for')); });

    const STATUS_CONFIG = {
        'paid':            { cls: 'paid',    alert: 'Paid — OR details required below.',       icon: 'bi-check-circle-fill' },
        'pending':         { cls: 'pending', alert: 'Pending — waiting for payment.',           icon: 'bi-hourglass-split' },
        'promissory note': { cls: 'pn',      alert: 'Promissory Note — Remarks is required below.', icon: 'bi-journal-text' },
        'free':            { cls: 'free',    alert: 'Free — no payment required.',              icon: 'bi-gift' },
        'cancelled':       { cls: 'cancel',  alert: 'Cancelled — slot will be returned.',       icon: 'bi-x-circle-fill' },
        'not qualified':   { cls: 'nq',      alert: 'Not Qualified — registration denied.',     icon: 'bi-slash-circle' },
    };

    function syncStatusUI(value) {
        $('#status').val(value);
        const alert  = $('#statusAlert');
        const alertT = $('#statusAlertText');
        const cfg    = STATUS_CONFIG[value] || {};

        if (cfg.alert) {
            alert.removeClass().addClass('status-alert mb-3 ' + (cfg.cls || ''));
            alertT.html(`<i class="bi ${cfg.icon} me-1"></i> ${cfg.alert}`);
        } else {
            alert.removeClass().addClass('status-alert mb-3 d-none');
        }

        const showOR   = value === 'paid';
        const $orGroup = $('#orFieldsGroup');
        if (showOR) {
            $orGroup.removeClass('hidden');
            if (!$('#collecting_officer option:not([value=""])').length) {
                loadCollectingOfficers();
            }
        } else {
            $orGroup.addClass('hidden');
            $('#orno').val('');
            $('#ordate').val('');
            $('#collecting_officer').val('');
        }
        if (showOR) {
            $('#primeHrmGroup').removeClass('hidden');
        } else {
            $('#primeHrmGroup').addClass('hidden');
            $('#prime_hrm_discount').prop('checked', false);
        }
        $('#remarksRequired').toggle(['promissory note', 'not qualified'].includes(value));
    }

    $('.payment-opt').change(function () { syncStatusUI($(this).val()); });

    let coLoaded = {};
    function loadCollectingOfficers(location) {
        const loc = location || $('#location').val() || '';
        if (coLoaded[loc]) return;
        coLoaded[loc] = true;

        API.post('api/fetch_co_dropdown.php', { location: loc }).then(res => {
            const $sel   = $('#collecting_officer');
            const current = $sel.val();
            $sel.html('<option value="">— Select Officer —</option>');
            (res || []).forEach(co => {
                $sel.append(`<option value="${co.name}">${co.name}</option>`);
            });
            if (current) $sel.val(current);
        });
    }

    $('#location').change(function () {
        coLoaded = {};
        if ($('#status').val() === 'paid') loadCollectingOfficers($(this).val());
    });

    function showSkeleton() {
        $('#modalSkeleton').show();
        $('#updateForm').hide();
        $('#tabProgress').hide();
    }

    function hideSkeleton() {
        $('#modalSkeleton').hide();
        $('#updateForm').show();
        $('#tabProgress').css('display', 'flex');
    }

    function makeInitials(fname, lname) {
        return ((fname || '').charAt(0) + (lname || '').charAt(0)).toUpperCase() || '?';
    }

    const REQUIRED_FIELDS = {
        'tab-personal':     ['fname', 'lname', 'email'],
        'tab-professional': ['positiontitle'],
        'tab-agency':       ['agency_name'],
        'tab-enrollment':   [],
    };

    function validateTab(tabId) {
        const fields = REQUIRED_FIELDS[tabId] || [];
        let valid = true;
        fields.forEach(id => {
            const $el = $(`#${id}`);
            if (!$el.length) return;
            const empty = !$el.val().trim();
            $el.toggleClass('is-invalid', empty);
            if (empty) valid = false;
        });
        $(`#badge-${tabId.replace('tab-', '')}`).toggleClass('show', !valid);
        return valid;
    }

    function validateAll() {
        let ok = Object.keys(REQUIRED_FIELDS).reduce((acc, tab) => validateTab(tab) && acc, true);
        if (['promissory note', 'not qualified'].includes($('#status').val())) {
            const $rem = $('#remarks');
            const empty = !$rem.val().trim();
            $rem.toggleClass('is-invalid', empty);
            $('#badge-enrollment').toggleClass('show', empty);
            if (empty) ok = false;
        }
        if ($('#status').val() === 'paid') {
            const ornoEmpty   = !$('#orno').val().trim();
            const ordateEmpty = !$('#ordate').val().trim();
            $('#orno').toggleClass('is-invalid', ornoEmpty);
            $('#ordate').toggleClass('is-invalid', ordateEmpty);
            if (ornoEmpty || ordateEmpty) {
                $('#badge-enrollment').addClass('show');
                ok = false;
            }
        }
        return ok;
    }

    $('#updateForm .form-control, #updateForm .form-select').on('input change', function () {
        $(this).removeClass('is-invalid');
    });

    function populateForm(p) {
        $('#fname').val(p.fname || '');
        $('#mname').val(p.mname || '');
        $('#lname').val(p.lname || '');
        $('#suffix').val(p.suffix || '');
        $('#age').val(p.age || '');
        $('#email').val(p.email || '');
        $('#gender').val(p.gender || '');
        $('#pwd').val(p.pwd || 'No');
        $('#civilstatus').val(p.civilstatus || '');
        $('#contactno').val(p.mobileno || '');

        $('#positiontitle').val(p.positiontitle || '');
        $('#salary_grade').val(p.salary_grade || '');
        $('#employment_status').val(p.employment_status || '');
        $('#position_level').val(p.position_level || '');
        $('#location').val(p.location || 'HRD');

        $('#agency_name').val(p.agency_name || '');
        $('#agency_address').val(p.agency_address || '');
        $('#home_address').val(p.home_address || '');

        $('#orno').val(p.orno || '');
        $('#ordate').val(p.ordate || '');
        $('#status_training').val(p.status_training || 'completed');
        $('#remarks').val(p.remarks || '');
        if ($('#food_restrictions').length) $('#food_restrictions').val(p.food_restrictions || '');
        $('#prime_hrm_discount').prop('checked', !!p.prime_hrm_discount);

        if (p.participant_id) $('#participantId').val(p.participant_id);
        $('#enrollmentId').val(p.enrollment_id || '');

        const statusVal = (p.status || 'pending').toLowerCase();
        _oldPaymentStatus = statusVal;
        $(`.payment-opt[value="${CSS.escape(statusVal)}"]`).prop('checked', true);
        syncStatusUI(statusVal);

        if (statusVal === 'paid') {
            loadCollectingOfficers(p.location);
            setTimeout(() => {
                if (p.collecting_officer) $('#collecting_officer').val(p.collecting_officer);
            }, 600);
        }

        const fname = p.fname || '', lname = p.lname || '';
        $('#fullName').text(`${fname} ${lname}`.trim() || 'Unknown');
        $('#modalAvatar').text(makeInitials(fname, lname));
        $('#modalEnrollmentMeta').text(`Enrollment #${p.enrollment_id || '—'} · ${p.status_training || '—'}`);

        $('.tab-badge').removeClass('show');
        $('.is-invalid').removeClass('is-invalid');
        switchTab('tab-personal');
    }

    // ── EDIT ──────────────────────────────────────────────────
    $('#participantsList').on('click', '.edit-btn', function () {
        const enrollmentId  = $(this).data('id');
        const participantId = $(this).data('pid');
        _currentPid = participantId;
        _currentRid = enrollmentId;
        showSkeleton();
        switchTab('tab-personal');
        $('#participantId').val(participantId);
        $('#enrollmentId').val(enrollmentId);
        $('#updateModal').modal('show');

        API.post('api/fetch_participant.php', { enrollmentId }).then(res => {
            hideSkeleton();
            if (res.status === 'success') {
                populateForm(res.participant);
            } else {
                $('#updateModal').modal('hide');
                Swal.fire('Error', res.message || 'Failed to fetch details', 'error');
            }
        }).fail(() => {
            hideSkeleton();
            Swal.fire('Error', 'Network error. Please try again.', 'error');
        });
    });

    // ── UPDATE SUBMIT ─────────────────────────────────────────
    $('#updateParticipantBtn').click(function () {
        if (!validateAll()) {
            UI.miniToast('⚠ Please fill in all required fields.');
            const firstErrorTab = Object.keys(REQUIRED_FIELDS).find(tab => {
                if ((REQUIRED_FIELDS[tab] || []).some(id => { const el = document.getElementById(id); return el && !el.value.trim(); })) return true;
                if (tab === 'tab-enrollment' && $('#status').val() === 'promissory note') {
                    const rem = document.getElementById('remarks');
                    return rem && !rem.value.trim();
                }
                return false;
            });
            if (firstErrorTab) switchTab(firstErrorTab);
            return;
        }

        const statusVal = $('#status').val();

        const proceedUpdate = () => {
            const $btn = $('#updateParticipantBtn');
            $btn.addClass('loading').prop('disabled', true);
            const pid  = _currentPid || $('#participantId').val();
            const rid  = _currentRid || $('#enrollmentId').val();
            const data = $('#updateForm').serialize()
                + '&participantId=' + encodeURIComponent(pid)
                + '&regId='         + encodeURIComponent(rid);

            API.post('api/update_participant.php', data).then(res => {
                $btn.removeClass('loading').prop('disabled', false);
                if (res.status === 'success') {
                    adjustPillCounts(_oldPaymentStatus, $('#status').val());
                    $('#updateModal').modal('hide');
                    UI.toast('success', 'Participant updated');
                    table.ajax.reload(null, false);
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }).fail(() => {
                $btn.removeClass('loading').prop('disabled', false);
                Swal.fire('Error', 'Request failed. Please try again.', 'error');
            });
        };

        if (statusVal === 'cancelled') {
            UI.confirm(
                'Cancel Registration?',
                'This will mark the participant as <strong>Cancelled</strong> and return the training slot.',
                'Yes, Cancel'
            ).then(res => { if (res.isConfirmed) proceedUpdate(); });
        } else {
            proceedUpdate();
        }
    });

    $('#updateModal').on('hidden.bs.modal', function () {
        showSkeleton();
        $('#updateForm')[0].reset();
        $('.payment-opt').prop('checked', false);
        $('#status').val('');
        $('#statusAlert').removeClass().addClass('status-alert mb-3 d-none');
        $('#orFieldsGroup').removeClass('hidden');
        $('#collecting_officer').html('<option value="">— Select Officer —</option>');
        $('#remarksRequired').hide();
        $('#fullName').text('Loading participant…');
        $('#modalAvatar').text('?');
        $('.is-invalid').removeClass('is-invalid');
        $('.tab-badge').removeClass('show');
        coLoaded = {};
    });

    // ── MODAL DELETE ──────────────────────────────────────────
    $('#deleteParticipantBtn').on('click', function () {
        const id = _currentPid;
        if (!id) return;
        UI.confirm('Delete Participant?', 'This cannot be undone!', 'Delete').then(res => {
            if (res.isConfirmed) {
                $('#updateModal').modal('hide');
                API.post('api/delete_participant.php', { id }).then(res => {
                    if (res.success) {
                        UI.toast('success', 'Deleted successfully');
                        table.ajax.reload(null, false);
                    } else {
                        Swal.fire('Error', res.error, 'error');
                    }
                });
            }
        });
    });

    // ── REACTIVATE ────────────────────────────────────────────
    $('#participantsList').on('click', '.reactivate-btn', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Reactivate Participant?',
            html: 'This will restore the participant\'s status to <strong>Pending</strong> and reclaim one training slot.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Reactivate',
            confirmButtonColor: '#e07b00'
        }).then(res => {
            if (!res.isConfirmed) return;
            API.post('api/reactivate_participant.php', { participantId: id }).then(res => {
                if (res.success) {
                    UI.toast('success', 'Participant reactivated to Pending');
                    table.ajax.reload(null, false);
                } else {
                    Swal.fire('Error', res.message || 'Failed to reactivate participant.', 'error');
                }
            }).fail(() => {
                Swal.fire('Error', 'Request failed. Please try again.', 'error');
            });
        });
    });

    // ── BATCH CANCELLATION ────────────────────────────────────
    $('#batchCancelBtn').click(() => {
        const ids = getSelectedIds();
        if (!ids.length) return;

        Swal.fire({
            title: 'Batch Cancellation',
            html: `Cancel <strong>${ids.length}</strong> selected participant(s)?<br>
                   <small class="text-muted">Occupied slots will be automatically returned.</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Cancel Them',
            confirmButtonColor: '#e07b00',
            cancelButtonText: 'Go Back'
        }).then(res => {
            if (!res.isConfirmed) return;
            UI.toggleLoading(true);
            API.post('api/batch_cancel_participants.php', { participantIds: JSON.stringify(ids) }).then(res => {
                UI.toggleLoading(false);
                if (res.success) {
                    UI.toast('success', res.message);
                    $('#checkAll').prop('checked', false);
                    table.ajax.reload(null, false);
                } else {
                    Swal.fire('Notice', res.message, res.cancelled === 0 ? 'info' : 'error');
                }
            }).fail(() => {
                UI.toggleLoading(false);
                Swal.fire('Error', 'Request failed. Please try again.', 'error');
            });
        });
    });

    // ── BULK UPDATE STATUS ────────────────────────────────────
    $('#updateSelectedBtn').click(() => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        API.post('api/update_participants.php', {
            participantIds: JSON.stringify(ids),
            newStatus: $('#newStatusSelect').val()
        }).then(() => {
            $('#updateStatusModal').modal('hide');
            UI.toast('success', 'Statuses updated');
            table.ajax.reload(null, false);
        });
    });

    // ── TRANSFER ──────────────────────────────────────────────
    $('#transferTraining').click(() => {
        const ids = getSelectedIds();
        if (ids.length) {
            $('#transferParticipantIds').val(ids.join(','));
            $('#transferTrainingModal').modal('show');
        }
    });

    $('#confirmTransfer').click(() => {
        API.post('api/transfer_participant.php', $('#transferForm').serialize()).then(res => {
            if (res.success) {
                $('#transferTrainingModal').modal('hide');
                UI.toast('success', 'Transferred successfully');
                table.ajax.reload();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        });
    });

    // ==========================================
    // 5. SEND EMAILS  (batched · max 25 per batch)
    // ==========================================

    const BATCH_SIZE = 25;

    function chunkArray(arr, size) {
        const out = [];
        for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
        return out;
    }

    function buildProgressHtml() {
        return `
        <div style="text-align:left">
          <div style="background:#e9ecef;border-radius:6px;overflow:hidden;height:20px;margin-bottom:.45rem">
            <div id="ep-bar" style="width:0%;height:100%;background:linear-gradient(90deg,#012970,#0a58ca);transition:width .3s ease;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#fff;font-weight:700;min-width:2rem">0%</div>
          </div>
          <div id="ep-status" style="font-size:.8rem;color:#555;margin-bottom:.55rem;text-align:center;min-height:1.1rem">Preparing…</div>
          <div id="ep-log" style="max-height:180px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;padding:.4rem .55rem;background:#f8f9fa;font-size:.77rem"></div>
          <div id="ep-summary" style="display:none;margin-top:.6rem;padding:.5rem .8rem;border-radius:6px;font-size:.83rem;font-weight:600"></div>
        </div>`;
    }

    function epEl(id) {
        const c = Swal.getHtmlContainer();
        return c ? c.querySelector('#' + id) : null;
    }

    function updateEpBar(pct) {
        const bar = epEl('ep-bar');
        if (bar) { bar.style.width = pct + '%'; bar.textContent = pct + '%'; }
    }

    function updateEpStatus(msg) {
        const el = epEl('ep-status');
        if (el) el.textContent = msg;
    }

    function appendEpLog(batchNum, sent, failed, errors) {
        const log = epEl('ep-log');
        if (!log) return;
        const icon  = failed === 0 ? '✔' : (sent === 0 ? '✘' : '⚠');
        const color = failed === 0 ? '#198754' : (sent === 0 ? '#dc3545' : '#856404');
        const row   = document.createElement('div');
        row.style.cssText = 'padding:.2rem 0;border-bottom:1px solid #e9ecef;display:flex;align-items:flex-start;gap:.4rem';
        row.innerHTML = `<span style="color:${color};font-size:.88rem;flex-shrink:0">${icon}</span>
            <span>Batch ${batchNum}: <b>${sent}</b> sent, <b>${failed}</b> failed</span>`;
        log.appendChild(row);
        if (errors && errors.length) {
            errors.forEach(e => {
                const d = document.createElement('div');
                d.style.cssText = 'padding-left:1.2rem;color:#dc3545;font-size:.7rem;word-break:break-word';
                d.textContent = e;
                log.appendChild(d);
            });
        }
        log.scrollTop = log.scrollHeight;
    }

    function showEpSummary(totalSent, totalFailed) {
        const el = epEl('ep-summary');
        if (!el) return;
        el.style.display = 'block';
        if (totalFailed === 0) {
            el.style.background = '#d1e7dd'; el.style.color = '#0f5132';
            el.innerHTML = `<b>${totalSent}</b> email${totalSent !== 1 ? 's' : ''} sent successfully.`;
        } else if (totalSent === 0) {
            el.style.background = '#f8d7da'; el.style.color = '#842029';
            el.innerHTML = `All <b>${totalFailed}</b> failed to send.`;
        } else {
            el.style.background = '#fff3cd'; el.style.color = '#664d03';
            el.innerHTML = `<b>${totalSent}</b> sent, <b>${totalFailed}</b> failed.`;
        }
        const actions = Swal.getActions();
        if (actions) actions.style.display = 'flex';
        const btn = Swal.getConfirmButton();
        if (btn) { btn.style.display = ''; btn.textContent = 'Close'; }
    }

    async function sendEmailBatched(title, ids, requestFn) {
        const batches = chunkArray(ids, BATCH_SIZE);
        Swal.fire({
            title,
            html:              buildProgressHtml(),
            allowOutsideClick: false,
            allowEscapeKey:    false,
            showConfirmButton: false,
        });

        let cumSent = 0, cumFailed = 0;
        for (let i = 0; i < batches.length; i++) {
            const batch = batches[i];
            updateEpBar(Math.round((i / batches.length) * 100));
            updateEpStatus(`Sending batch ${i + 1} of ${batches.length} (${batch.length} participant${batch.length !== 1 ? 's' : ''})…`);
            try {
                const res    = await requestFn(batch);
                const sent   = res.sent   ?? (res.success ? batch.length : 0);
                const failed = res.failed ?? (res.success ? 0 : batch.length);
                const errors = res.errors ?? [];
                cumSent   += sent;
                cumFailed += failed;
                appendEpLog(i + 1, sent, failed, errors);
            } catch (err) {
                cumFailed += batch.length;
                const msg = err.responseJSON?.message || err.statusText || 'Unknown error';
                appendEpLog(i + 1, 0, batch.length, [`Request failed: ${msg}`]);
            }
        }
        updateEpBar(100);
        updateEpStatus('Done.');
        showEpSummary(cumSent, cumFailed);
    }

    function handleEmail(btnId, endpoint) {
        $(btnId).on('click', () => {
            const ids        = getSelectedIds();
            if (!ids.length) return;
            const label      = $(btnId).text().trim();
            const batchCount = Math.ceil(ids.length / BATCH_SIZE);
            const batchNote  = ids.length > BATCH_SIZE
                ? ` <small style="color:#666">(${batchCount} batches of up to ${BATCH_SIZE})</small>`
                : '';
            UI.confirm('Send Emails?',
                `Send <b>${label}</b> to <b>${ids.length}</b> participant${ids.length !== 1 ? 's' : ''}?${batchNote}`
            ).then(res => {
                if (!res.isConfirmed) return;
                sendEmailBatched(label, ids, (batch) => $.ajax({
                    url:      `${endpoint}?id=${CONFIG.trainingId}`,
                    type:     'POST',
                    data:     { participantIds: JSON.stringify(batch), _csrf: window.csrfToken ?? '' },
                    headers:  { 'X-CSRF-Token': window.csrfToken ?? '' },
                    dataType: 'json'
                }));
            });
        });
    }

    handleEmail('#sendEmailAck',      'api/send_email-ack.php');
    handleEmail('#sendEmailAdvisory', 'api/send_email-adv.php');

    $('#sendEmailZoom').on('click', () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        $('#zoomLinkModal').modal('show');
    });

    $('#confirmSendEmailZoom').on('click', () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        const zoomLink = $('#zoomLinkInput').val();
        const pdfFile  = $('#zoomPDF')[0].files[0];
        const bgFile   = $('#zoomBg')[0].files[0];
        $('#zoomLinkModal').modal('hide');
        sendEmailBatched('Zoom Link', ids, (batch) => {
            const fd = new FormData();
            fd.append('participantIds', JSON.stringify(batch));
            fd.append('zoomLinkInput',  zoomLink);
            fd.append('_csrf',          window.csrfToken ?? '');
            if (pdfFile) fd.append('zoomPDF', pdfFile);
            if (bgFile)  fd.append('zoomBg',  bgFile);
            return $.ajax({
                url:         `api/send_email-link.php?id=${CONFIG.trainingId}`,
                type:        'POST',
                data:        fd,
                processData: false,
                contentType: false,
                headers:     { 'X-CSRF-Token': window.csrfToken ?? '' },
                dataType:    'json'
            });
        });
    });

    // ── REPLACE PARTICIPANT ────────────────────────────────
    function repSwitchTab(tabId) {
        $('#replaceModal .rep-tab-btn').each(function () {
            const active = $(this).data('tab') === tabId;
            $(this).toggleClass('active', active).attr('aria-selected', active);
        });
        $('#replaceModal .tab-pane-content').each(function () {
            $(this).toggleClass('active', this.id === tabId);
        });
    }

    $('#replaceModal').on('click', '.rep-tab-btn', function () {
        repSwitchTab($(this).data('tab'));
    });

    $('#replaceFromUpdateBtn').on('click', function () {
        const fname = $('#fname').val().trim() || '';
        const lname = $('#lname').val().trim() || '';
        const name  = lname ? `${lname}, ${fname}` : (fname || '—');

        $('#replaceForm')[0].reset();
        $('#rep_enrollmentId').val(_currentRid);
        $('#rep_trainingId').val(CONFIG.trainingId);
        $('#rep_originalParticipantId').val(_currentPid);
        $('#rep_originalName').text(name);
        $('#rep_foodRow').toggle(CONFIG.trainingMode === 'Face to Face');
        repSwitchTab('rep-tab-personal');

        $('#updateModal').one('hidden.bs.modal', function () {
            $('#replaceModal').modal('show');
        });
        $('#updateModal').modal('hide');
    });

    $('#replaceParticipantBtn').on('click', function () {
        const fname  = $('#rep_fname').val().trim();
        const lname  = $('#rep_lname').val().trim();
        const reason = $('#rep_replacement_reason').val().trim();

        if (!fname || !lname) {
            Swal.fire('Missing Fields', 'First name and last name are required.', 'warning');
            repSwitchTab('rep-tab-personal');
            return;
        }
        if (!reason) {
            Swal.fire('Missing Fields', 'Please provide a replacement reason.', 'warning');
            repSwitchTab('rep-tab-reason');
            return;
        }

        const $btn = $(this);
        $btn.addClass('loading').prop('disabled', true);

        fetch('api/replace_participant.php', {
            method:  'POST',
            headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
            body:    new FormData($('#replaceForm')[0])
        })
        .then(r => r.json())
        .then(res => {
            $btn.removeClass('loading').prop('disabled', false);
            if (res.status === 'success') {
                $('#replaceModal').modal('hide');
                UI.toast('success', 'Participant replaced successfully');
                table.ajax.reload(null, false);
            } else {
                Swal.fire('Error', res.message || 'Replacement failed.', 'error');
            }
        })
        .catch(() => {
            $btn.removeClass('loading').prop('disabled', false);
            Swal.fire('Error', 'Request failed. Please try again.', 'error');
        });
    });

    $('#replaceModal').on('hidden.bs.modal', function () {
        $('#replaceForm')[0].reset();
        $('#rep_originalName').text('—');
        repSwitchTab('rep-tab-personal');
    });

    // ── REGISTRATION & PAYMENT LOG TIMELINE ───────────────────
    $('#participantsList').on('click', '.pay-log-btn', function () {
        const regId = $(this).data('id');
        const name  = $(this).data('name') || 'Participant';

        $('#payLogName').text(name);
        $('#payLogMeta').text(`Enrollment #${regId} · Registration & Payment Log`);
        $('#payLogBody').html(
            '<div class="text-center py-4 text-muted">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading log…</div>'
        );
        $('#payLogModal').modal('show');

        API.post('api/fetch_reg_payment_log.php', { enrollment_id: regId })
            .then(res => {
                if (res.status !== 'success') {
                    $('#payLogBody').html(
                        `<div class="alert alert-danger">${res.message || 'Failed to load log.'}</div>`
                    );
                    return;
                }
                $('#payLogBody').html(buildPayLogTimeline(res));
            })
            .fail(() => {
                $('#payLogBody').html(
                    '<div class="alert alert-danger">Network error. Please try again.</div>'
                );
            });
    });

    function buildPayLogTimeline(res) {
        const esc = s => (s || '—')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

        const statusCls = s => {
            const m = {
                'paid': 'paid', 'pending': 'pending',
                'cancelled': 'cancel', 'promissory note': 'pn',
                'free': 'free', 'not qualified': 'nq'
            };
            return m[(s || '').toLowerCase()] || 'other';
        };
        const statusIcon = s => {
            const m = {
                'paid':            'bi-check-circle-fill',
                'pending':         'bi-hourglass-split',
                'cancelled':       'bi-x-circle-fill',
                'promissory note': 'bi-journal-text',
                'free':            'bi-gift',
                'not qualified':   'bi-slash-circle'
            };
            return m[(s || '').toLowerCase()] || 'bi-circle';
        };
        const badge = s => `<span class="plt-badge ${statusCls(s)}">${esc(s)}</span>`;
        const fmtDate = str => {
            if (!str) return '—';
            const d = new Date(str.replace(' ', 'T'));
            if (isNaN(d)) return str;
            return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
                 + ' ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
        };

        let html = '<ul class="pay-log-timeline list-unstyled mb-0">';

        // ── Registration event (always first) ────────────────────
        html += `
            <li class="plt-item">
                <div class="plt-dot reg"><i class="bi bi-person-check-fill"></i></div>
                <div class="plt-body">
                    <div class="plt-title">Participant Registered</div>
                    <div class="plt-meta">
                        <span class="plt-meta-item">
                            <i class="bi bi-calendar3"></i>${fmtDate(res.registered_at)}
                        </span>
                        <span class="plt-meta-item">
                            <i class="bi bi-tag"></i>Initial status: ${badge(res.initial_status)}
                        </span>
                    </div>
                </div>
            </li>`;

        // ── All events (payment, cancellation, replacement) ──────
        if (!res.logs || res.logs.length === 0) {
            html += `
                <li class="plt-item" style="padding-bottom:0;">
                    <div class="plt-dot other"><i class="bi bi-info-circle"></i></div>
                    <div class="plt-body">
                        <div class="plt-no-logs">
                            <i class="bi bi-info-circle-fill"></i>
                            No activity has been recorded for this enrollment.
                        </div>
                    </div>
                </li>`;
        } else {
            res.logs.forEach(log => {
                const type = log.event_type || 'payment';

                if (type === 'payment') {
                    const sc = statusCls(log.new_status);
                    const orRow = (log.or_no || log.or_date || log.collecting_officer) ? `
                                <div class="plt-meta mt-1">
                                    ${log.or_no   ? `<span class="plt-meta-item"><i class="bi bi-receipt"></i>OR No.: <strong>${esc(log.or_no)}</strong></span>` : ''}
                                    ${log.or_date ? `<span class="plt-meta-item"><i class="bi bi-calendar-check"></i>OR Date: <strong>${esc(log.or_date)}</strong></span>` : ''}
                                    ${log.collecting_officer ? `<span class="plt-meta-item"><i class="bi bi-person-badge"></i>${esc(log.collecting_officer)}</span>` : ''}
                                </div>` : '';
                    html += `
                        <li class="plt-item">
                            <div class="plt-dot ${sc}"><i class="bi ${statusIcon(log.new_status)}"></i></div>
                            <div class="plt-body">
                                <div class="plt-title">${esc(log.action)}</div>
                                <div class="plt-meta">
                                    <span class="plt-meta-item">
                                        ${badge(log.old_status)}
                                        <i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>
                                        ${badge(log.new_status)}
                                    </span>
                                </div>
                                ${orRow}
                                <div class="plt-meta mt-2">
                                    <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                    <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                                </div>
                            </div>
                        </li>`;

                } else if (type === 'cancellation') {
                    html += `
                        <li class="plt-item">
                            <div class="plt-dot cancel-req"><i class="bi bi-send-exclamation-fill"></i></div>
                            <div class="plt-body">
                                <div class="plt-title">${esc(log.action)}</div>
                                ${log.remarks ? `<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;color:#92400e;">"${esc(log.remarks)}"</span></div>` : ''}
                                <div class="plt-meta mt-2">
                                    <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                    <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                                </div>
                            </div>
                        </li>`;

                } else if (type === 'cancellation_review') {
                    const approved = (log.request_status || '').toLowerCase() === 'approved';
                    const dotCls   = approved ? 'paid' : 'cancel';
                    const icon     = approved ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                    html += `
                        <li class="plt-item">
                            <div class="plt-dot ${dotCls}"><i class="bi ${icon}"></i></div>
                            <div class="plt-body">
                                <div class="plt-title">${esc(log.action)}</div>
                                ${log.remarks ? `<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;">"${esc(log.remarks)}"</span></div>` : ''}
                                <div class="plt-meta mt-2">
                                    <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                    <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                                </div>
                            </div>
                        </li>`;

                } else if (type === 'replacement') {
                    html += `
                        <li class="plt-item">
                            <div class="plt-dot replace"><i class="bi bi-arrow-left-right"></i></div>
                            <div class="plt-body">
                                <div class="plt-title">${esc(log.action)}</div>
                                <div class="plt-meta">
                                    <span class="plt-meta-item">
                                        <i class="bi bi-person-dash" style="color:#dc2626;"></i>${esc(log.original_name)}
                                        <i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>
                                        <i class="bi bi-person-check" style="color:#16a34a;"></i>${esc(log.new_name)}
                                    </span>
                                </div>
                                ${log.remarks ? `<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;">"${esc(log.remarks)}"</span></div>` : ''}
                                <div class="plt-meta mt-2">
                                    <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                    <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                                </div>
                            </div>
                        </li>`;

                } else if (type === 'profile_update') {
                    html += `
                        <li class="plt-item">
                            <div class="plt-dot other" style="background:#ede9fe;color:#7c3aed;border-color:#ddd6fe;"><i class="bi bi-pencil-square"></i></div>
                            <div class="plt-body">
                                <div class="plt-title">Participant Info Updated</div>
                                ${log.notes ? `<div class="plt-meta"><span class="plt-meta-item" style="color:#6b7280;font-size:.78rem;">${esc(log.notes)}</span></div>` : ''}
                                <div class="plt-meta mt-2">
                                    <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                    <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                                </div>
                            </div>
                        </li>`;
                }
            });
        }

        html += '</ul>';
        return html;
    }

}); // end $(function)
