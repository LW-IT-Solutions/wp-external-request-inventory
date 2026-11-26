/* External Request Inventory: scan of this site's public pages and the host report. All values are inserted as text. */
(function () {
    'use strict';
    const scanButton = document.getElementById('erinv-scan');
    if (!scanButton || typeof ERINV === 'undefined') return;
    const T = ERINV.text;
    const own = new Set(ERINV.own);
    const status = document.getElementById('erinv-status');
    const body = document.getElementById('erinv-results');
    const summary = document.getElementById('erinv-summary');
    const recording = document.getElementById('erinv-recording');
    const toggle = document.getElementById('erinv-toggle');
    const clear = document.getElementById('erinv-clear');
    const csvButton = document.getElementById('erinv-csv');
    const extra = document.getElementById('erinv-extra');
    const overview = document.getElementById('erinv-overview');
    const toolbar = document.getElementById('erinv-toolbar');
    const filters = document.getElementById('erinv-filters');
    const search = document.getElementById('erinv-search');
    const table = document.getElementById('erinv-table');
    const shown = document.getElementById('erinv-shown');
    const empty = document.getElementById('erinv-empty');
    const spinner = document.getElementById('erinv-spinner');
    const progressWrap = document.getElementById('erinv-progress-wrap');
    const progress = document.getElementById('erinv-progress');
    const FONT = /\.(woff2?|ttf|otf|eot)(\?|#|$)/i;
    // JSON blocks hold configuration that scripts load from (for example the emoji CDN); structured data (ld+json) is never loaded.
    const SCRIPT_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'application/json', 'importmap'];
    let report = null, busy = false, filter = 'all';

    function fmt(text) {
        const args = Array.prototype.slice.call(arguments, 1);
        let next = 0;
        return text.replace(/%(?:(\d)\$)?[sd]/g, (_, n) => String(args[n ? n - 1 : next++]));
    }

    function el(tag, text, className) {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    function icon(name) {
        const node = el('span', undefined, 'dashicons dashicons-' + name);
        node.setAttribute('aria-hidden', 'true');
        return node;
    }

    async function post(data) {
        const response = await fetch(ERINV.url, {
            method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams(Object.assign({nonce: ERINV.nonce}, data))
        });
        const json = await response.json().catch(() => null);
        if (!json) throw new Error(T.error);
        if (!response.ok || !json.success) throw new Error((json.data && json.data.message) || T.error);
        return json.data;
    }

    function path(url) {
        try { return decodeURIComponent(url.pathname); } catch (_) { return url.pathname; }
    }

    function cssUrls(text) {
        const found = [];
        const patterns = [/url\(\s*(['"]?)([^'")]+)\1\s*\)/gi, /@import\s+(['"])([^'"]+)\1/gi];
        patterns.forEach(re => { let m; while ((m = re.exec(text))) found.push(m[2]); });
        return found;
    }

    /* Collect external hosts of one parsed page. Same-site stylesheets are queued for a second pass. */
    function collect(doc, base, page, add, ownCss) {
        const take = (value, type) => {
            if (!value) return;
            let url;
            try { url = new URL(value.trim(), base); } catch (_) { return; }
            if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
            const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '');
            if (own.has(host)) {
                if (type === 'style') ownCss.add(url.href);
                return;
            }
            add(host, type === 'inline-style' && FONT.test(url.pathname) ? 'font' : type, page);
        };
        const srcset = value => (value || '').split(',').map(part => part.trim().split(/\s+/)[0]).filter(Boolean);
        doc.querySelectorAll('script[src]').forEach(node => take(node.getAttribute('src'), 'script'));
        doc.querySelectorAll('link[href]').forEach(node => {
            const rel = (node.getAttribute('rel') || '').toLowerCase().split(/\s+/);
            const as = (node.getAttribute('as') || '').toLowerCase();
            let type = null;
            if (rel.includes('stylesheet')) type = 'style';
            else if (rel.includes('preconnect') || rel.includes('dns-prefetch')) type = 'preconnect';
            else if (rel.includes('modulepreload')) type = 'script';
            else if (rel.includes('preload') || rel.includes('prefetch')) type = {font: 'font', script: 'script', style: 'style', image: 'image'}[as] || 'prefetch';
            else if (rel.includes('icon') || rel.includes('apple-touch-icon') || rel.includes('mask-icon')) type = 'image';
            if (type) take(node.getAttribute('href'), type);
        });
        doc.querySelectorAll('img, input[type="image"]').forEach(node => {
            take(node.getAttribute('src'), 'image');
            srcset(node.getAttribute('srcset')).forEach(u => take(u, 'image'));
        });
        doc.querySelectorAll('source').forEach(node => {
            const media = node.parentElement && /^(video|audio)$/i.test(node.parentElement.tagName);
            take(node.getAttribute('src'), media ? 'media' : 'image');
            srcset(node.getAttribute('srcset')).forEach(u => take(u, 'image'));
        });
        doc.querySelectorAll('video, audio, track').forEach(node => {
            take(node.getAttribute('src'), 'media');
            take(node.getAttribute('poster'), 'image');
        });
        doc.querySelectorAll('iframe[src]').forEach(node => take(node.getAttribute('src'), 'iframe'));
        doc.querySelectorAll('embed[src]').forEach(node => take(node.getAttribute('src'), 'embed'));
        doc.querySelectorAll('object[data]').forEach(node => take(node.getAttribute('data'), 'embed'));
        doc.querySelectorAll('form[action]').forEach(node => take(node.getAttribute('action'), 'form'));
        doc.querySelectorAll('[style]').forEach(node => cssUrls(node.getAttribute('style')).forEach(u => take(u, 'inline-style')));
        doc.querySelectorAll('style').forEach(node => cssUrls(node.textContent).forEach(u => take(u, 'inline-style')));
        doc.querySelectorAll('script:not([src])').forEach(node => {
            if (!SCRIPT_TYPES.includes((node.getAttribute('type') || '').toLowerCase())) return;
            const text = node.textContent.replace(/\\\//g, '/');
            const re = /["'`(=\s](?:https?:)?\/\/([a-z0-9-]+(?:\.[a-z0-9-]+)+)/gi;
            let m;
            while ((m = re.exec(text))) {
                const host = m[1].toLowerCase();
                if (!own.has(host) && /[a-z]/.test(host.split('.').pop())) add(host, 'inline-script', page);
            }
        });
    }

    function pagesToScan() {
        const list = ERINV.pages.slice(), notes = [], further = [];
        extra.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean).slice(0, 20).forEach(line => {
            let url;
            try { url = new URL(line, location.origin); } catch (_) { notes.push(fmt(T.foreign, line)); return; }
            if ((url.protocol === 'http:' || url.protocol === 'https:') && own.has(url.hostname.toLowerCase())) {
                list.push(url.href);
                further.push(path(url));
            } else notes.push(fmt(T.foreign, line));
        });
        return {list: Array.from(new Set(list)), notes, further};
    }

    /* Busy state of the scan: spinner, progress bar, disabled buttons. */
    function setBusy(on, total) {
        busy = on;
        scanButton.disabled = on;
        scanButton.setAttribute('aria-busy', on ? 'true' : 'false');
        const emptyScan = document.getElementById('erinv-empty-scan');
        if (emptyScan) emptyScan.disabled = on;
        spinner.classList.toggle('is-active', on);
        progressWrap.hidden = !on;
        if (on) {
            progress.max = Math.max(1, total);
            progress.value = 0;
        }
    }

    /* Result line of a scan, with the pages that could not be scanned as an inline warning. */
    function scanResult(text, notes) {
        status.replaceChildren(el('p', text, 'erinv-status-line'));
        if (notes.length) {
            const box = el('div', undefined, 'notice notice-warning inline erinv-notes');
            box.append(el('p', T.notes));
            const list = el('ul');
            notes.forEach(note => list.append(el('li', note)));
            box.append(list);
            status.append(box);
        }
    }

    async function scan() {
        if (busy) return;
        const hosts = new Map(), ownCss = new Set(), pages = [];
        const {list, notes, further} = pagesToScan();
        setBusy(true, list.length);
        let failed = 0;
        const add = (host, type, page) => {
            if (!hosts.has(host)) hosts.set(host, {types: new Set(), pages: new Set()});
            const entry = hosts.get(host);
            entry.types.add(type);
            if (page && entry.pages.size < 5) entry.pages.add(page);
        };
        try {
            for (let i = 0; i < list.length; i++) {
                status.replaceChildren(el('p', fmt(T.scanning, i + 1, list.length, list[i]), 'erinv-status-line'));
                progress.value = i;
                try {
                    // Redirects are not followed, so the browser never requests an address on another host.
                    const response = await fetch(list[i], {credentials: 'omit', cache: 'no-store', redirect: 'manual'});
                    if (response.type === 'opaqueredirect') {
                        failed++;
                        notes.push(fmt(T.redirect, list[i]));
                        continue;
                    }
                    if (!response.ok || !/html/i.test(response.headers.get('content-type') || '')) throw new Error('page');
                    const base = new URL(response.url);
                    const html = (await response.text()).slice(0, 5000000);
                    const page = path(base);
                    pages.push(page);
                    collect(new DOMParser().parseFromString(html, 'text/html'), base, page, add, ownCss);
                } catch (_) {
                    failed++;
                    notes.push(fmt(T.failed, list[i]));
                }
            }
            progress.value = list.length;
            for (const href of Array.from(ownCss).slice(0, 20)) {
                try {
                    const response = await fetch(href, {credentials: 'omit', redirect: 'manual'});
                    if (!response.ok) continue; // Also skips stylesheets that redirect.
                    const base = new URL(response.url);
                    cssUrls((await response.text()).slice(0, 2000000)).forEach(value => {
                        let url;
                        try { url = new URL(value, base); } catch (_) { return; }
                        const host = url.hostname.toLowerCase();
                        if ((url.protocol === 'http:' || url.protocol === 'https:') && !own.has(host)) add(host, FONT.test(url.pathname) ? 'font' : 'css', path(base));
                    });
                } catch (_) { /* A stylesheet that cannot be read is skipped. */ }
            }
            const payload = {};
            hosts.forEach((value, host) => { payload[host] = {types: Array.from(value.types), pages: Array.from(value.pages)}; });
            report = await post({action: 'erinv_save', hosts: JSON.stringify(payload), pages: JSON.stringify(pages), further: JSON.stringify(further)});
            setBusy(false);
            render();
            scanResult(fmt(T.scanned, pages.length, failed, report.scanned), notes);
        } catch (error) {
            setBusy(false);
            scanResult(error.message, []);
        }
    }

    /* Small chips for resource types and contexts. */
    function chips(values, labels, className) {
        const wrap = el('span', undefined, 'erinv-chips');
        values.forEach(v => wrap.append(el('span', labels[v] || v, 'erinv-chip ' + (className || ''))));
        return wrap;
    }

    function where(row, maxCount) {
        const cell = el('td');
        cell.dataset.label = T.cols[1];
        if (row.browser) {
            const line = el('div', undefined, 'erinv-where');
            line.append(el('span', T.browser, 'erinv-src erinv-src-browser'), chips(row.browser.types, T.types));
            cell.append(line);
            if (row.browser.pages.length) {
                const pages = el('div', undefined, 'erinv-muted erinv-pages');
                pages.append(el('span', T.pagesLbl + ' ', 'screen-reader-text'), row.browser.pages.join('  '));
                cell.append(pages);
            }
        }
        if (row.server) {
            const line = el('div', undefined, 'erinv-where');
            line.append(el('span', T.server, 'erinv-src erinv-src-server'), el('span', row.server.count === 1 ? fmt(T.once, row.server.last) : fmt(T.times, row.server.count, row.server.last), 'erinv-count-text'));
            cell.append(line);
            // Requests of this host compared with the busiest host: a bar for orientation, the number is in the text.
            const bar = el('span', undefined, 'erinv-bar');
            bar.setAttribute('aria-hidden', 'true');
            const fill = el('span', undefined, 'erinv-bar-fill');
            fill.style.width = Math.max(3, Math.round(row.server.count / maxCount * 100)) + '%';
            bar.append(fill);
            cell.append(bar);
            const by = el('div', undefined, 'erinv-muted erinv-by');
            by.append(row.server.sources.join(', ') + ' ', chips(row.server.contexts, T.contexts, 'erinv-chip-context'));
            cell.append(by);
        }
        return cell;
    }

    function policyBadge(row) {
        const td = el('td');
        td.dataset.label = T.cols[3];
        let badge;
        if (row.policy === null) {
            badge = el('span', undefined, 'erinv-pill erinv-pill-na');
            badge.append(icon('minus'), el('span', T.na));
        } else if (row.policy) {
            badge = el('span', undefined, 'erinv-pill erinv-pill-yes');
            badge.append(icon('yes-alt'), el('span', T.yes));
        } else {
            badge = el('span', undefined, 'erinv-pill erinv-pill-no');
            badge.append(icon('warning'), el('span', T.no));
        }
        td.append(badge);
        return td;
    }

    /* Coverage ring: share of the hosts the privacy policy names, drawn with a CSS conic gradient. */
    function ring(part, total) {
        const share = total ? part / total : 0;
        const node = el('span', undefined, 'erinv-ring' + (share < 1 ? ' erinv-ring-gap' : ''));
        node.setAttribute('aria-hidden', 'true');
        node.style.setProperty('--erinv-share', (share * 100).toFixed(1) + '%');
        return node;
    }

    function tile(number, label, kind) {
        const box = el('div', undefined, 'erinv-tile' + (kind ? ' erinv-tile-' + kind : ''));
        box.append(el('span', String(number), 'erinv-tile-n'), el('span', label, 'erinv-tile-l'));
        return box;
    }

    function renderOverview(rows) {
        overview.replaceChildren();
        if (!rows.length) {
            overview.hidden = true;
            return;
        }
        overview.hidden = false;
        const notNamed = rows.filter(r => r.policy === false).length;
        const named = rows.filter(r => r.policy === true).length;
        const checked = rows.filter(r => r.policy !== null).length;
        const tiles = el('div', undefined, 'erinv-tiles');
        tiles.append(
            tile(rows.length, T.tileHosts, ''),
            tile(notNamed, T.tileNo, notNamed ? 'bad' : 'ok'),
            tile(rows.filter(r => r.new).length, T.tileNew, 'new')
        );
        const requests = rows.reduce((sum, r) => sum + (r.server ? r.server.count : 0), 0);
        tiles.append(tile(requests, fmt(T.tileReq, report.server_since), 'server'));

        // Coverage of the privacy policy, as a ring with the percentage in words next to it.
        const cover = el('div', undefined, 'erinv-tile erinv-tile-cover');
        if (report.policy_url) {
            const pct = checked ? Math.round(named / checked * 100) : 0;
            cover.append(ring(named, checked));
            const text = el('span', undefined, 'erinv-cover-text');
            text.append(el('span', pct + ' %', 'erinv-tile-n'), el('span', T.tileCover + ' (' + named + '/' + checked + ')', 'erinv-tile-l'));
            const link = el('a', T.policyOpen, 'erinv-cover-link');
            link.href = report.policy_url;
            text.append(link);
            cover.append(text);
        } else {
            const text = el('span', undefined, 'erinv-cover-text');
            text.append(el('span', '–', 'erinv-tile-n'), el('span', T.nopolicy, 'erinv-tile-l'));
            const link = el('a', T.policySet, 'erinv-cover-link');
            link.href = ERINV.privacy;
            text.append(link);
            cover.append(text);
        }
        tiles.append(cover);
        overview.append(tiles);

        // Where the hosts were found: one stacked bar, the counts are written in the legend.
        const counts = [
            ['browser', T.onlyB, rows.filter(r => r.browser && !r.server).length],
            ['both', T.both, rows.filter(r => r.browser && r.server).length],
            ['server', T.onlyS, rows.filter(r => !r.browser && r.server).length]
        ];
        const split = el('div', undefined, 'erinv-split');
        split.append(el('h3', T.sources, 'erinv-split-title'));
        const bar = el('div', undefined, 'erinv-split-bar');
        bar.setAttribute('aria-hidden', 'true');
        const legend = el('ul', undefined, 'erinv-legend');
        counts.forEach(([key, label, n]) => {
            if (n) {
                const part = el('span', undefined, 'erinv-split-' + key);
                part.style.flexGrow = String(n);
                bar.append(part);
            }
            const item = el('li', undefined, 'erinv-legend-' + key);
            item.append(el('span', undefined, 'erinv-swatch'), el('span', label + ': ' + n));
            legend.append(item);
        });
        split.append(bar, legend);
        overview.append(split);
    }

    function matches(row, query) {
        if (filter === 'no' && row.policy !== false) return false;
        if (filter === 'new' && !row.new) return false;
        if (filter === 'browser' && !row.browser) return false;
        if (filter === 'server' && !row.server) return false;
        return !query || row.host.indexOf(query) !== -1 || (row.service || '').toLowerCase().indexOf(query) !== -1;
    }

    function renderFilters(rows) {
        const options = [
            ['all', T.all, rows.length],
            ['no', T.fNo, rows.filter(r => r.policy === false).length],
            ['new', T.fNew, rows.filter(r => r.new).length],
            ['browser', T.browser, rows.filter(r => r.browser).length],
            ['server', T.server, rows.filter(r => r.server).length]
        ];
        filters.replaceChildren();
        options.forEach(([key, label, n]) => {
            if (key !== 'all' && !n) {
                if (filter === key) filter = 'all';
                return;
            }
            const button = el('button', undefined, 'button erinv-filter');
            button.type = 'button';
            button.dataset.filter = key;
            button.setAttribute('aria-pressed', filter === key ? 'true' : 'false');
            button.append(el('span', label), el('span', String(n), 'erinv-filter-n'));
            button.addEventListener('click', () => {
                filter = key;
                filters.querySelectorAll('.erinv-filter').forEach(b => b.setAttribute('aria-pressed', b === button ? 'true' : 'false'));
                applyFilter();
            });
            filters.append(button);
        });
    }

    function applyFilter() {
        if (!report) return;
        const query = search.value.trim().toLowerCase();
        let count = 0;
        Array.prototype.forEach.call(body.rows, (tr, i) => {
            const show = matches(report.rows[i], query);
            tr.hidden = !show;
            if (show) count++;
        });
        const total = report.rows.length;
        shown.textContent = count ? (count < total ? fmt(T.shown, count, total) : '') : T.noMatch;
    }

    function render() {
        body.replaceChildren();
        const rows = report.rows;
        const maxCount = Math.max(1, ...rows.map(r => (r.server ? r.server.count : 0)));
        rows.forEach(row => {
            const tr = document.createElement('tr');
            if (row.policy === false) tr.className = 'erinv-row-no';
            const host = el('td');
            host.dataset.label = T.cols[0];
            const name = el('div', undefined, 'erinv-host');
            name.append(el('strong', row.host));
            if (row.new) name.append(' ', el('span', T.new, 'erinv-badge'));
            host.append(name);
            if (row.service) host.append(el('div', row.service, 'erinv-muted'));
            const first = el('td', row.first);
            first.dataset.label = T.cols[2];
            tr.append(host, where(row, maxCount), first, policyBadge(row));
            body.append(tr);
        });
        table.hidden = !rows.length;
        toolbar.hidden = !rows.length;
        empty.hidden = !!rows.length;
        renderOverview(rows);
        renderFilters(rows);
        applyFilter();
        summary.replaceChildren();
        if (!report.policy_url) {
            const box = el('div', undefined, 'notice notice-info inline');
            box.append(el('p', T.nopolicy));
            summary.append(box);
        }
        if (!busy) status.replaceChildren(el('p', report.scanned ? fmt(T.last, report.scanned, report.pages.length) : T.never, 'erinv-status-line'));
        const state = el('p', undefined, 'erinv-rec-line');
        const pill = el('span', undefined, 'erinv-rec ' + (report.recording ? 'erinv-rec-on' : 'erinv-rec-off'));
        pill.append(el('span', undefined, 'erinv-rec-dot'), el('span', report.recording ? T.recOn : T.recOff));
        state.append(pill, ' ', el('span', report.recording ? fmt(T.recordOn, report.server_since) : T.recordOff));
        recording.replaceChildren(state);
        if (report.dropped) {
            const box = el('div', undefined, 'notice notice-warning inline');
            box.append(el('p', fmt(T.dropped, report.dropped)));
            recording.append(box);
        }
        toggle.replaceChildren(icon(report.recording ? 'controls-pause' : 'controls-play'), el('span', report.recording ? T.pause : T.resume));
        csvButton.disabled = !rows.length;
    }

    async function action(task) {
        try {
            report = await post({action: 'erinv_action', task});
            render();
        } catch (error) {
            scanResult(error.message, []);
        }
    }

    function csv(value) {
        let text = String(value == null ? '' : value);
        // Protect spreadsheet consumers from formula interpretation.
        if (/^[\s\u0000-\u001f]*[=+@-]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    /* Confirmation in a modal dialog of the page. The native dialog keeps the focus inside, closes on Escape and
       returns the focus to the button that opened it; Enter activates the focused button. */
    function confirmDialog(title, text, okLabel, onConfirm) {
        const dialog = el('dialog', undefined, 'erinv-dialog');
        const heading = el('h2', title);
        heading.id = 'erinv-dialog-title';
        const message = el('p', text);
        message.id = 'erinv-dialog-text';
        dialog.setAttribute('aria-labelledby', heading.id);
        dialog.setAttribute('aria-describedby', message.id);
        dialog.setAttribute('aria-modal', 'true');
        const actions = el('p', undefined, 'erinv-dialog-actions');
        const cancel = el('button', T.cancel, 'button');
        cancel.type = 'button';
        const ok = el('button', okLabel, 'button button-primary');
        ok.type = 'button';
        actions.append(cancel, ok);
        dialog.append(heading, message, actions);
        cancel.addEventListener('click', () => dialog.close('cancel'));
        ok.addEventListener('click', () => dialog.close('ok'));
        dialog.addEventListener('close', () => {
            dialog.remove();
            if (dialog.returnValue === 'ok') onConfirm();
        });
        document.body.append(dialog);
        dialog.showModal();
        ok.focus();
    }

    scanButton.addEventListener('click', scan);
    document.getElementById('erinv-empty-scan').addEventListener('click', () => { scanButton.focus(); scan(); });
    search.addEventListener('input', applyFilter);
    toggle.addEventListener('click', () => action(report && report.recording ? 'pause' : 'resume'));
    clear.addEventListener('click', () => confirmDialog(T.clearTitle, T.confirmClr, T.clearOk, () => action('clear')));
    csvButton.addEventListener('click', () => {
        const data = [T.csv];
        report.rows.forEach(r => data.push([
            r.host, r.service,
            r.browser ? r.browser.types.join('|') : '', r.browser ? r.browser.pages.join('|') : '',
            r.server ? r.server.count : '', r.server ? r.server.last : '', r.server ? r.server.sources.join('|') : '', r.server ? r.server.contexts.join('|') : '',
            r.first, r.new ? 'yes' : '', r.policy === null ? '' : (r.policy ? 'named' : 'not named')
        ]));
        const blob = new Blob(['﻿' + data.map(row => row.map(csv).join(',')).join('\r\n')], {type: 'text/csv;charset=utf-8'});
        const url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = 'external-requests.csv';
        a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    status.replaceChildren(el('p', T.loading, 'erinv-status-line'));
    post({action: 'erinv_report'}).then(data => { report = data; render(); }).catch(error => { scanResult(error.message, []); });
}());
