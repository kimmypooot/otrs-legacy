// participants-analytics.js — analytics report + participant drilldown module
// Depends on: jQuery, Bootstrap 5, DataTables (+ Buttons), CONFIG (inline in PHP)

(function () {
    'use strict';

    /* ── State ─────────────────────────────────────────────────── */
    let suppressAnalyticsClear = false;
    let drilldownRawData       = [];
    let drilldownCurrentLabel  = '';

    /* ── Location labels ───────────────────────────────────────── */
    const locationLabels = {
        '':      'CSC RO VIII - HRD',
        'hrd':   'CSC RO VIII - HRD',
        'lfoi':  'LFOI - Leyte I',
        'lfoii': 'LFOII - Leyte II',
        'bfo':   'BFO - Biliran',
        'wlso':  'WLSO - Western Leyte',
        'slfo':  'SLFO - Southern Leyte',
        'sfo':   'SFO - Samar',
        'esfo':  'ESFO - Eastern Samar',
        'nsfo':  'NSFO - Northern Samar',
        'ro6':   'CSC RO VI',
        'ro7':   'CSC RO VII',
    };

    /* ── HELPERS ───────────────────────────────────────────────── */
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g,  '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function genderPills(m, f, o) {
        let html = '';
        if (m > 0) html += `<span class="badge rounded-pill bg-primary me-1">${m} M</span>`;
        if (f > 0) html += `<span class="badge rounded-pill bg-info text-dark me-1">${f} F</span>`;
        if (o > 0) html += `<span class="badge rounded-pill bg-secondary me-1">${o} O</span>`;
        return html || '<span class="text-muted">—</span>';
    }

    function genderNote(m, f, o) {
        const parts = [];
        if (m > 0) parts.push(`M: ${m}`);
        if (f > 0) parts.push(`F: ${f}`);
        if (o > 0) parts.push(`O: ${o}`);
        return parts.length ? `<small class="text-muted">(${parts.join(' / ')})</small>` : '';
    }

    function sectionLabel(icon, title, subtitle) {
        return `
        <div class="d-flex align-items-center gap-2 mb-3 mt-4">
            <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:30px;height:30px;background:#e8f0fe;">
                <i class="${icon} text-primary" style="font-size:.9rem;"></i>
            </div>
            <div>
                <p class="mb-0 fw-bold text-primary" style="font-size:.85rem;line-height:1.2;">${title}</p>
                ${subtitle ? `<p class="mb-0 text-muted" style="font-size:.72rem;">${subtitle}</p>` : ''}
            </div>
        </div>`;
    }

    /* ── DRILLCELL ─────────────────────────────────────────────── *
     * Wraps a count in a clickable span with drilldown metadata.
     * value=0 renders a muted dash (or bold 0 for total rows).
     */
    function drillCell(value, filterType, filterParams, label, isTotalRow) {
        if (value === 0) {
            return isTotalRow
                ? '<span>0</span>'
                : '<span class="text-muted">—</span>';
        }
        const paramsJson = JSON.stringify(filterParams).replace(/'/g, '&#039;');
        const tip  = `Click to view ${value.toLocaleString()} participant${value !== 1 ? 's' : ''}`;
        return `<span class="drill-num"
                      data-filter-type="${filterType}"
                      data-filter-params='${paramsJson}'
                      data-filter-label="${escHtml(label)}"
                      title="${tip}">${value.toLocaleString()}</span>`;
    }

    /* ── LABEL BUILDERS ────────────────────────────────────────── */
    function paxLabel(gender, cat) {
        const g = gender === 'all' ? 'All Genders' : gender;
        const c = { cs:'Civil Servants', nc_gov:'Non-CS in Govt', nc_other:'Others / Private',
                    pwd_cs:'PWD Civil Servants', all:'All Categories' }[cat] || cat;
        return `${g} — ${c} (Graduates)`;
    }
    function posLabel(level, gender) {
        const l = level  === 'all' ? 'All Levels'  : level;
        const g = gender === 'all' ? 'All Genders' : gender;
        return `${l} — ${g} (CS Graduates)`;
    }
    function secLabel(sector, gender) {
        const s = sector === 'all' ? 'All Gov. Sectors' : sector;
        const g = gender === 'all' ? 'All Genders'      : gender;
        return `${s} — ${g} (CS Graduates)`;
    }

    /* ── STATUS BADGE (drilldown table) ────────────────────────── */
    function getStatusBadge(payStatus, regStatus) {
        const reg = (regStatus || '').toLowerCase();
        const pay = (payStatus  || '').toLowerCase();
        const s   = 'font-size:.7rem;font-weight:700;';
        if (reg === 'cancelled')       return `<span class="badge rounded-pill" style="background:#fee2e2;color:#991b1b;${s}">Cancelled</span>`;
        if (reg === 'not qualified')   return `<span class="badge rounded-pill" style="background:#f1f5f9;color:#475569;${s}">Not Qualified</span>`;
        if (pay === 'paid')            return `<span class="badge rounded-pill" style="background:#dcfce7;color:#15803d;${s}">Paid</span>`;
        if (pay === 'promissory note') return `<span class="badge rounded-pill" style="background:#e0f2fe;color:#0369a1;${s}">Promissory Note</span>`;
        if (pay === 'free')            return `<span class="badge rounded-pill" style="background:#dbeafe;color:#1e40af;${s}">Free</span>`;
        if (pay === 'pending')         return `<span class="badge rounded-pill" style="background:#fef9c3;color:#92400e;${s}">Pending</span>`;
        return `<span class="badge bg-secondary" style="${s}">${escHtml(pay || reg || '—')}</span>`;
    }

    /* ── ANALYTICS RENDERER ────────────────────────────────────── */

    /* Section E collection card */
    function collCard(icon, label, value, colorClass, textClass, statusKey) {
        const isClickable = !!(statusKey && value > 0);
        const paramsJson  = JSON.stringify({ status_filter: statusKey }).replace(/'/g, '&#039;');
        const clickAttrs  = isClickable
            ? ` data-filter-type="collection" data-filter-params='${paramsJson}' data-filter-label="${escHtml(label)}" title="Click to view ${value.toLocaleString()} participants"`
            : '';
        const clickHint   = isClickable
            ? `<p class="mb-0 mt-1" style="font-size:.63rem;color:#3b82f6;"><i class="bi bi-zoom-in me-1"></i>View list</p>`
            : '';
        return `
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 h-100 rounded-3 shadow-sm overflow-hidden${isClickable ? ' drilldown-stat-card' : ''}" style="${isClickable ? 'cursor:pointer;' : ''}"${clickAttrs}>
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0 ${colorClass}"
                         style="width:42px;height:42px;">
                        <i class="${icon} fs-5 ${textClass}"></i>
                    </div>
                    <div>
                        <p class="mb-0 text-muted"
                           style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px;">${label}</p>
                        <p class="mb-0 fw-bold" style="font-size:1.35rem;">${value.toLocaleString()}</p>
                        ${clickHint}
                    </div>
                </div>
            </div>
        </div>`;
    }

    function renderReport(d) {
        const info = d.training_info;
        const pax  = d.pax_breakdown;
        const pos  = d.position_level;
        const sec  = d.sector_breakdown;
        const coll = d.collection_summary;

        let html = '';

        /* ── Training header card ─────────────────────────── */
        html += `
        <div class="card border-0 rounded-3 mb-4"
             style="background:linear-gradient(135deg,#0f4c81,#1a6baa);color:#fff;">
            <div class="card-body p-3">
                <div class="d-flex align-items-start gap-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:46px;height:46px;background:rgba(255,255,255,.18);">
                        <i class="bi bi-mortarboard-fill fs-5"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-bold"
                            style="font-size:.78rem;opacity:.75;letter-spacing:1px;text-transform:uppercase;">
                            Training Analytics Report
                        </h6>
                        <p class="mb-2 fw-bold" style="font-size:1rem;line-height:1.35;">${escHtml(info.name)}</p>
                        <div class="d-flex flex-wrap gap-2" style="font-size:.78rem;opacity:.9;">
                            <span><i class="bi bi-calendar3 me-1"></i>${escHtml(info.date)}</span>
                            <span><i class="bi bi-clock me-1"></i>${info.no_hrs} training hour${info.no_hrs !== 1 ? 's' : ''}</span>
                            <span><i class="bi bi-display me-1"></i>${escHtml(info.mode)}</span>
                            <span><i class="bi bi-geo-alt me-1"></i>${escHtml(info.location)}</span>
                            ${info.fee > 0
                                ? `<span><i class="bi bi-cash me-1"></i>₱${parseFloat(info.fee).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>`
                                : `<span><i class="bi bi-gift me-1"></i>Free Training</span>`}
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        /* ── B. Pax Breakdown ─────────────────────────────── */
        html += sectionLabel('bi bi-people-fill',
            'B. Participants Breakdown (Graduates Only)',
            'Click any number to view the corresponding participant list');

        const paxRows = [...pax.rows, pax.total];
        html += `
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-primary">
                    <tr>
                        <th style="width:90px;"></th>
                        <th class="text-center">Civil Servants</th>
                        <th class="text-center">Non-CS in Govt<br><small class="fw-normal text-muted">(COS / JO)</small></th>
                        <th class="text-center">Others<br><small class="fw-normal text-muted">(Private / Unemployed)</small></th>
                        <th class="text-center">Total Pax Trained</th>
                        <th class="text-center">PWD CS Trained</th>
                    </tr>
                </thead>
                <tbody>
                    ${paxRows.map((r, idx) => {
                        const isTot = idx === paxRows.length - 1;
                        const g     = isTot ? 'all' : r.label;
                        return `
                        <tr class="${isTot ? 'table-dark fw-bold' : (idx % 2 === 0 ? '' : 'table-light')}">
                            <td>${escHtml(r.label)}</td>
                            <td class="text-center">${drillCell(r.cs,       'graduate_pax', {gender:g, participant_cat:'cs'},       paxLabel(g,'cs'),       isTot)}</td>
                            <td class="text-center">${drillCell(r.nc_gov,   'graduate_pax', {gender:g, participant_cat:'nc_gov'},   paxLabel(g,'nc_gov'),   isTot)}</td>
                            <td class="text-center">${drillCell(r.nc_other, 'graduate_pax', {gender:g, participant_cat:'nc_other'}, paxLabel(g,'nc_other'), isTot)}</td>
                            <td class="text-center">${drillCell(r.total,    'graduate_pax', {gender:g, participant_cat:'all'},      paxLabel(g,'all'),      isTot)}</td>
                            <td class="text-center">${drillCell(r.pwd_cs,   'graduate_pax', {gender:g, participant_cat:'pwd_cs'},   paxLabel(g,'pwd_cs'),   isTot)}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>`;

        /* ── C. Position Level ────────────────────────────── */
        html += sectionLabel('bi bi-diagram-3-fill',
            'C. CS Trained per Position Level (Graduates)',
            'Click any number to view the corresponding participant list');

        const posRows     = [...pos.rows, pos.total];
        const hasOthersPL = posRows.some(r => r.o > 0);
        html += `
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-primary">
                    <tr>
                        <th>Position Level</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Gender Breakdown</th>
                        <th class="text-center">Male</th>
                        <th class="text-center">Female</th>
                        ${hasOthersPL ? '<th class="text-center">Others</th>' : ''}
                    </tr>
                </thead>
                <tbody>
                    ${posRows.map((r, idx) => {
                        const isTot = idx === posRows.length - 1;
                        const lv    = isTot ? 'all' : r.level;
                        return `
                        <tr class="${isTot ? 'table-dark fw-bold' : ''}">
                            <td>${escHtml(r.level)}</td>
                            <td class="text-center">${drillCell(r.total, 'graduate_position', {position_level:lv, gender:'all'},    posLabel(lv,'all'),    isTot)}</td>
                            <td class="text-center">${genderPills(r.m, r.f, r.o)}</td>
                            <td class="text-center">${drillCell(r.m,     'graduate_position', {position_level:lv, gender:'Male'},   posLabel(lv,'Male'),   isTot)}</td>
                            <td class="text-center">${drillCell(r.f,     'graduate_position', {position_level:lv, gender:'Female'}, posLabel(lv,'Female'), isTot)}</td>
                            ${hasOthersPL ? `<td class="text-center">${drillCell(r.o, 'graduate_position', {position_level:lv, gender:'Others'}, posLabel(lv,'Others'), isTot)}</td>` : ''}
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>`;

        /* ── D. Sector Breakdown ──────────────────────────── */
        html += sectionLabel('bi bi-building-fill',
            'D. CS Trained per Sector (Graduates)',
            'Click any number to view the corresponding participant list');

        const csT = sec.cs_total;
        html += `
        <div class="alert mb-3 p-2 d-inline-flex align-items-center gap-2 rounded-2"
             style="background:#e8f0fe;border:1px solid #b8d0f5;font-size:.82rem;">
            <i class="bi bi-people-fill text-primary"></i>
            <span><strong>Total CS Trained:</strong>
            ${drillCell(csT.total, 'graduate_sector', {sector:'all', gender:'all'}, 'All CS Graduates (All Gov. Sectors)', false)}
            pax ${genderNote(csT.m, csT.f, csT.o)}</span>
        </div>`;

        const secRows2     = [...sec.rows, sec.total];
        const hasOthersSec = secRows2.some(r => r.o > 0);
        html += `
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-primary">
                    <tr>
                        <th style="width:100px;">Sector</th>
                        <th class="text-center">Gender Breakdown</th>
                        <th class="text-center">Male</th>
                        <th class="text-center">Female</th>
                        ${hasOthersSec ? '<th class="text-center">Others</th>' : ''}
                        <th class="text-center">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${secRows2.map((r, idx) => {
                        const isTot = idx === secRows2.length - 1;
                        const sc    = isTot ? 'all' : r.sector;
                        return `
                        <tr class="${isTot ? 'table-dark fw-bold' : (idx % 2 === 0 ? '' : 'table-light')}">
                            <td>${escHtml(r.sector)}</td>
                            <td class="text-center">${genderPills(r.m, r.f, r.o)}</td>
                            <td class="text-center">${drillCell(r.m,     'graduate_sector', {sector:sc, gender:'Male'},   secLabel(sc,'Male'),   isTot)}</td>
                            <td class="text-center">${drillCell(r.f,     'graduate_sector', {sector:sc, gender:'Female'}, secLabel(sc,'Female'), isTot)}</td>
                            ${hasOthersSec ? `<td class="text-center">${drillCell(r.o, 'graduate_sector', {sector:sc, gender:'Others'}, secLabel(sc,'Others'), isTot)}</td>` : ''}
                            <td class="text-center">${drillCell(r.total, 'graduate_sector', {sector:sc, gender:'all'},    secLabel(sc,'all'),    isTot)}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>`;

        /* ── E. Collection Summary ────────────────────────── */
        html += sectionLabel('bi bi-cash-stack',
            'E. Collection / Registration Summary',
            'Click any card to view the corresponding participant list');

        html += '<div class="row g-3 mb-2">';
        if (coll.is_free) {
            html += collCard('bi bi-gift-fill',         'Free / Non-Paying', coll.free_pax,      'bg-success bg-opacity-10',   'text-success',   'free');
        } else {
            html += collCard('bi bi-check-circle-fill', 'paid',              coll.paid,           'bg-success bg-opacity-10',   'text-success',   'paid');
            html += collCard('bi bi-hourglass-split',   'promissory note',   coll.promissory,     'bg-warning bg-opacity-10',   'text-warning',   'promissory note');
            html += collCard('bi bi-clock-history',     'Pending / Unpaid',  coll.pending,        'bg-primary bg-opacity-10',   'text-primary',   'pending');
        }
        html += collCard('bi bi-x-circle-fill', 'cancelled',      coll.cancelled,      'bg-danger bg-opacity-10',    'text-danger',    'cancelled');
        html += collCard('bi bi-slash-circle',  'not qualified',  coll.not_qualified,  'bg-secondary bg-opacity-10', 'text-secondary', 'not qualified');
        html += collCard('bi bi-people',        'Total Registered',coll.grand_total,   'bg-info bg-opacity-10',      'text-info',      '');
        html += '</div>';

        html += `
        <div class="alert alert-success d-inline-flex align-items-center gap-2 rounded-2 py-2 px-3"
             style="font-size:.82rem;">
            <i class="bi bi-mortarboard-fill text-success"></i>
            <span><strong>Total Graduates (Paid + PN${coll.is_free ? ' + Free' : ''}):</strong>
            ${pax.total.total.toLocaleString()} pax
            ${genderNote(pax.total.m, pax.total.f, pax.total.o)}</span>
        </div>`;

        const now = new Date().toLocaleString('en-PH', { dateStyle: 'long', timeStyle: 'short' });
        html += `
        <hr class="mt-4">
        <p class="text-muted text-end mb-0" style="font-size:.72rem;">
            <i class="bi bi-clock me-1"></i>Report generated: ${now}
        </p>`;

        return html;
    }

    /* ── ANALYTICS FETCH ───────────────────────────────────────── */
    function loadAnalytics() {
        const $loading = $('#analyticsLoading');
        const $error   = $('#analyticsError');
        const $content = $('#analyticsContent');

        $loading.removeClass('d-none');
        $error.addClass('d-none');
        $content.addClass('d-none').empty();

        $.ajax({
            url:      'api/get_training_analytics.php',
            type:     'POST',
            dataType: 'json',
            data:     { training_id: CONFIG.trainingId },
            headers:  { 'X-CSRF-Token': window.csrfToken ?? '' }
        })
        .done(function (res) {
            $loading.addClass('d-none');
            if (!res.success) {
                $error.removeClass('d-none');
                $('#analyticsErrorMsg').text(res.message || 'Failed to generate report.');
                return;
            }
            try {
                $content.html(renderReport(res.data)).removeClass('d-none');
            } catch (e) {
                $error.removeClass('d-none');
                $('#analyticsErrorMsg').text('Render error: ' + e.message);
                console.error('[Analytics]', e);
            }
        })
        .fail(function (xhr) {
            $loading.addClass('d-none');
            $error.removeClass('d-none');
            $('#analyticsErrorMsg').text('Network error (HTTP ' + xhr.status + '). Please try again.');
        });
    }

    /* ── DRILLDOWN ─────────────────────────────────────────────── */
    function renderDrilldownTable(participants, count, label) {
        drilldownRawData      = participants;
        drilldownCurrentLabel = label;

        if ($.fn.DataTable.isDataTable('#drilldownTable')) {
            $('#drilldownTable').DataTable().destroy();
        }
        $('#drilldownTable tbody').empty();

        $('#drilldownSubtitle').text(count > 0
            ? `${count.toLocaleString()} participant(s) found`
            : 'No participants found');
        $('#drilldownFooterCount').text(`${count.toLocaleString()} participant(s)`);
        $('#drilldownExportBtn').toggleClass('d-none', count === 0);

        if (count === 0) {
            $('#drilldownTable tbody').html(
                '<tr><td colspan="10" class="text-center text-muted py-4">' +
                '<i class="bi bi-inbox me-2"></i>No participants found.</td></tr>'
            );
            $('#drilldownContent').removeClass('d-none');
            return;
        }

        const rows = participants.map((p, i) => {
            const fo = locationLabels[p.location] || escHtml(p.location) || '—';
            return `<tr>
                <td class="text-center align-middle">${i + 1}</td>
                <td class="align-middle">${escHtml(p.full_name) || '—'}</td>
                <td class="text-center align-middle">${escHtml(p.gender) || '—'}</td>
                <td class="align-middle">${escHtml(p.position) || '—'}</td>
                <td class="text-center align-middle">${escHtml(p.salary_grade) || '—'}</td>
                <td class="align-middle">${escHtml(p.position_level) || '—'}</td>
                <td class="align-middle">${escHtml(p.agency) || '—'}</td>
                <td class="align-middle">${escHtml(p.sector) || '—'}</td>
                <td class="align-middle">${fo}</td>
                <td class="text-center align-middle">${getStatusBadge(p.payment_status, p.registration_status)}</td>
            </tr>`;
        }).join('');

        $('#drilldownTable tbody').html(rows);

        $('#drilldownTable').DataTable({
            pageLength: 25,
            order:      [[1, 'asc']],
            autoWidth:  false,
            columnDefs: [{ orderable: false, targets: [0, 9] }],
            dom: "<'row mb-2'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
            language: {
                search:            '<i class="bi bi-search"></i>',
                searchPlaceholder: 'Search participants…',
                lengthMenu:        'Show _MENU_',
                emptyTable:        'No participants found.',
                paginate: {
                    next:     '<i class="bi bi-chevron-right"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                },
            },
        });

        $('#drilldownContent').removeClass('d-none');
    }

    function fetchDrilldownData(filterType, filterParams, label) {
        const $loading = $('#drilldownLoading');
        const $error   = $('#drilldownError');
        const $content = $('#drilldownContent');

        $loading.removeClass('d-none');
        $error.addClass('d-none');
        $content.addClass('d-none');

        const postData = Object.assign(
            { training_id: CONFIG.trainingId, filter_type: filterType, label: label },
            filterParams
        );

        $.ajax({
            url:      'api/get_count_participants.php',
            type:     'POST',
            dataType: 'json',
            data:     postData,
            headers:  { 'X-CSRF-Token': window.csrfToken ?? '' },
        })
        .done(function (res) {
            $loading.addClass('d-none');
            if (!res.success) {
                $error.removeClass('d-none');
                $('#drilldownErrorMsg').text(res.message || 'Failed to load participants.');
                return;
            }
            renderDrilldownTable(res.participants, res.count, res.label);
        })
        .fail(function (xhr) {
            $loading.addClass('d-none');
            $error.removeClass('d-none');
            $('#drilldownErrorMsg').text('Network error (HTTP ' + xhr.status + '). Please try again.');
        });
    }

    function openParticipantDrilldown(filterType, filterParams, label) {
        const iconMap = {
            'graduate_pax':      'bi-people-fill',
            'graduate_position': 'bi-diagram-3-fill',
            'graduate_sector':   'bi-building-fill',
            'collection':        ({paid:'bi-check-circle-fill', pending:'bi-hourglass-split',
                                   'promissory note':'bi-journal-text', free:'bi-gift',
                                   cancelled:'bi-x-circle-fill', 'not qualified':'bi-slash-circle'}
                                  [filterParams.status_filter] || 'bi-cash-stack'),
        };

        $('#drilldownTitle').text(label);
        $('#drilldownSubtitle').text('Fetching participants…');
        $('#drilldownAvatar').html(`<i class="bi ${iconMap[filterType] || 'bi-people-fill'}"></i>`);
        $('#drilldownFooterCount').text('—');

        const analyticsOpen = $('#analyticsModal').hasClass('show');

        function showDrilldown() {
            $('#drilldownModal').modal('show');
            fetchDrilldownData(filterType, filterParams, label);
            if (analyticsOpen) {
                $('#drilldownModal').one('hidden.bs.modal', function () {
                    $('#analyticsModal').modal('show');
                });
            }
        }

        if (analyticsOpen) {
            suppressAnalyticsClear = true;
            bootstrap.Modal.getInstance(document.getElementById('analyticsModal')).hide();
            $('#analyticsModal').one('hidden.bs.modal', showDrilldown);
        } else {
            showDrilldown();
        }
    }

    /* ── TRIGGERS ──────────────────────────────────────────────── */
    $(document).ready(function () {

        /* Open analytics modal */
        $('#analyticsBtn').on('click', function () {
            $('#analyticsModal').modal('show');
            loadAnalytics();
        });

        /* Clear analytics content when closed (unless suppressed by drilldown transition) */
        $('#analyticsModal').on('hidden.bs.modal', function () {
            if (suppressAnalyticsClear) {
                suppressAnalyticsClear = false;
                return;
            }
            $('#analyticsContent').addClass('d-none').empty();
            $('#analyticsLoading').removeClass('d-none');
            $('#analyticsError').addClass('d-none');
        });

        /* Reset drilldown modal state when closed */
        $('#drilldownModal').on('hidden.bs.modal', function () {
            if ($.fn.DataTable.isDataTable('#drilldownTable')) {
                $('#drilldownTable').DataTable().destroy();
            }
            $('#drilldownTable tbody').empty();
            $('#drilldownContent').addClass('d-none');
            $('#drilldownLoading').removeClass('d-none');
            $('#drilldownError').addClass('d-none');
            $('#drilldownExportBtn').addClass('d-none');
            drilldownRawData      = [];
            drilldownCurrentLabel = '';
        });

        /* Click on a .drill-num cell (Sections B / C / D) */
        $(document).on('click', '#analyticsContent .drill-num', function () {
            const filterType   = $(this).data('filter-type');
            const filterParams = $(this).data('filter-params');  /* jQuery auto-parses JSON */
            const label        = $(this).data('filter-label');
            if (!filterType) return;
            openParticipantDrilldown(filterType, filterParams, label);
        });

        /* Click on a collection card (Section E) */
        $(document).on('click', '#analyticsContent .drilldown-stat-card', function () {
            const filterType   = $(this).data('filter-type');
            const filterParams = $(this).data('filter-params');
            const label        = $(this).data('filter-label');
            if (!filterType) return;
            openParticipantDrilldown(filterType, filterParams, label);
        });
    });

    /* ── DRILLDOWN CSV EXPORT ──────────────────────────────────── */
    window.otrsExportDrilldown = function () {
        if (!drilldownRawData.length) return;

        const headers = [
            'Name', 'Gender', 'Position', 'Salary Grade',
            'Position Level', 'Agency', 'Sector', 'Field Office',
            'Payment Status', 'Registration Status',
        ];

        const csvRows = [headers, ...drilldownRawData.map(p => [
            p.full_name       || '',
            p.gender          || '',
            p.position        || '',
            p.salary_grade    || '',
            p.position_level  || '',
            p.agency          || '',
            p.sector          || '',
            locationLabels[p.location] || p.location || '',
            p.payment_status      || '',
            p.registration_status || '',
        ])];

        const csv = csvRows
            .map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
            .join('\r\n');

        const safeName = drilldownCurrentLabel.replace(/[^a-z0-9\-_ ]/gi, '').trim().replace(/\s+/g, '_');
        const filename  = `${safeName || 'participants'}_${new Date().toISOString().slice(0, 10)}.csv`;

        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    /* ── PRINT ─────────────────────────────────────────────────── */
    window.otrsAnalyticsPrint = function () {
        const content = document.getElementById('analyticsContent');
        if (!content || content.classList.contains('d-none')) {
            alert('Report is still loading. Please wait.');
            return;
        }
        const frame = document.createElement('iframe');
        frame.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;border:none;';
        document.body.appendChild(frame);
        const doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write(`<!DOCTYPE html><html lang="en"><head>
            <meta charset="UTF-8">
            <title>Training Analytics Report</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
            <style>
                body { font-size: 11pt; padding: 1rem; }
                @page { margin: 1.5cm; size: A4 portrait; }
                .table, .card { page-break-inside: avoid; }
                .badge { border: 1px solid #dee2e6; }
                .drill-num { color: inherit !important; text-decoration: none !important; cursor: default !important; }
                .drilldown-stat-card { cursor: default !important; }
            </style>
        </head><body>${content.innerHTML}</body></html>`);
        doc.close();
        frame.contentWindow.focus();
        frame.contentWindow.print();
        setTimeout(() => document.body.removeChild(frame), 2000);
    };

}());
