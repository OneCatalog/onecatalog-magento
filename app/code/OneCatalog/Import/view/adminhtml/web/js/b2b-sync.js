/**
 * B2B-синк — браузерный степпер (§13): идёт по страницам фида (start/limit) на
 * extension/module/onecatalog/b2bSync, показывает прогресс и сводку
 * (изменено/без изменений/нет в каталоге). Не зависит от cron.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        var cfg = window.OneCatalogB2bCfg;
        if (!cfg) { return; }
        var M = cfg.messages || {};

        var runBtn = document.getElementById('oc-b2b-run');
        var spinner = document.getElementById('oc-b2b-spinner');
        var prog = document.getElementById('oc-b2b-progress');
        var bar = document.getElementById('oc-b2b-bar');
        var summaryEl = document.getElementById('oc-b2b-summary');
        var statusBox = document.getElementById('oc-b2b-status');

        if (!runBtn) { return; }

        var busy = false;

        function beforeUnload(e) { e.preventDefault(); e.returnValue = ''; return ''; }

        runBtn.addEventListener('click', function () {
            if (busy) { return; }
            busy = true;
            runBtn.disabled = true;
            if (statusBox) { statusBox.style.display = 'block'; }
            if (spinner) { spinner.style.display = 'inline-block'; }
            window.addEventListener('beforeunload', beforeUnload);

            var totals = { scanned: 0, changed: 0, unchanged: 0, missing: 0, total: 0 };

            function setBar(pct, cls) {
                if (!bar) { return; }
                bar.style.width = pct + '%';
                bar.className = 'oc-bar' + (cls ? ' ' + cls : '');
            }
            function summary() {
                return (M.changed || 'Changed') + ': ' + totals.changed
                    + ' · ' + (M.unchanged || 'Unchanged') + ': ' + totals.unchanged
                    + ' · ' + (M.missing || 'Not in catalog') + ': ' + totals.missing;
            }
            function stop(cls) {
                busy = false;
                runBtn.disabled = false;
                if (spinner) { spinner.style.display = 'none'; }
                window.removeEventListener('beforeunload', beforeUnload);
                if (summaryEl) { summaryEl.textContent = summary(); }
                setBar(100, cls);
            }

            function page(start) {
                var body = 'form_key=' + encodeURIComponent(cfg.formKey || '') + '&start=' + encodeURIComponent(start) + '&limit=' + encodeURIComponent(cfg.limit || 200);
                fetch(cfg.syncUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.error) { if (prog) { prog.textContent = (M.error || 'Error') + ': ' + d.error; } stop('oc-err'); return; }
                        totals.scanned += (d.scanned || 0);
                        totals.changed += (d.changed || 0);
                        totals.unchanged += (d.unchanged || 0);
                        totals.missing += (d.missing || 0);
                        totals.total = d.total || totals.total;
                        if (prog) {
                            prog.textContent = (M.running || 'Syncing…') + ' '
                                + totals.scanned + (totals.total ? '/' + totals.total : '');
                        }
                        if (summaryEl) { summaryEl.textContent = summary(); }
                        if (totals.total > 0) { setBar(Math.min(100, Math.round(totals.scanned / totals.total * 100)), ''); }
                        if (d.more) { page(d.next); }
                        else { if (prog) { prog.textContent = (M.done || 'Done:') + ' ' + totals.scanned; } stop('oc-ok'); }
                    })
                    .catch(function () { stop('oc-err'); });
            }

            if (prog) { prog.textContent = (M.running || 'Syncing…') + ' 0'; }
            setBar(0, '');
            if (summaryEl) { summaryEl.textContent = ''; }
            page(0);
        });
    });
})();
