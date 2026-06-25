/**
 * AJAX-степпер импорта (§6 v1.2): public_id из пикера ИЛИ из textarea → порции по
 * «шагу импорта» → последовательно на admin-контроллер (importBatch). Прогресс
 * (спиннер+бар), сводка (создано/обновлено/ошибок), отмена между порциями, persist.
 *
 * Способы независимы: пикер НЕ заполняет поле ввода; во время импорта кнопки и поле
 * блокируются, повторный запуск запрещён.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'oc-import-last';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        var cfg = window.OneCatalogCfg;
        if (!cfg) { return; }
        var M = cfg.messages || {};

        var pickBtn = document.getElementById('oc-open-picker');
        var impBtn = document.getElementById('oc-import-btn');
        var cancelBtn = document.getElementById('oc-cancel-btn');
        var ta = document.getElementById('oc-ids');
        var status = document.getElementById('oc-status');
        var spinner = document.getElementById('oc-spinner');
        var prog = document.getElementById('oc-progress');
        var bar = document.getElementById('oc-bar');
        var summaryEl = document.getElementById('oc-summary');
        var logEl = document.getElementById('oc-log');

        var busy = false;
        var cancelled = false;

        function beforeUnload(e) { e.preventDefault(); e.returnValue = ''; return ''; }

        function setBusy(b) {
            busy = b;
            if (pickBtn) { pickBtn.disabled = b; }
            if (impBtn) { impBtn.disabled = b; }
            if (ta) { ta.disabled = b; }
            if (spinner) { spinner.style.display = b ? 'inline-block' : 'none'; }
            if (cancelBtn) { cancelBtn.style.display = b ? '' : 'none'; cancelBtn.disabled = false; }
            if (status) { status.style.display = 'block'; }
            if (b) { window.addEventListener('beforeunload', beforeUnload); }
            else { window.removeEventListener('beforeunload', beforeUnload); }
        }

        function setBar(pct, cls) {
            if (!bar) { return; }
            bar.style.width = pct + '%';
            bar.className = 'oc-bar' + (cls ? ' ' + cls : '');
        }

        function summaryText(c) {
            var parts = [];
            parts.push((M.created || 'Created') + ': ' + (c.created || 0));
            parts.push((M.updated || 'Updated') + ': ' + (c.updated || 0));
            parts.push((M.errors || 'Errors') + ': ' + (c.error || 0));
            return parts.join(' · ');
        }
        function tally(c, results) {
            (results || []).forEach(function (r) {
                var s = r && r.status ? r.status : 'error';
                c[s] = (c[s] || 0) + 1;
            });
        }

        function persist() {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    ts: Date.now(),
                    prog: prog ? prog.textContent : '',
                    summary: summaryEl ? summaryEl.textContent : '',
                    log: logEl ? logEl.textContent : '',
                    barClass: bar ? bar.className : '',
                    barWidth: bar ? bar.style.width : '0%'
                }));
            } catch (e) {}
        }
        function restore() {
            var raw;
            try { raw = window.localStorage.getItem(STORAGE_KEY); } catch (e) { return; }
            if (!raw) { return; }
            var d;
            try { d = JSON.parse(raw); } catch (e) { return; }
            if (!d) { return; }
            if (status) { status.style.display = 'block'; }
            if (spinner) { spinner.style.display = 'none'; }
            var when = '';
            try { when = ' (' + new Date(d.ts).toLocaleString() + ')'; } catch (e) {}
            if (prog) { prog.textContent = (M.last || 'Last result') + when + ' — ' + (d.prog || ''); }
            if (summaryEl) { summaryEl.textContent = d.summary || ''; }
            if (logEl) { logEl.textContent = d.log || ''; }
            if (bar) { bar.className = d.barClass || 'oc-bar'; bar.style.width = d.barWidth || '0%'; }
        }

        if (pickBtn) {
            pickBtn.addEventListener('click', function () {
                if (busy) { return; }
                window.OneCatalogPicker.open(cfg, function (ids) { runImport(ids || []); });
            });
        }
        if (impBtn) {
            impBtn.addEventListener('click', function () {
                if (busy) { return; }
                runImport(splitIds(ta.value));
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () { cancelled = true; cancelBtn.disabled = true; });
        }

        function splitIds(s) { return String(s || '').split(/[\s,;]+/).filter(Boolean); }
        function chunk(a, n) { var r = []; for (var i = 0; i < a.length; i += n) { r.push(a.slice(i, i + n)); } return r; }

        function runImport(ids) {
            if (busy) { return; }
            ids = (ids || []).filter(Boolean);
            if (!ids.length) { alert(M.empty || 'No IDs'); return; }

            var batches = chunk(ids, cfg.step || 10);
            var total = ids.length;
            var done = 0;
            var i = 0;
            var counts = { created: 0, updated: 0, error: 0 };

            cancelled = false;
            setBusy(true);
            if (summaryEl) { summaryEl.textContent = ''; }
            if (prog) { prog.textContent = (M.importing || 'Importing...') + ' 0/' + total; }
            setBar(0, '');

            function finish(label, cls) {
                setBusy(false);
                if (prog) { prog.textContent = label + ' ' + Math.min(done, total) + '/' + total; }
                if (summaryEl) { summaryEl.textContent = summaryText(counts); }
                setBar(total > 0 ? Math.round((Math.min(done, total) / total) * 100) : 0, cls);
                persist();
            }

            function next() {
                if (cancelled) { finish(M.cancelled || 'Cancelled:', 'oc-err'); return; }
                if (i >= batches.length) { finish(M.done || 'Done:', 'oc-ok'); return; }

                var b = batches[i++];
                var body = 'form_key=' + encodeURIComponent(cfg.formKey || '');
                b.forEach(function (id) { body += (body ? '&' : '') + 'ids[]=' + encodeURIComponent(id); });

                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.error) { finish((M.error || 'Error') + ': ' + d.error, 'oc-err'); return; }
                        tally(counts, d.results);
                        done += b.length;
                        if (prog) { prog.textContent = (M.importing || 'Importing...') + ' ' + Math.min(done, total) + '/' + total; }
                        setBar(Math.round((Math.min(done, total) / total) * 100), '');
                        if (summaryEl) { summaryEl.textContent = summaryText(counts); }
                        if (d.log) { renderLog(d.log); }
                        next();
                    })
                    .catch(function () { finish(M.error || 'Error', 'oc-err'); });
            }
            next();
        }

        function renderLog(log) {
            logEl.textContent = log.slice(-50).map(function (e) {
                return '[' + e.status + '] ' + e.public_id + (e.message ? ' — ' + e.message : '');
            }).join('\n');
        }

        restore();
    });
})();
