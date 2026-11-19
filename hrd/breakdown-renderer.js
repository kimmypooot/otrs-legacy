/**
 * breakdown-renderer.js
 * Reusable participant breakdown table renderer.
 * Styles live in assets/css/style.css — no injection done here.
 *
 * Gender columns rendered (always): Male | Female | LGBTQIA+ | Not Indicated
 */

'use strict';

class BreakdownRenderer {
    /**
     * @param {string|HTMLElement} container
     * @param {object} options
     * @param {string}  options.apiUrl    URL of fetch_breakdown.php  (default: 'fetch_breakdown.php')
     * @param {boolean} options.showPct   Show % column               (default: true)
     * @param {boolean} options.exportable Show export buttons         (default: true)
     */
    constructor(container, options = {}) {
        this.container = typeof container === 'string'
            ? document.querySelector(container)
            : container;

        if (!this.container) throw new Error('BreakdownRenderer: container not found.');

        this.opts = Object.assign({
            apiUrl:     'fetch_breakdown.php',
            showPct:    true,
            exportable: true,
        }, options);

        this._lastData = null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    async load(params) {
        this._showSpinner();
        try {
            const res  = await fetch(this.opts.apiUrl, { method: 'POST', body: new URLSearchParams(params) });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            if (json.error) throw new Error(json.error);
            this.render(json);
        } catch (err) {
            this._showError(err.message);
        }
    }

    render(data) {
        this._lastData = data;
        this.container.innerHTML = '';

        const { columns, rows, totals, meta } = data;
        const plKeys  = columns.position_levels;
        const secKeys = columns.sectors;
        const grand   = meta.grand_total;

        const wrap = document.createElement('div');
        wrap.className = 'bdr-wrap';

        if (this.opts.exportable) wrap.appendChild(this._buildToolbar(meta));

        const scroll = document.createElement('div');
        scroll.className = 'bdr-scroll';

        const table = document.createElement('table');
        table.className = 'bdr-table';
        table.appendChild(this._buildHeader(plKeys, secKeys));

        const tbody = document.createElement('tbody');
        rows.forEach(row => tbody.appendChild(this._buildRow(row, plKeys, secKeys, grand)));
        table.appendChild(tbody);

        table.appendChild(this._buildFooter(totals, plKeys, secKeys, meta));
        scroll.appendChild(table);
        wrap.appendChild(scroll);
        this.container.appendChild(wrap);
    }

    // ── Private: builders ─────────────────────────────────────────────────────

    _buildToolbar(meta) {
        const bar = document.createElement('div');
        bar.className = 'bdr-toolbar';

        const info = document.createElement('span');
        info.className = 'bdr-meta';
        info.innerHTML = `<i class="bi bi-people-fill me-1"></i>${meta.grand_total.toLocaleString()} participant${meta.grand_total !== 1 ? 's' : ''}`;

        const group = document.createElement('div');
        group.className = 'bdr-btn-group';

        const mkBtn = (cls, icon, label, fn) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = `bdr-btn ${cls}`;
            b.innerHTML = `<i class="bi ${icon}"></i> ${label}`;
            b.addEventListener('click', fn);
            return b;
        };

        group.appendChild(mkBtn('bdr-btn-csv',   'bi-filetype-csv',       'Export CSV',   () => this.exportCSV()));
        group.appendChild(mkBtn('bdr-btn-excel', 'bi-file-earmark-excel', 'Export Excel', () => this.exportExcel()));

        bar.appendChild(info);
        bar.appendChild(group);
        return bar;
    }

    _buildHeader(plKeys, secKeys) {
        const thead = document.createElement('thead');

        const mkTh = (text, cls, opts = {}) => {
            const th = document.createElement('th');
            th.innerHTML = text;
            th.className = cls;
            if (opts.rowspan)  th.rowSpan  = opts.rowspan;
            if (opts.colspan)  th.colSpan  = opts.colspan;
            if (opts.minWidth) th.style.minWidth = opts.minWidth;
            return th;
        };

        // Always 4 gender sub-columns: Male | Female | LGBTQIA+ | Not Indicated
        const r1 = document.createElement('tr');
        r1.appendChild(mkTh('Generation',     'bdr-th-left',     { rowspan: 2, minWidth: '120px' }));
        r1.appendChild(mkTh('Age Range',      '',                { rowspan: 2 }));
        r1.appendChild(mkTh('Gender',         'bdr-th-gender',   { colspan: 4 }));
        r1.appendChild(mkTh('Position Level', 'bdr-th-position', { colspan: plKeys.length  || 1 }));
        r1.appendChild(mkTh('Sector',         'bdr-th-sector',   { colspan: secKeys.length || 1 }));
        r1.appendChild(mkTh('Total',          'bdr-th-total',    { rowspan: 2 }));
        if (this.opts.showPct) r1.appendChild(mkTh('%', 'bdr-th-total', { rowspan: 2 }));

        const r2 = document.createElement('tr');
        ['Male', 'Female', 'LGBTQIA+', 'Not Indicated'].forEach(g => r2.appendChild(mkTh(g, 'bdr-th-gender')));
        (plKeys.length  ? plKeys  : ['—']).forEach(pl  => r2.appendChild(mkTh(pl,  'bdr-th-position', { minWidth: '90px' })));
        (secKeys.length ? secKeys : ['—']).forEach(sec => r2.appendChild(mkTh(sec, 'bdr-th-sector',   { minWidth: '90px' })));

        thead.appendChild(r1);
        thead.appendChild(r2);
        return thead;
    }

    _buildRow(row, plKeys, secKeys, grand) {
        const tr   = document.createElement('tr');
        const mkTd = (text, cls = '') => {
            const td = document.createElement('td');
            td.textContent = text;
            td.className   = cls;
            return td;
        };

        tr.appendChild(mkTd(row.generation,   'bdr-td-left bdr-td-gen'));
        tr.appendChild(mkTd(row.age_range,    'bdr-td-age'));
        tr.appendChild(mkTd(row.male));
        tr.appendChild(mkTd(row.female));
        tr.appendChild(mkTd(row.lgbtqia));
        tr.appendChild(mkTd(row.not_indicated));

        (plKeys.length  ? plKeys  : []).forEach(pl  => tr.appendChild(mkTd(row.position_levels[pl]  ?? 0)));
        if (!plKeys.length)  tr.appendChild(mkTd(0));
        (secKeys.length ? secKeys : []).forEach(sec => tr.appendChild(mkTd(row.sectors[sec] ?? 0)));
        if (!secKeys.length) tr.appendChild(mkTd(0));

        const pct     = grand > 0 ? row.total / grand : 0;
        const totalTd = document.createElement('td');
        totalTd.className = 'bdr-td-total';
        totalTd.innerHTML = `${row.total}<span class="bdr-bar-wrap"><span class="bdr-bar-fill" style="width:${Math.round(pct * 48)}px"></span></span>`;
        tr.appendChild(totalTd);

        if (this.opts.showPct) tr.appendChild(mkTd(`${row.pct}%`, 'bdr-td-pct'));
        return tr;
    }

    _buildFooter(totals, plKeys, secKeys, meta) {
        const tfoot = document.createElement('tfoot');
        const tr    = document.createElement('tr');
        const mkTf  = (text, cls = '') => {
            const td = document.createElement('td');
            td.textContent = text;
            td.className   = cls;
            return td;
        };

        tr.appendChild(mkTf('Grand Total', 'bdr-td-left'));
        tr.appendChild(mkTf(''));
        tr.appendChild(mkTf(totals.male));
        tr.appendChild(mkTf(totals.female));
        tr.appendChild(mkTf(totals.lgbtqia       ?? 0));
        tr.appendChild(mkTf(totals.not_indicated  ?? 0));

        (plKeys.length  ? plKeys  : []).forEach(pl  => tr.appendChild(mkTf(totals.position_levels[pl]  ?? 0)));
        if (!plKeys.length)  tr.appendChild(mkTf(0));
        (secKeys.length ? secKeys : []).forEach(sec => tr.appendChild(mkTf(totals.sectors[sec] ?? 0)));
        if (!secKeys.length) tr.appendChild(mkTf(0));

        tr.appendChild(mkTf(meta.grand_total, 'bdr-td-grand'));
        if (this.opts.showPct) tr.appendChild(mkTf('100%'));

        tfoot.appendChild(tr);
        return tfoot;
    }

    // ── Spinner / error ───────────────────────────────────────────────────────

    _showSpinner() {
        this.container.innerHTML = `
            <div class="bdr-spinner">
                <div class="bdr-spinner-ring"></div>
                Loading breakdown data…
            </div>`;
    }

    _showError(msg) {
        this.container.innerHTML = `
            <div class="bdr-error">
                <strong>Error:</strong> ${msg}
            </div>`;
    }

    // ── Export ────────────────────────────────────────────────────────────────

    _toMatrix() {
        if (!this._lastData) return [];
        const { columns, rows, totals, meta } = this._lastData;
        const { position_levels: plKeys, sectors: secKeys } = columns;

        const header = [
            'Generation', 'Age Range',
            'Male', 'Female', 'LGBTQIA+', 'Not Indicated',
            ...plKeys, ...secKeys,
            'Total', '%',
        ];
        const matrix = [header];

        rows.forEach(row => matrix.push([
            row.generation, row.age_range,
            row.male, row.female, row.lgbtqia, row.not_indicated,
            ...plKeys.map(pl  => row.position_levels[pl]  ?? 0),
            ...secKeys.map(sec => row.sectors[sec]         ?? 0),
            row.total, `${row.pct}%`,
        ]));

        matrix.push([
            'Grand Total', '',
            totals.male, totals.female, totals.lgbtqia ?? 0, totals.not_indicated ?? 0,
            ...plKeys.map(pl  => totals.position_levels[pl]  ?? 0),
            ...secKeys.map(sec => totals.sectors[sec]         ?? 0),
            meta.grand_total, '100%',
        ]);

        return matrix;
    }

    exportCSV() {
        const csv = this._toMatrix()
            .map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(','))
            .join('\n');
        this._download('breakdown.csv', 'text/csv', csv);
    }

    exportExcel() {
        const rows = this._toMatrix().map(row => {
            const cells = row.map(c => typeof c === 'number'
                ? `<Cell><Data ss:Type="Number">${c}</Data></Cell>`
                : `<Cell><Data ss:Type="String">${String(c).replace(/&/g,'&amp;').replace(/</g,'&lt;')}</Data></Cell>`
            ).join('');
            return `<Row>${cells}</Row>`;
        }).join('');

        const xml = `<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
            xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
            <Worksheet ss:Name="Breakdown"><Table>${rows}</Table></Worksheet></Workbook>`;

        this._download('breakdown.xls', 'application/vnd.ms-excel', xml);
    }

    _download(filename, mime, content) {
        const url = URL.createObjectURL(new Blob([content], { type: mime }));
        Object.assign(document.createElement('a'), { href: url, download: filename }).click();
        URL.revokeObjectURL(url);
    }
}

if (typeof module !== 'undefined') module.exports = BreakdownRenderer;