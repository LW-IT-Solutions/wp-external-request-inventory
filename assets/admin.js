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
    const FONT = /\.(woff2?|ttf|otf|eot)(\?|#|$)/i;
    // JSON blocks hold configuration that scripts load from (for example the emoji CDN); structured data (ld+json) is never loaded.
    const SCRIPT_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'application/json', 'importmap'];
    let report = null, busy = false;

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
        const list = ERINV.pages.slice(), notes = [];
        extra.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean).slice(0, 20).forEach(line => {
            let url;
            try { url = new URL(line, location.origin); } catch (_) { notes.push(fmt(T.foreign, line)); return; }
            if ((url.protocol === 'http:' || url.protocol === 'https:') && own.has(url.hostname.toLowerCase())) list.push(url.href);
            else notes.push(fmt(T.foreign, line));
        });
        return {list: Array.from(new Set(list)), notes};
    }

    async function scan() {
        if (busy) return;
        busy = true; scanButton.disabled = true;
        const hosts = new Map(), ownCss = new Set(), pages = [];
        const {list, notes} = pagesToScan();
        let failed = 0;
        const add = (host, type, page) => {
            if (!hosts.has(host)) hosts.set(host, {types: new Set(), pages: new Set()});
            const entry = hosts.get(host);
            entry.types.add(type);
            if (page && entry.pages.size < 5) entry.pages.add(page);
        };
        try {
            for (let i = 0; i < list.length; i++) {
                status.textContent = fmt(T.scanning, i + 1, list.length, list[i]);
                try {
                    const response = await fetch(list[i], {credentials: 'omit', cache: 'no-store', redirect: 'follow'});
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
            for (const href of Array.from(ownCss).slice(0, 20)) {
                try {
                    const response = await fetch(href, {credentials: 'omit'});
                    if (!response.ok) continue;
                    const base = new URL(response.url);
                    cssUrls((await response.text()).slice(0, 2000000)).forEach(value => {
                        let url;
                        try { url = new URL(value, base); } catch (_) { return; }
                        const host = url.hostname.toLowerCase();
                        if ((url.protocol === 'http:' || url.protocol === 'https:') && !own.has(host)) add(host, FONT.test(url.pathname) ? 'font' : 'css', null);
                    });
                } catch (_) { /* A stylesheet that cannot be read is skipped. */ }
            }
            const payload = {};
            hosts.forEach((value, host) => { payload[host] = {types: Array.from(value.types), pages: Array.from(value.pages)}; });
            report = await post({action: 'erinv_save', hosts: JSON.stringify(payload), pages: JSON.stringify(pages)});
            render();
            status.replaceChildren(el('span', fmt(T.scanned, pages.length, failed, report.scanned)));
            notes.forEach(note => status.append(el('span', note, 'erinv-note')));
        } catch (error) {
            status.textContent = error.message;
        } finally {
            busy = false; scanButton.disabled = false;
        }
    }

    function where(row) {
        const cell = el('td');
        if (row.browser) {
            const line = el('div');
            line.append(el('strong', T.browser + ': '), el('span', row.browser.types.map(t => T.types[t] || t).join(', ')));
            cell.append(line);
            if (row.browser.pages.length) cell.append(el('div', row.browser.pages.join('  '), 'erinv-muted erinv-pages'));
        }
        if (row.server) {
            const line = el('div');
            line.append(el('strong', T.server + ': '), el('span', row.server.count === 1 ? fmt(T.once, row.server.last) : fmt(T.times, row.server.count, row.server.last)));
            cell.append(line);
            const by = row.server.sources.join(', ') + ' (' + row.server.contexts.map(c => T.contexts[c] || c).join(', ') + ')';
            cell.append(el('div', by, 'erinv-muted'));
        }
        return cell;
    }

    function render() {
        body.replaceChildren();
        const rows = report.rows;
        rows.forEach(row => {
            const tr = document.createElement('tr');
            const host = el('td');
            const name = el('div');
            name.append(el('strong', row.host));
            if (row.new) name.append(' ', el('span', T.new, 'erinv-badge'));
            host.append(name);
            if (row.service) host.append(el('div', row.service, 'erinv-muted'));
            const policy = el('td', row.policy === null ? T.na : (row.policy ? T.yes : T.no), row.policy === false ? 'erinv-no' : (row.policy ? 'erinv-yes' : 'erinv-muted'));
            tr.append(host, where(row), el('td', row.first), policy);
            body.append(tr);
        });
        if (!rows.length) {
            const tr = document.createElement('tr');
            const td = el('td', T.empty);
            td.colSpan = 4;
            tr.append(td);
            body.append(tr);
        }
        summary.replaceChildren(el('span', fmt(T.summary, rows.length, rows.filter(r => r.policy === false).length, rows.filter(r => r.new).length)));
        if (!report.policy_url) summary.append(el('span', T.nopolicy, 'erinv-note'));
        if (!busy) status.textContent = report.scanned ? fmt(T.last, report.scanned, report.pages.length) : T.never;
        recording.replaceChildren(el('span', report.recording ? fmt(T.recordOn, report.server_since) : T.recordOff));
        if (report.dropped) recording.append(el('span', fmt(T.dropped, report.dropped), 'erinv-note'));
        toggle.textContent = report.recording ? T.pause : T.resume;
        csvButton.disabled = !rows.length;
    }

    async function action(task) {
        try {
            report = await post({action: 'erinv_action', task});
            render();
        } catch (error) {
            status.textContent = error.message;
        }
    }

    function csv(value) {
        let text = String(value == null ? '' : value);
        // Protect spreadsheet consumers from formula interpretation.
        if (/^[\s\u0000-\u001f]*[=+@-]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    scanButton.addEventListener('click', scan);
    toggle.addEventListener('click', () => action(report && report.recording ? 'pause' : 'resume'));
    clear.addEventListener('click', () => { if (window.confirm(T.confirmClr)) action('clear'); });
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

    status.textContent = T.loading;
    post({action: 'erinv_report'}).then(data => { report = data; render(); }).catch(error => { status.textContent = error.message; });
}());
