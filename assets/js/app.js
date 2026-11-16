/* FinTrack - vanilla JavaScript */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ---------- Toasts ---------- */
    function dismissToast(t) {
        t.classList.add('out');
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 320);
    }
    $$('.toast').forEach(function (t) {
        var btn = $('.toast-close', t);
        if (btn) btn.addEventListener('click', function () { dismissToast(t); });
        setTimeout(function () { dismissToast(t); }, 6000);
    });

    /* ---------- Mobile sidebar (burger menu) ---------- */
    var sidebar = $('#sidebar'), backdrop = $('#sidebarBackdrop'), toggle = $('#menuToggle');
    var mobileMq = window.matchMedia('(max-width: 900px)');

    function sidebarIsOpen() { return !!sidebar && sidebar.classList.contains('open'); }
    function setSidebar(open) {
        if (!sidebar) return;
        open = !!open && mobileMq.matches;      // the drawer only exists on small screens
        sidebar.classList.toggle('open', open);
        if (backdrop) backdrop.classList.toggle('open', open);
        document.body.classList.toggle('nav-open', open);
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }
    }
    if (sidebar && toggle) {
        toggle.addEventListener('click', function () { setSidebar(!sidebarIsOpen()); });
        // One delegated listener: closes on a nav link click or a click anywhere outside the menu
        document.addEventListener('click', function (ev) {
            if (!sidebarIsOpen() || toggle.contains(ev.target)) return;
            if (!sidebar.contains(ev.target) || ev.target.closest('a.nav-link')) setSidebar(false);
        });
        // Leaving the mobile breakpoint (rotate / resize) resets the drawer state
        var onBreakpoint = function () { if (!mobileMq.matches) setSidebar(false); };
        if (mobileMq.addEventListener) mobileMq.addEventListener('change', onBreakpoint);
        else if (mobileMq.addListener) mobileMq.addListener(onBreakpoint);
    }

    /* ---------- Modals ---------- */
    function openModal(id) {
        var m = document.getElementById(id);
        if (!m) return null;
        m.classList.add('open');
        var first = $('input:not([type=hidden]):not([readonly]), select, textarea', m);
        if (first) setTimeout(function () { first.focus(); }, 30);
        return m;
    }
    function closeModal(m) { if (m) m.classList.remove('open'); }

    function resetForm(modal) {
        var form = $('form', modal);
        if (!form) return;
        form.reset();
        $$('input[type=hidden][data-clear]', form).forEach(function (h) { h.value = ''; });
        // default every date input flagged data-today to today
        var d = new Date();
        var today = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        $$('input[data-today]', form).forEach(function (i) { i.value = today; });
        var title = $('[data-title-add]', modal);
        if (title) title.textContent = title.getAttribute('data-title-add');
    }

    function fillForm(modal, record) {
        var form = $('form', modal);
        if (!form || !record) return;
        Object.keys(record).forEach(function (key) {
            var field = form.elements[key];
            if (field && field.type !== 'file') field.value = record[key] === null ? '' : record[key];
        });
        var title = $('[data-title-edit]', modal);
        if (title) title.textContent = title.getAttribute('data-title-edit');
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        var openBtn = t.closest('[data-modal-open]');
        if (openBtn) {
            var modal = document.getElementById(openBtn.getAttribute('data-modal-open'));
            if (modal) {
                if (openBtn.hasAttribute('data-reset')) resetForm(modal);
                openModal(modal.id);
            }
            return;
        }
        var editBtn = t.closest('.js-edit');
        if (editBtn) {
            var em = document.getElementById(editBtn.getAttribute('data-target'));
            if (em) {
                resetForm(em);
                try { fillForm(em, JSON.parse(editBtn.getAttribute('data-record'))); } catch (e) { /* ignore */ }
                openModal(em.id);
            }
            return;
        }
        var delBtn = t.closest('.js-delete');
        if (delBtn) {
            var dm = document.getElementById(delBtn.getAttribute('data-target'));
            if (dm) {
                var idField = $('input[name=id]', dm);
                if (idField) idField.value = delBtn.getAttribute('data-id');
                var typeField = $('input[name=type]', dm);
                if (typeField && delBtn.hasAttribute('data-type')) typeField.value = delBtn.getAttribute('data-type');
                var action = delBtn.getAttribute('data-action');
                if (action) { var f = $('form', dm); if (f) f.setAttribute('action', action); }
                var label = $('[data-delete-label]', dm);
                if (label) label.textContent = delBtn.getAttribute('data-label') || 'this record';
                openModal(dm.id);
            }
            return;
        }
        var moneyBtn = t.closest('.js-money');
        if (moneyBtn) {
            var mm = document.getElementById(moneyBtn.getAttribute('data-target'));
            if (mm) {
                resetForm(mm);
                $('input[name=id]', mm).value = moneyBtn.getAttribute('data-id');
                $('select[name=mode]', mm).value = moneyBtn.getAttribute('data-mode');
                var nm = $('[data-goal-name]', mm);
                if (nm) nm.textContent = moneyBtn.getAttribute('data-name');
                openModal(mm.id);
            }
            return;
        }
        var viewBtn = t.closest('.js-view');
        if (viewBtn) {
            var vm = document.getElementById(viewBtn.getAttribute('data-target'));
            if (vm) {
                try {
                    var rec = JSON.parse(viewBtn.getAttribute('data-record'));
                    $$('[data-field]', vm).forEach(function (el) {
                        var v = rec[el.getAttribute('data-field')];
                        el.textContent = (v === null || v === '' || v === undefined) ? '—' : v;
                    });
                } catch (e) { /* ignore */ }
                openModal(vm.id);
            }
            return;
        }
        if (t.closest('[data-modal-close]') || t.classList.contains('modal-backdrop')) {
            closeModal(t.closest('.modal'));
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            $$('.modal.open').forEach(closeModal);
            setSidebar(false);
        }
    });

    // Open an edit modal automatically (e.g. ?edit=12 from the transactions page)
    var auto = $('#autoEdit');
    if (auto) {
        var am = document.getElementById(auto.getAttribute('data-target'));
        if (am) {
            try { fillForm(am, JSON.parse(auto.getAttribute('data-record'))); } catch (e) { /* ignore */ }
            openModal(am.id);
        }
    }
    // Re-open modal flagged by the server after a validation error
    var reopen = $('#reopenModal');
    if (reopen) openModal(reopen.getAttribute('data-target'));

    /* ---------- Client-side form checks ---------- */
    $$('form[data-match]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var a = form.elements[form.getAttribute('data-match').split(',')[0]];
            var b = form.elements[form.getAttribute('data-match').split(',')[1]];
            if (a && b && a.value !== b.value) {
                ev.preventDefault();
                b.setCustomValidity('Passwords do not match');
                b.reportValidity();
                b.addEventListener('input', function () { b.setCustomValidity(''); }, { once: true });
            }
        });
    });
    $$('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
        });
    });
    $$('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });
    $$('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { if (el.form) el.form.submit(); });
    });

    /* ---------- Charts (Chart.js) ---------- */
    var dataEl = document.getElementById('chart-data');
    if (!dataEl) return;
    var cfg;
    try { cfg = JSON.parse(dataEl.textContent); } catch (e) { return; }

    function fmt(n) {
        return cfg.currency + Number(n).toLocaleString(undefined, {
            minimumFractionDigits: cfg.decimals, maximumFractionDigits: cfg.decimals
        });
    }
    function short(n) {
        var abs = Math.abs(n);
        if (abs >= 1e6) return cfg.currency + (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (abs >= 1e3) return cfg.currency + (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
        return cfg.currency + n;
    }
    function message(canvas, text) {
        var box = canvas.parentNode;
        box.innerHTML = '<div class="chart-empty"><span>' + text + '</span></div>';
    }

    cfg.charts.forEach(function (def) {
        var canvas = document.getElementById(def.id);
        if (!canvas) return;
        var hasData = def.datasets.some(function (ds) {
            return ds.data.some(function (v) { return Number(v) > 0; });
        });
        if (!hasData) { message(canvas, def.empty || 'No data to display yet.'); return; }
        if (typeof Chart === 'undefined') {
            message(canvas, 'Chart library could not be loaded. Check your internet connection.');
            return;
        }

        var options, datasets;
        if (def.type === 'doughnut') {
            datasets = [{
                data: def.datasets[0].data,
                backgroundColor: def.colors,
                borderWidth: 2,
                borderColor: '#fff'
            }];
            options = {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                var total = c.dataset.data.reduce(function (a, b) { return a + Number(b); }, 0);
                                var pct = total ? (c.parsed / total * 100).toFixed(1) : 0;
                                return ' ' + c.label + ': ' + fmt(c.parsed) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            };
        } else {
            datasets = def.datasets.map(function (ds) {
                var o = { label: ds.label, data: ds.data, borderColor: ds.color, backgroundColor: ds.color };
                if (def.type === 'bar') { o.borderRadius = 5; o.maxBarThickness = 38; }
                else {
                    o.tension = 0.35; o.pointRadius = 3; o.borderWidth = 2.5;
                    if (def.fill) o.backgroundColor = ds.color + '26';
                    o.fill = !!def.fill;
                }
                return o;
            });
            options = {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: def.datasets.length > 1, position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + fmt(c.parsed.y); } } }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: function (v) { return short(v); } } }
                }
            };
        }
        new Chart(canvas, { type: def.type, data: { labels: def.labels, datasets: datasets }, options: options });
    });
})();
