// EVORA shared front-end behaviors

// ---------------------------------------------------------------------
// Chart.js look (v34). Runs at load time — before any page's
// DOMContentLoaded chart code — so every chart inherits it.
// Single-series trends use the brand hue; status charts use the reserved
// status palette (validated: CVD ΔE 15.1, normal-vision ΔE 24.1), always
// with a labelled legend so color is never the only signal.
// ---------------------------------------------------------------------
// Night mode (v44): the status palette stays the same (it also passes on the dark surface);
// ink, grid, slice gaps, the empty track and the brand hue switch with the theme.
const evoraChartDark = document.documentElement.getAttribute('data-theme') === 'dark';
window.EvoraChart = {
    brand: evoraChartDark ? '#818cf8' : '#4f46e5',
    brandFill: evoraChartDark ? 'rgba(129, 140, 248, 0.16)' : 'rgba(79, 70, 229, 0.12)',
    status: { success: '#15803d', warning: '#f59e0b', danger: '#dc2626', neutral: '#64748b', track: evoraChartDark ? '#2a2f3c' : '#e5e7eb' },
};
if (typeof Chart !== 'undefined') {
    const d = Chart.defaults;
    d.font.family = "'Inter', 'Segoe UI', Roboto, sans-serif";
    d.font.size = 12;
    d.color = evoraChartDark ? '#9aa3b2' : '#5b6474';    // axis + legend text: muted ink, never series color
    d.borderColor = evoraChartDark ? '#262b38' : '#eef0f5';   // recessive grid
    d.maintainAspectRatio = true;
    d.elements.line.borderWidth = 2;
    d.elements.line.tension = 0.35;
    d.elements.line.cubicInterpolationMode = 'monotone';   // no overshoot below zero
    d.elements.point.radius = 3;
    d.elements.point.hoverRadius = 6;
    d.elements.point.hitRadius = 12;           // hit target larger than the mark
    d.elements.bar.borderRadius = 4;
    d.elements.bar.maxBarThickness = 40;
    d.elements.arc.borderWidth = 2;            // 2px surface gap between slices
    d.elements.arc.borderColor = evoraChartDark ? '#171a23' : '#ffffff';
    d.interaction.mode = 'index';              // hover anywhere on a column / time step
    d.interaction.intersect = false;
    d.plugins.legend.labels.usePointStyle = true;
    d.plugins.legend.labels.boxWidth = 8;
    d.plugins.legend.labels.padding = 14;
    Object.assign(d.plugins.tooltip, {
        backgroundColor: evoraChartDark ? '#e5e7eb' : '#111827', titleColor: evoraChartDark ? '#111827' : '#fff', bodyColor: evoraChartDark ? '#1f2937' : '#e5e7eb',
        padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true,
    });
    d.scale.grid.drawTicks = false;
    d.scale.border = { display: false };
    // Doughnut legends read "Approved (7)": the label + value carry the meaning, not color alone.
    const baseLabels = Chart.overrides.doughnut.plugins.legend.labels.generateLabels;
    Chart.overrides.doughnut.plugins.legend.labels.generateLabels = function (chart) {
        const values = chart.data.datasets[0] ? chart.data.datasets[0].data : [];
        return baseLabels(chart).map(function (item, i) {
            item.text = item.text + ' (' + (values[i] ?? 0) + ')';
            return item;
        });
    };
    Chart.overrides.doughnut.cutout = '68%';
    Chart.overrides.doughnut.interaction = { mode: 'nearest', intersect: true };

    // Consistent chart proportions: canvases inside cards drop their fixed
    // height attribute (which forced a tall 1.5:1 box) and use one aspect
    // ratio per chart kind. This script is at the end of <body>, so the
    // canvases already exist; the pages build their charts on DOMContentLoaded.
    d.aspectRatio = 2;
    Chart.overrides.doughnut.aspectRatio = 1.6;
    document.querySelectorAll('.card-evora canvas[height]').forEach(function (canvas) {
        canvas.removeAttribute('height');
        canvas.removeAttribute('width');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const hasSwal = typeof Swal !== 'undefined';

    // Mobile navigation: slide-in sidebar with a backdrop; closes on backdrop
    // tap, Escape, or following a link. The toggle reports its state (aria-expanded).
    const mobileSidebar = document.getElementById('evoraSidebar');
    const mobileToggle = document.getElementById('sidebarMobileToggle');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (mobileSidebar && mobileToggle) {
        const setOpen = function (open) {
            mobileSidebar.classList.toggle('show', open);
            if (backdrop) backdrop.classList.toggle('show', open);
            document.body.classList.toggle('sidebar-open', open);
            mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            mobileToggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
            if (open) {
                const current = mobileSidebar.querySelector('.nav-link.active') || mobileSidebar.querySelector('.nav-link');
                if (current) current.focus();
            }
        };
        mobileToggle.addEventListener('click', function () { setOpen(!mobileSidebar.classList.contains('show')); });
        if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && mobileSidebar.classList.contains('show')) { setOpen(false); mobileToggle.focus(); }
        });
        mobileSidebar.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () { if (window.innerWidth < 992) setOpen(false); });
        });
    }

    // Any table not already inside .table-responsive scrolls horizontally on
    // small screens instead of stretching the page.
    document.querySelectorAll('main table.table').forEach(function (table) {
        if (table.closest('.table-responsive') || table.closest('.dataTables_wrapper')) return;
        const wrap = document.createElement('div');
        wrap.className = 'table-responsive';
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);
    });

    // Phones (v46): a table too wide for the screen becomes a stack of cards. Every cell gets
    // its column name (data-label) once; .table-stack is only on while the table would not fit.
    // Opt out with data-no-stack.
    const stackTables = Array.from(document.querySelectorAll('main table.table')).filter(function (table) {
        const heads = Array.from(table.querySelectorAll('thead th')).map(function (th) { return th.textContent.trim(); });
        if (heads.length < 3 || table.hasAttribute('data-no-stack')) return false;
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            let col = 0;
            Array.from(tr.children).forEach(function (td) {
                if (!td.hasAttribute('data-label')) td.setAttribute('data-label', td.colSpan > 1 ? '' : (heads[col] || ''));
                col += td.colSpan || 1;
            });
        });
        return true;
    });
    function fitTables() {
        stackTables.forEach(function (table) {
            table.classList.remove('table-stack');
            if (window.innerWidth < 576 && table.scrollWidth > table.parentElement.clientWidth + 2) table.classList.add('table-stack');
        });
    }
    let fitTimer = null;
    window.addEventListener('resize', function () { clearTimeout(fitTimer); fitTimer = setTimeout(fitTables, 150); });
    ['shown.bs.tab', 'shown.bs.collapse', 'shown.bs.modal'].forEach(function (ev) { document.addEventListener(ev, fitTables); }); // tables that were hidden
    window.evoraFitTables = fitTables;

    // Bootstrap tooltips for elements that opt in with data-bs-toggle="tooltip".
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
    }

    // Alert persistence (see evoraAlert() in core/helpers.php and docs/UI_DESIGN_SYSTEM.md).
    // An alert NEVER disappears on its own unless it opts in with data-autohide="ms".
    // Action-required notices, warnings and errors have no data-autohide, so they stay
    // until the user closes them or the page stops rendering them (action completed).
    const closeAlert = function (alertEl) {
        if (!alertEl.isConnected) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
            bootstrap.Alert.getOrCreateInstance(alertEl).close(); // fades out (.fade), respects reduced motion
        } else {
            alertEl.remove();
        }
    };
    // Timed alert that pauses while hovered or focused, so it never vanishes mid-read.
    const autoHide = function (alertEl, ms) {
        let remaining = ms;
        let started = Date.now();
        let timer = setTimeout(function () { closeAlert(alertEl); }, remaining);
        const pause = function () { clearTimeout(timer); remaining -= Date.now() - started; };
        const resume = function () {
            if (alertEl.matches(':hover') || alertEl.contains(document.activeElement)) return;
            started = Date.now();
            clearTimeout(timer);
            timer = setTimeout(function () { closeAlert(alertEl); }, Math.max(remaining, 1500));
        };
        alertEl.addEventListener('mouseenter', pause);
        alertEl.addEventListener('focusin', pause);
        alertEl.addEventListener('mouseleave', resume);
        alertEl.addEventListener('focusout', resume);
    };
    document.querySelectorAll('.alert[data-autohide]').forEach(function (alertEl) {
        const ms = parseInt(alertEl.getAttribute('data-autohide'), 10);
        if (!(ms > 0)) return;
        // Timed flash messages become a SweetAlert2 toast with the same duration (progressive
        // enhancement — without SweetAlert2 the inline alert stays and fades out instead).
        const message = alertEl.textContent.trim();
        if (alertEl.hasAttribute('data-toast') && hasSwal && message) {
            Swal.fire({
                toast: true, position: 'top-end', icon: alertEl.getAttribute('data-toast') === 'info' ? 'info' : 'success', title: message,
                showConfirmButton: false, showCloseButton: true, timer: ms, timerProgressBar: true,
                didOpen: function (toast) {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                },
            });
            alertEl.remove();
            return;
        }
        autoHide(alertEl, ms);
    });

    // Notification bell live refresh (v31.1). Polls the viewer's own notifications every
    // 30 s while the tab is visible; the server treats it as a passive request, so it never
    // keeps an idle session alive. The menu is only swapped while it is closed.
    const notifMenu = document.querySelector('.notif-dropdown[data-notif-poll]');
    if (notifMenu && window.fetch) {
        const bell = document.querySelector('.notif-bell');
        const badge = bell ? bell.querySelector('.notif-count') : null;
        let lastNewest = null; // baseline set by the first poll: no toast for what was already there
        let pendingHtml = null;
        const applyCount = function (unread) {
            if (badge) {
                badge.textContent = unread > 99 ? '99+' : String(unread);
                badge.classList.toggle('d-none', unread < 1);
            }
            if (bell) bell.setAttribute('aria-label', 'Notifications' + (unread ? ' (' + unread + ' unread)' : ''));
            notifMenu.setAttribute('data-notif-unread', String(unread));
        };
        const menuOpen = function () { return notifMenu.classList.contains('show'); };
        const poll = function () {
            if (document.hidden) return;
            fetch(notifMenu.getAttribute('data-notif-poll'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || typeof data.unread !== 'number') return;
                    applyCount(data.unread);
                    if (menuOpen()) { pendingHtml = data.html; } else { notifMenu.innerHTML = data.html; }
                    if (lastNewest !== null && data.newest_id > lastNewest && hasSwal && data.newest_title) {
                        Swal.fire({
                            toast: true, position: 'top-end', icon: 'info', title: 'New notification', text: data.newest_title,
                            showConfirmButton: false, showCloseButton: true, timer: 5000, timerProgressBar: true,
                        });
                    }
                    lastNewest = Math.max(lastNewest || 0, data.newest_id || 0);
                })
                .catch(function () { /* offline or session ended: try again next tick */ });
        };
        if (bell) {
            bell.addEventListener('hidden.bs.dropdown', function () {
                if (pendingHtml !== null) { notifMenu.innerHTML = pendingHtml; pendingHtml = null; }
            });
        }
        poll();
        setInterval(poll, 30000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    }

    // Replace native confirm() dialogs: <form data-confirm="Are you sure?">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === 'true') return; // already confirmed, let it through
            e.preventDefault();
            const message = form.getAttribute('data-confirm');
            // Capture whichever submit button triggered this, so a form.submit()
            // fallback below still includes its name=value (e.g. action=approve) —
            // plain form.submit() drops that, unlike a real button click.
            const submitter = e.submitter || form.querySelector('button[type="submit"]');
            const resubmit = function () {
                if (submitter && form.requestSubmit) {
                    form.requestSubmit(submitter);
                } else {
                    form.submit();
                }
            };
            if (!hasSwal) {
                if (confirm(message)) {
                    form.dataset.confirmed = 'true';
                    resubmit();
                }
                return;
            }
            const danger = form.dataset.confirmVariant === 'danger';
            Swal.fire({
                title: form.dataset.confirmTitle || 'Please confirm', text: message, icon: danger ? 'warning' : 'question',
                showCancelButton: true, confirmButtonText: form.dataset.confirmButton || 'Yes, continue', cancelButtonText: 'Cancel',
                confirmButtonColor: danger ? '#dc2626' : '#4f46e5', reverseButtons: true, focusCancel: danger,
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.dataset.confirmed = 'true';
                    resubmit();
                    if (submitter) {
                        submitter.disabled = true;
                        submitter.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + submitter.innerText.trim();
                    }
                }
            });
        });
    });

    // ---------------------------------------------------------------------
    // v34.1 confirmation pattern. Presentation only: the same button submits
    // the same form with the same name=value after the user confirms.
    //
    //   <button data-confirm-click="What happens"            (required)
    //           data-confirm-title="Cancel Event?"            (optional)
    //           data-confirm-button="Cancel Event"            (optional)
    //           data-confirm-variant="danger|primary|success">
    //
    //   <form data-prompt="Why?" data-prompt-field="remarks" data-prompt-min="10"
    //         data-prompt-title="Cancel Event?" data-prompt-button="Cancel Event">
    // ---------------------------------------------------------------------
    const variantColor = { danger: '#dc2626', success: '#15803d', primary: '#4f46e5', warning: '#b45309' };
    const confirmDialog = function (opts) {
        if (!hasSwal) {
            return Promise.resolve({ isConfirmed: window.confirm((opts.title ? opts.title + '\n\n' : '') + opts.text) });
        }
        return Swal.fire({
            title: opts.title || 'Please confirm',
            text: opts.text,
            icon: opts.variant === 'danger' ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: opts.button || 'Confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: variantColor[opts.variant] || variantColor.primary,
            reverseButtons: true,
            focusCancel: opts.variant === 'danger',
        });
    };
    // Shared with page scripts (e.g. the live game controller) that confirm before a fetch() call.
    window.EvoraConfirm = confirmDialog;
    document.querySelectorAll('[data-confirm-click]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (btn.dataset.confirmed === 'true') { btn.dataset.confirmed = ''; return; }
            const form = btn.form || btn.closest('form');
            e.preventDefault();
            if (form && !form.reportValidity()) return;          // fix inputs first, then confirm
            confirmDialog({
                title: btn.dataset.confirmTitle, text: btn.dataset.confirmClick,
                button: btn.dataset.confirmButton, variant: btn.dataset.confirmVariant,
            }).then(function (result) {
                if (!result.isConfirmed) return;
                // requestSubmit() submits with this button's name=value and fires no click,
                // so the next click asks again. Old browsers: re-click once, flagged.
                if (form && form.requestSubmit) { form.requestSubmit(btn); } else { btn.dataset.confirmed = 'true'; btn.click(); }
            });
        });
    });
    document.querySelectorAll('form[data-prompt]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.prompted === 'true') { form.dataset.prompted = ''; return; }
            e.preventDefault();
            const submitter = e.submitter || form.querySelector('[type="submit"]');
            const field = form.elements[form.dataset.promptField];
            const min = parseInt(form.dataset.promptMin, 10) || 1;
            const done = function (value) {
                if (field) field.value = value;
                form.dataset.prompted = 'true';
                if (form.requestSubmit) { form.requestSubmit(submitter); } else { form.submit(); }
            };
            if (!hasSwal) {
                const answer = window.prompt(form.dataset.prompt);
                if (answer !== null && answer.trim().length >= min) done(answer.trim());
                return;
            }
            Swal.fire({
                title: form.dataset.promptTitle || 'Please confirm',
                text: form.dataset.prompt,
                input: 'textarea',
                inputPlaceholder: 'Type the reason here…',
                inputAttributes: { 'aria-label': form.dataset.prompt, maxlength: '500' },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: form.dataset.promptButton || 'Continue',
                cancelButtonText: 'Cancel',
                confirmButtonColor: variantColor.danger,
                reverseButtons: true,
                inputValidator: function (value) {
                    return (value || '').trim().length < min ? 'Please enter at least ' + min + ' characters.' : undefined;
                },
            }).then(function (result) {
                if (result.isConfirmed) done(result.value.trim());
            });
        });
    });

    // Show a spinner on the clicked submit button while a form posts, so
    // slower actions (uploads, report exports, QR generation) give feedback
    // instead of looking unresponsive. Opt out with data-no-spinner on the form.
    document.querySelectorAll('form:not([data-no-spinner])').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return; // e.g. a confirm dialog on this form was cancelled/pending
            const btn = e.submitter || form.querySelector('button[type="submit"]');
            if (!btn || btn.disabled) return;
            const originalHtml = btn.innerHTML;
            const label = btn.innerText.trim();
            // Defer disabling to the next tick: a disabled control is left out of the
            // submitted form data, so disabling the submit button synchronously here
            // would silently drop its own name=value (e.g. action=approve) from the
            // request the browser is about to send.
            setTimeout(function () {
                btn.dataset.originalHtml = originalHtml;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + label;
            }, 0);
        });
    });

    // Desktop sidebar collapse, persisted per-browser
    const sidebarEl = document.getElementById('evoraSidebar');
    const mainEl = document.querySelector('.evora-main');
    const collapseBtn = document.getElementById('sidebarCollapseToggle');
    if (sidebarEl && mainEl) {
        try {
            if (localStorage.getItem('evora_sidebar_collapsed') === '1') {
                sidebarEl.classList.add('collapsed');
                mainEl.classList.add('collapsed');
            }
        } catch (err) { /* localStorage unavailable — ignore, default expanded */ }
    }
    if (collapseBtn && sidebarEl && mainEl) {
        collapseBtn.addEventListener('click', function () {
            const collapsed = sidebarEl.classList.toggle('collapsed');
            mainEl.classList.toggle('collapsed', collapsed);
            try { localStorage.setItem('evora_sidebar_collapsed', collapsed ? '1' : '0'); } catch (err) { /* ignore */ }
        });
    }

    // v30.2 copy-to-clipboard: <button type="button" data-copy="text">Copy</button>
    // The button briefly shows "Copied" (announced via aria-live on the button).
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.setAttribute('aria-live', 'polite');
        btn.addEventListener('click', function () {
            const text = btn.dataset.copy;
            const label = btn.innerHTML;
            const done = function (ok) {
                btn.innerHTML = ok ? '<i class="bi bi-check2" aria-hidden="true"></i> Copied' : 'Press Ctrl+C to copy';
                setTimeout(function () { btn.innerHTML = label; }, 1800);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
                return;
            }
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.className = 'visually-hidden';
            document.body.appendChild(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
            document.body.removeChild(area);
            done(ok);
        });
    });

    // v34.2 auto-submitting selects (replaces inline onchange="this.form.submit()").
    // A value listed in data-confirm-value (e.g. "cancelled") asks first; Cancel
    // puts the previous value back and nothing is submitted.
    //   <select data-autosubmit data-confirm-value="cancelled" data-confirm-text="…"
    //           data-confirm-title="…" data-confirm-button="…">
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        let previous = sel.value;
        sel.addEventListener('change', function () {
            if (sel.dataset.confirmValue && sel.value === sel.dataset.confirmValue) {
                confirmDialog({
                    title: sel.dataset.confirmTitle, text: sel.dataset.confirmText,
                    button: sel.dataset.confirmButton, variant: 'danger',
                }).then(function (result) {
                    if (result.isConfirmed) { sel.form.submit(); } else { sel.value = previous; }
                });
                return;
            }
            previous = sel.value;
            sel.form.submit();
        });
    });

    // v34.2 client-side filters for a rendered list (presentation only — rows are
    // hidden/shown, nothing is re-queried):
    //   <div class="filter-bar" data-filter-for="tableId">
    //     <input type="search" data-filter="search">  matches row data-search
    //     <select data-filter="category">              matches row data-category
    //     <span class="filter-count"></span>
    //   </div>
    //   <tr data-search="…" data-category="…" data-status="…">  (+ optional tr.filter-empty)
    document.querySelectorAll('[data-filter-for]').forEach(function (bar) {
        const target = document.getElementById(bar.dataset.filterFor);
        if (!target) return;
        const rows = Array.prototype.slice.call(target.querySelectorAll('[data-search]'));
        const controls = Array.prototype.slice.call(bar.querySelectorAll('[data-filter]'));
        const countEl = bar.querySelector('.filter-count');
        const emptyEl = target.querySelector('.filter-empty');
        const apply = function () {
            let shown = 0;
            rows.forEach(function (row) {
                const visible = controls.every(function (c) {
                    const value = c.value.trim().toLowerCase();
                    if (!value) return true;
                    if (c.dataset.filter === 'search') return (row.dataset.search || '').toLowerCase().indexOf(value) !== -1;
                    return (row.dataset[c.dataset.filter] || '').toLowerCase() === value;
                });
                row.classList.toggle('d-none', !visible);
                if (visible) shown++;
            });
            if (emptyEl) emptyEl.classList.toggle('d-none', shown > 0);
            if (countEl) countEl.textContent = shown === rows.length ? rows.length + ' shown' : shown + ' of ' + rows.length + ' shown';
        };
        controls.forEach(function (c) { c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', apply); });
        apply();
    });

    // File input preview: <input type="file" data-preview="#targetSelector">
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        const target = document.querySelector(input.getAttribute('data-preview'));
        if (!target) return;
        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) { target.innerHTML = ''; return; }
            const file = input.files[0];
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function (ev) {
                    target.innerHTML = '<img src="' + ev.target.result + '" class="img-thumbnail mt-2" style="max-height:120px;">';
                };
                reader.readAsDataURL(file);
            } else {
                target.innerHTML = '<div class="small text-muted mt-1"><i class="bi bi-file-earmark-check"></i> ' + file.name + '</div>';
            }
        });
    });

    // Client-side search/sort/pagination/export for listing tables
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        $('.table-datatable').each(function () {
            const paging = this.dataset.paging !== 'false';
            const pageLength = parseInt(this.dataset.pageLength, 10) || 10;
            $(this).DataTable({
                search: { search: new URLSearchParams(location.search).get('dt_search') || '' }, // pre-filled from a search result (v53)
                paging: paging,
                pageLength: pageLength,
                lengthMenu: [10, 25, 50, 100],
                dom: "<'row mb-2'<'col-sm-6'f><'col-sm-6 text-end'B>>t<'row mt-2'<'col-sm-6'i><'col-sm-6'" + (paging ? 'p' : '') + "'>>",
                buttons: [{ extend: 'csv', text: '<i class="bi bi-download" aria-hidden="true"></i><span class="dt-btn-label">Export</span>', titleAttr: 'Export to CSV', className: 'btn btn-outline-secondary btn-sm' }],
                language: {
                    search: '', searchPlaceholder: 'Search...',
                    emptyTable: '<div class="empty-state empty-state-sm"><i class="bi bi-inbox" aria-hidden="true"></i><p>Nothing here yet.</p></div>',
                    zeroRecords: '<div class="empty-state empty-state-sm"><i class="bi bi-search" aria-hidden="true"></i><p>No matches. Try a different search.</p></div>',
                },
            });
        });
    }
    if (window.evoraFitTables) window.evoraFitTables();
});

// Night mode (v44): charts are drawn once with the theme's colors; reload the page's charts on a switch.
document.addEventListener('evora:theme', function () {
    if (typeof Chart !== 'undefined' && Object.keys(Chart.instances || {}).length) {
        window.location.reload();
    }
});

// ---------------------------------------------------------------------
// UI polish (v48): event form wizard, sticky Register bar, full-screen QR.
// ---------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    const esc = function (s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    // Event form wizard: one step per section heading of the form, plus a Review step with the
    // save buttons. Only the display changes — it is still one form, posted once.
    // data-wizard="edit" lets every step be opened directly (an existing event).
    document.querySelectorAll('form[data-wizard]').forEach(function (form) {
        const heads = Array.from(form.querySelectorAll(':scope > h6.text-uppercase'));
        if (heads.length < 2) return;
        const editing = form.dataset.wizard === 'edit';
        const submit = form.querySelector(':scope > button[type=submit]');
        const tail = [];
        if (submit) {
            const prev = submit.previousElementSibling;
            if (prev && prev.classList.contains('alert')) tail.push(prev);
            let n = submit;
            while (n) { const next = n.nextSibling; tail.push(n); n = next; }
        }
        tail.forEach(function (n) { n.remove(); });

        let steps = [];
        heads.forEach(function (h, i) {
            const step = document.createElement('div');
            step.className = 'wizard-step';
            step.dataset.title = h.textContent.trim();
            form.insertBefore(step, h);
            let n = h;
            while (n && n !== heads[i + 1]) { const next = n.nextSibling; step.appendChild(n); n = next; }
            steps.push(step);
        });
        // A section with nothing to fill in (e.g. the PayPal note) joins the step before it.
        const fillable = function (step) {
            return Array.from(step.querySelectorAll('input, select, textarea')).some(function (c) { return !c.disabled && c.type !== 'hidden'; });
        };
        steps = steps.filter(function (step, i) {
            if (i > 0 && !fillable(step)) { while (step.firstChild) steps[i - 1].appendChild(step.firstChild); step.remove(); return false; }
            return true;
        });
        const review = document.createElement('div');
        review.className = 'wizard-step';
        review.dataset.title = 'Review';
        review.innerHTML = '<h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Review</h6>'
            + '<p class="small text-muted">Check the details below, then save. Use Back or the steps above to change anything.</p><dl class="wizard-review"></dl>';
        tail.forEach(function (n) { review.appendChild(n); });
        form.appendChild(review);
        steps.push(review);

        const list = document.createElement('ol');
        list.className = 'wizard-steps';
        list.setAttribute('aria-label', 'Form steps');
        steps.forEach(function (step, i) {
            const li = document.createElement('li');
            li.innerHTML = '<button type="button"><span class="ws-num">' + (i + 1) + '</span><span class="ws-label">' + esc(step.dataset.title) + '</span></button>';
            list.appendChild(li);
        });
        form.insertBefore(list, steps[0]);
        const nav = document.createElement('div');
        nav.className = 'wizard-nav';
        nav.innerHTML = '<button type="button" class="btn btn-outline-secondary" data-wz="back"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</button>'
            + '<span class="wizard-count" aria-live="polite"></span>'
            + '<button type="button" class="btn btn-evora-primary" data-wz="next">Next<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>';
        form.insertBefore(nav, review.nextSibling);

        let current = 0;
        let reached = editing ? steps.length - 1 : 0;
        const controlsOf = function (step) { return Array.from(step.querySelectorAll('input, select, textarea')).filter(function (c) { return !c.disabled && c.willValidate; }); };
        const valid = function (i) {
            const bad = controlsOf(steps[i]).find(function (c) { return !c.checkValidity(); });
            if (!bad) return true;
            show(i);
            bad.reportValidity();
            return false;
        };
        const labelOf = function (c) {
            const l = (c.labels && c.labels[0]) || (c.closest('.form-floating') && c.closest('.form-floating').querySelector('label'));
            return l ? l.textContent.replace(/\s*\*\s*$/, '').replace(/\s+/g, ' ').trim() : (c.name || '');
        };
        const buildReview = function () {
            const dl = review.querySelector('.wizard-review');
            let html = '';
            steps.slice(0, -1).forEach(function (step) {
                let rows = '';
                const seenRadio = {};
                step.querySelectorAll('input, select, textarea').forEach(function (c) {
                    const hiddenBox = c.closest('[hidden]');
                    if (c.type === 'hidden' || c.type === 'submit' || c.closest('.d-none') || (hiddenBox && hiddenBox !== step)) return;
                    let value = '';
                    if (c.type === 'radio') {
                        if (seenRadio[c.name] || !c.checked) return;
                        seenRadio[c.name] = true;
                        value = labelOf(c);
                        rows += '<dt>' + esc(c.closest('[role=radiogroup], fieldset') ? (c.closest('[role=radiogroup], fieldset').getAttribute('aria-label') || 'Option') : 'Option') + '</dt><dd>' + esc(value) + '</dd>';
                        return;
                    }
                    if (c.type === 'checkbox') { if (!c.checked || c.disabled) return; value = 'Yes'; }
                    else if (c.type === 'file') { value = c.files && c.files.length ? c.files[0].name : ''; }
                    else if (c.tagName === 'SELECT') { value = c.selectedOptions.length && c.value !== '' ? c.selectedOptions[0].textContent.trim() : ''; }
                    else { value = c.value.trim(); }
                    if (value === '') return;
                    if (value.length > 140) value = value.slice(0, 140) + '…';
                    rows += '<dt>' + esc(labelOf(c)) + '</dt><dd>' + esc(value) + '</dd>';
                });
                if (rows) html += '<div class="wr-section">' + esc(step.dataset.title) + '</div>' + rows;
            });
            dl.innerHTML = html || '<dd class="text-muted">Nothing filled in yet.</dd>';
        };
        function show(i) {
            current = i;
            reached = Math.max(reached, i);
            steps.forEach(function (s, k) { s.hidden = k !== i; });
            list.querySelectorAll('li').forEach(function (li, k) {
                li.classList.toggle('is-current', k === i);
                li.classList.toggle('is-done', k < i || (editing && k !== i));
                const b = li.querySelector('button');
                b.disabled = k > reached;
                if (k === i) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
            });
            nav.querySelector('[data-wz=back]').style.visibility = i === 0 ? 'hidden' : 'visible';
            nav.querySelector('[data-wz=next]').hidden = i === steps.length - 1;
            nav.querySelector('.wizard-count').textContent = 'Step ' + (i + 1) + ' of ' + steps.length;
            if (i === steps.length - 1) buildReview();
        }
        const goTo = function (target) {
            if (target > current) {
                for (let k = current; k < target; k++) { if (!valid(k)) return; }
            }
            show(target);
            form.closest('.card-evora').scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        nav.querySelector('[data-wz=next]').addEventListener('click', function () { goTo(current + 1); });
        nav.querySelector('[data-wz=back]').addEventListener('click', function () { goTo(current - 1); });
        list.addEventListener('click', function (e) {
            const b = e.target.closest('button');
            if (b && !b.disabled) goTo(Array.from(list.querySelectorAll('button')).indexOf(b));
        });
        // Enter in a field goes to the next step instead of saving early.
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && current < steps.length - 1 && e.target.matches('input:not([type=submit]):not([type=button])')) {
                e.preventDefault();
                goTo(current + 1);
            }
        });
        // A field that is still invalid on save: open its step so the browser can point at it.
        form.addEventListener('invalid', function (e) {
            const i = steps.findIndex(function (s) { return s.contains(e.target); });
            if (i >= 0 && i !== current) show(i);
        }, true);
        const firstError = form.querySelector('.is-invalid');
        show(firstError ? Math.max(0, steps.findIndex(function (s) { return s.contains(firstError); })) : 0);
    });

    // Sticky "Register" bar on phones: repeats the main action of the event page's registration
    // card while that card is scrolled out of view.
    const ctaCard = document.querySelector('[data-mobile-cta]');
    const ctaAction = ctaCard && ctaCard.querySelector('a.btn-evora-primary, button.btn-evora-primary, a.btn-outline-primary');
    if (ctaAction && 'IntersectionObserver' in window) {
        const bar = document.createElement('div');
        bar.className = 'mobile-cta';
        bar.innerHTML = '<div class="mobile-cta-price"><small>' + esc(ctaCard.dataset.ctaLabel || 'Fee') + '</small><strong>' + esc(ctaCard.dataset.ctaPrice || '') + '</strong></div>';
        const copy = document.createElement(ctaAction.tagName === 'A' ? 'a' : 'button');
        copy.className = 'btn btn-evora-primary';
        copy.textContent = ctaAction.textContent.replace(/\s+/g, ' ').trim();
        if (ctaAction.tagName === 'A') { copy.href = ctaAction.href; } else { copy.type = 'button'; copy.addEventListener('click', function () { ctaAction.click(); }); }
        bar.appendChild(copy);
        document.body.appendChild(bar);
        document.body.classList.add('has-mobile-cta');
        new IntersectionObserver(function (entries) { bar.classList.toggle('is-shown', !entries[0].isIntersecting); }).observe(ctaAction);
    }
});

// Full-screen QR (QR Codes page): white background, big code, screen kept awake while it is shown.
document.addEventListener('click', function (e) {
    const trigger = e.target.closest ? e.target.closest('[data-qr-fullscreen]') : null;
    if (!trigger) return;
    e.preventDefault();
    const overlay = document.createElement('div');
    overlay.className = 'qr-fullscreen';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'QR code');
    overlay.innerHTML = '<div class="qr-fs-title"></div><img alt=""><div class="qr-fs-sub">Show this to the staff at the entrance. Turn your screen brightness up for a faster scan.</div>'
        + '<button type="button" class="btn btn-dark px-4">Close</button>';
    overlay.querySelector('.qr-fs-title').textContent = trigger.dataset.qrTitle || '';
    const img = overlay.querySelector('img');
    img.src = trigger.dataset.qrFullscreen;
    img.alt = 'QR code' + (trigger.dataset.qrTitle ? ' for ' + trigger.dataset.qrTitle : '');
    document.body.appendChild(overlay);
    let wake = null;
    if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (l) { wake = l; }).catch(function () {});
    const onKey = function (ev) { if (ev.key === 'Escape') close(); };
    function close() {
        overlay.remove();
        if (wake) wake.release().catch(function () {});
        document.removeEventListener('keydown', onKey);
        trigger.focus();
    }
    overlay.querySelector('button').addEventListener('click', close);
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
    document.addEventListener('keydown', onKey);
    overlay.querySelector('button').focus();
});

// Type-to-filter lists (v49): <input data-filter-input="#list" data-filter-empty="#none" data-filter-count="#count">
// hides the [data-filter-item] children of #list whose text does not contain every typed word.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-filter-input]').forEach(function (input) {
        const list = document.querySelector(input.dataset.filterInput);
        if (!list) return;
        const items = Array.from(list.querySelectorAll('[data-filter-item]'));
        const empty = input.dataset.filterEmpty ? document.querySelector(input.dataset.filterEmpty) : null;
        const count = input.dataset.filterCount ? document.querySelector(input.dataset.filterCount) : null;
        const total = items.length;
        const apply = function () {
            const words = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            let shown = 0;
            items.forEach(function (item) {
                const text = item.dataset.filterItem || item.textContent.toLowerCase();
                const match = words.every(function (w) { return text.indexOf(w) !== -1; });
                item.classList.toggle('d-none', !match);
                if (match) shown++;
            });
            if (empty) empty.classList.toggle('d-none', shown > 0);
            if (count) count.textContent = words.length ? shown + ' of ' + total + ' QR codes' : total + ' QR codes';
        };
        input.addEventListener('input', apply);
        input.addEventListener('search', apply);
    });
});

// File viewer (v51): every link to an uploaded image or PDF (uploads_guard.php) opens inside the
// page with a Back button, instead of a bare file in a new tab. The phone's Back gesture closes it.
// Links with a download attribute, or data-no-viewer, keep their normal behaviour.
(function () {
    const IMG = /\.(png|jpe?g|gif|webp)$/i, PDF = /\.pdf$/i;
    let open = null;
    const fileOf = function (a) {
        try {
            const u = new URL(a.href, location.href);
            if (u.origin !== location.origin || !/uploads_guard\.php$/i.test(u.pathname)) return null;
            const f = u.searchParams.get('f') || '';
            return IMG.test(f) || PDF.test(f) ? { url: u.href, name: f.split('/').pop(), pdf: PDF.test(f) } : null;
        } catch (e) { return null; }
    };
    const titleOf = function (a, file) {
        const t = (a.getAttribute('aria-label') || a.textContent || '').replace(/\s+/g, ' ').trim();
        return t && t.length < 80 ? t.replace(/^(view|open)\s+/i, '') : file.name;
    };
    function close(fromHistory) {
        if (!open) return;
        const o = open;
        open = null;
        o.el.remove();
        document.body.classList.remove('file-viewer-open');
        document.removeEventListener('keydown', o.onKey);
        if (!fromHistory && history.state && history.state.evoraViewer) history.back();
        if (o.trigger && o.trigger.focus) o.trigger.focus();
    }
    function show(file, title, trigger) {
        close(true);
        const el = document.createElement('div');
        el.className = 'file-viewer';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', title);
        el.innerHTML = '<div class="file-viewer-bar">'
            + '<button type="button" class="btn btn-light btn-sm file-viewer-back"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</button>'
            + '<span class="file-viewer-title"></span>'
            + '<a class="btn btn-outline-light btn-sm" target="_blank" rel="noopener" data-no-viewer><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Open</span></a>'
            + '<a class="btn btn-outline-light btn-sm" data-no-viewer download><i class="bi bi-download" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Download</span></a>'
            + '</div><div class="file-viewer-body"></div>';
        el.querySelector('.file-viewer-title').textContent = title;
        const links = el.querySelectorAll('a');
        links[0].href = file.url;
        links[0].setAttribute('aria-label', 'Open in a new tab');
        links[1].href = file.url;
        links[1].setAttribute('download', file.name);
        links[1].setAttribute('aria-label', 'Download');
        const body = el.querySelector('.file-viewer-body');
        if (file.pdf) {
            const frame = document.createElement('iframe');
            frame.src = file.url;
            frame.title = title;
            body.appendChild(frame);
        } else {
            const img = document.createElement('img');
            img.src = file.url;
            img.alt = title;
            img.addEventListener('error', function () { body.innerHTML = '<div class="file-viewer-missing"><i class="bi bi-file-earmark-x" aria-hidden="true"></i><p>This file could not be loaded. It may have been removed.</p></div>'; });
            body.appendChild(img);
            body.addEventListener('click', function (e) { if (e.target === body) close(false); });
        }
        document.body.appendChild(el);
        document.body.classList.add('file-viewer-open');
        const onKey = function (e) { if (e.key === 'Escape') close(false); };
        document.addEventListener('keydown', onKey);
        el.querySelector('.file-viewer-back').addEventListener('click', function () { close(false); });
        open = { el: el, onKey: onKey, trigger: trigger };
        history.pushState({ evoraViewer: 1 }, '');
        el.querySelector('.file-viewer-back').focus();
    }
    window.addEventListener('popstate', function () { close(true); });
    document.addEventListener('click', function (e) {
        const a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (a.hasAttribute('download') || a.hasAttribute('data-no-viewer') || a.closest('.file-viewer')) return;
        const file = fileOf(a);
        if (!file) return;
        e.preventDefault();
        show(file, titleOf(a, file), a);
    });
})();

// Countdown chips (v52): <span data-countdown="2026-10-17T12:57:00"> shows "in 3 days" / "in 5h 20m".
(function () {
    const chips = document.querySelectorAll('[data-countdown]');
    if (!chips.length) return;
    const render = function () {
        chips.forEach(function (chip) {
            const target = new Date(chip.dataset.countdown).getTime();
            if (isNaN(target)) return;
            const mins = Math.round((target - Date.now()) / 60000);
            const text = chip.querySelector('.countdown-text') || chip;
            chip.classList.toggle('is-now', mins <= 0);
            chip.classList.toggle('is-soon', mins > 0 && mins < 24 * 60);
            if (mins <= 0) { text.textContent = 'Happening today'; return; }
            const days = Math.floor(mins / 1440), hours = Math.floor((mins % 1440) / 60), m = mins % 60;
            text.textContent = days >= 2 ? 'in ' + days + ' days' : days === 1 ? 'in 1 day ' + hours + 'h' : hours > 0 ? 'in ' + hours + 'h ' + m + 'm' : 'in ' + m + ' min';
        });
    };
    render();
    setInterval(render, 60000);
})();

// ---------------------------------------------------------------------
// v53: global search (Ctrl+K), unsaved-changes warning, dismissible cards.
// ---------------------------------------------------------------------

// Global search: pages from the sidebar (instant) + events / people from global-search (role-scoped).
(function () {
    const opener = document.querySelector('[data-search-open]');
    if (!opener) return;
    const endpoint = opener.getAttribute('data-search-url');
    const esc = function (s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const pages = Array.from(document.querySelectorAll('.evora-sidebar a.nav-link')).map(function (a) {
        const icon = a.querySelector('i.bi');
        return { title: a.textContent.replace(/\s+/g, ' ').replace(/\s\d+$/, '').trim(), sub: 'Page', url: a.href, icon: icon ? Array.from(icon.classList).find(function (c) { return c.indexOf('bi-') === 0; }) : 'bi-link' };
    }).filter(function (p) { return p.title; });
    let modal = null, input = null, list = null, items = [], active = -1, timer = null, ctrl = null;

    function build() {
        modal = document.createElement('div');
        modal.className = 'gsearch';
        modal.hidden = true;
        modal.innerHTML = '<div class="gsearch-panel" role="dialog" aria-modal="true" aria-label="Search EVORA">'
            + '<div class="gsearch-input"><i class="bi bi-search" aria-hidden="true"></i>'
            + '<input type="search" placeholder="Search pages, events, people…" aria-label="Search" aria-controls="gsearchList" aria-autocomplete="list" autocomplete="off">'
            + '<kbd>Esc</kbd></div>'
            + '<div class="gsearch-results" id="gsearchList" role="listbox"></div>'
            + '<div class="gsearch-foot"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Ctrl</kbd><kbd>K</kbd> search anywhere</span></div></div>';
        document.body.appendChild(modal);
        input = modal.querySelector('input');
        list = modal.querySelector('.gsearch-results');
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 180); renderPages(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!items.length) return;
                setActive((active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (items[active]) location.href = items[active].url;
            } else if (e.key === 'Escape') {
                close();
            }
        });
        list.addEventListener('mousemove', function (e) { const o = e.target.closest('[role=option]'); if (o) setActive(+o.dataset.index); });
    }
    function render(groups, note) {
        items = [];
        let html = '';
        groups.forEach(function (g) {
            if (!g.items.length) return;
            html += '<div class="gsearch-group">' + esc(g.label) + '</div>';
            g.items.forEach(function (it) {
                const i = items.length;
                items.push(it);
                html += '<a class="gsearch-item" role="option" id="gs-opt-' + i + '" data-index="' + i + '" href="' + esc(it.url) + '">'
                    + '<i class="bi ' + esc(it.icon || 'bi-link') + '" aria-hidden="true"></i><span class="gsearch-text"><strong>' + esc(it.title) + '</strong><small>' + esc(it.sub || '') + '</small></span></a>';
            });
        });
        list.innerHTML = html || '<div class="gsearch-empty">' + esc(note || 'No results. Try another word.') + '</div>';
        setActive(items.length ? 0 : -1);
    }
    let serverGroups = [];
    function matchingPages() {
        const words = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
        const found = words.length ? pages.filter(function (p) { const t = p.title.toLowerCase(); return words.every(function (w) { return t.indexOf(w) !== -1; }); }) : pages.slice(0, 8);
        return { label: words.length ? 'Pages' : 'Go to', items: found.slice(0, 6) };
    }
    function renderPages() { render([matchingPages()].concat(input.value.trim().length >= 2 ? serverGroups : []), input.value.trim().length >= 2 ? 'Searching…' : 'Type at least 2 letters.'); }
    function search() {
        const q = input.value.trim();
        if (q.length < 2) { serverGroups = []; renderPages(); return; }
        if (ctrl) ctrl.abort();
        ctrl = window.AbortController ? new AbortController() : null;
        fetch(endpoint + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { return r.ok ? r.json() : { groups: [] }; })
            .then(function (data) { if (input.value.trim() !== q) return; serverGroups = data.groups || []; render([matchingPages()].concat(serverGroups)); })
            .catch(function () {});
    }
    function setActive(i) {
        active = i;
        list.querySelectorAll('[role=option]').forEach(function (o, k) { o.classList.toggle('is-active', k === i); o.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
        if (i >= 0) { input.setAttribute('aria-activedescendant', 'gs-opt-' + i); const el = document.getElementById('gs-opt-' + i); if (el) el.scrollIntoView({ block: 'nearest' }); }
        else input.removeAttribute('aria-activedescendant');
    }
    function open() {
        if (!modal) build();
        modal.hidden = false;
        document.body.classList.add('gsearch-open');
        input.value = '';
        serverGroups = [];
        renderPages();
        setTimeout(function () { input.focus(); }, 0);
    }
    function close() {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        document.body.classList.remove('gsearch-open');
        opener.focus();
    }
    opener.addEventListener('click', open);
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); modal && !modal.hidden ? close() : open(); }
    });
})();

// Unsaved changes: leaving a changed form asks first. Forms with a textarea or 3+ fields are
// watched (opt out with data-no-unsaved); saving the form never asks.
(function () {
    const watched = Array.from(document.querySelectorAll('main form[method=post], main form[method=POST]')).filter(function (f) {
        if (f.hasAttribute('data-no-unsaved') || f.closest('.modal, .dropdown-menu, .evaluation-prompt')) return false;
        const fields = Array.from(f.elements).filter(function (el) { return el.name && !/^(hidden|submit|button)$/.test(el.type) && el.name !== 'csrf_token'; });
        return fields.length >= 3 || f.querySelector('textarea');
    });
    if (!watched.length) return;
    const snap = function (f) {
        return Array.from(f.elements).filter(function (el) { return el.name && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button'; }).map(function (el) {
            if (el.type === 'checkbox' || el.type === 'radio') return el.name + '=' + el.checked;
            if (el.type === 'file') return el.name + '=' + (el.files ? el.files.length : 0);
            return el.name + '=' + el.value;
        }).join('&');
    };
    const initial = new Map(watched.map(function (f) { return [f, snap(f)]; }));
    let leaving = false;
    const dirty = function () { return !leaving && watched.some(function (f) { return snap(f) !== initial.get(f); }); };
    // Any form being sent (normal submit, or form.submit() after a confirm dialog) is not "leaving unsaved".
    document.addEventListener('submit', function (e) { if (!e.defaultPrevented) leaving = true; });
    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () { leaving = true; return nativeSubmit.apply(this, arguments); };
    window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('click', function (e) {
        const a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
        const href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href) || /uploads_guard/.test(a.href) || !dirty()) return;
        e.preventDefault();
        const go = function () { leaving = true; location.href = a.href; };
        if (window.Swal) {
            Swal.fire({ icon: 'warning', title: 'Leave without saving?', text: 'You changed this form but did not save it. Your changes will be lost.',
                showCancelButton: true, confirmButtonText: 'Leave page', cancelButtonText: 'Stay and save', reverseButtons: true, focusCancel: true })
                .then(function (r) { if (r.isConfirmed) go(); });
        } else if (window.confirm('You changed this form but did not save it. Leave this page?')) {
            go();
        }
    }, true);
})();

// Dismissible cards: [data-dismiss-key] hides for this browser after its [data-dismiss] button is used.
document.querySelectorAll('[data-dismiss-key]').forEach(function (card) {
    const key = 'evora_dismiss_' + card.getAttribute('data-dismiss-key');
    try { if (localStorage.getItem(key) === '1') { card.remove(); return; } } catch (e) {}
    const btn = card.querySelector('[data-dismiss]');
    if (btn) btn.addEventListener('click', function () { try { localStorage.setItem(key, '1'); } catch (e) {} card.remove(); });
});

// ---------------------------------------------------------------------
// v54: session timeout warning, live "Today" panel.
// ---------------------------------------------------------------------

// Session timeout warning: 2 minutes before the idle timeout a dialog offers "Stay signed in".
// Working on the page (typing, clicking) counts as activity, so nobody is signed out mid-form.
// Tabs share the last activity time through localStorage.
(function () {
    const body = document.body;
    const timeout = parseInt(body.dataset.sessionTimeout || '0', 10) * 1000;
    if (!timeout || !body.dataset.sessionPing) return;
    const KEY = 'evora_last_activity', WARN = 120000, PING_EVERY = 300000;
    const now = function () { return Date.now(); };
    const loadedAt = now();
    const mark = function (t) { try { localStorage.setItem(KEY, String(t)); } catch (e) {} };
    const last = function () { let v = 0; try { v = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) {} return Math.max(v, loadedAt); };
    mark(loadedAt);
    let lastPing = loadedAt, dialog = null;
    const expire = function () { location.href = body.dataset.sessionLogin; };
    const ping = function () {
        lastPing = now();
        return fetch(body.dataset.sessionPing, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (r.status === 401) { expire(); return; } mark(now()); hide(); })
            .catch(function () {});
    };
    ['keydown', 'click', 'input', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, function () { if (!dialog && now() - lastPing > PING_EVERY) ping(); }, { passive: true, capture: true });
    });
    function hide() { if (dialog) { dialog.remove(); dialog = null; } }
    function show(left) {
        if (!dialog) {
            dialog = document.createElement('div');
            dialog.className = 'session-warning';
            dialog.innerHTML = '<div class="session-warning-box" role="alertdialog" aria-modal="true" aria-labelledby="swTitle" aria-describedby="swText">'
                + '<div class="session-warning-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>'
                + '<h2 id="swTitle" class="h5 fw-bold mb-1">Are you still there?</h2>'
                + '<p id="swText" class="text-muted mb-3">For your security you will be signed out in <strong class="sw-left"></strong> because there was no activity.</p>'
                + '<div class="d-flex gap-2 justify-content-center flex-wrap"><a class="btn btn-outline-secondary" href="' + body.dataset.sessionLogout + '">Sign out now</a>'
                + '<button type="button" class="btn btn-evora-primary" data-sw-stay>Stay signed in</button></div></div>';
            document.body.appendChild(dialog);
            dialog.querySelector('[data-sw-stay]').addEventListener('click', ping);
            dialog.querySelector('[data-sw-stay]').focus();
        }
        const s = Math.ceil(left / 1000);
        dialog.querySelector('.sw-left').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }
    setInterval(function () {
        const left = last() + timeout - now();
        if (left <= 0) expire();
        else if (left <= WARN) show(left);
        else hide();
    }, 1000);
})();

// "Today" panel: refresh the check-in numbers every 30 seconds while the page is visible.
(function () {
    const panel = document.querySelector('[data-today-url]');
    if (!panel) return;
    const refresh = function () {
        if (document.hidden) return;
        fetch(panel.dataset.todayUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.events) return;
                data.events.forEach(function (ev) {
                    const row = panel.querySelector('[data-today-event="' + ev.id + '"]');
                    if (!row) return;
                    const pct = ev.approved > 0 ? Math.min(100, Math.round(ev.checked_in * 100 / ev.approved)) : 0;
                    row.querySelector('[data-today-checked]').textContent = ev.checked_in;
                    row.querySelector('[data-today-waiting]').textContent = Math.max(0, ev.approved - ev.checked_in);
                    row.querySelector('[data-today-total]').textContent = ev.approved;
                    row.querySelector('[data-today-bar]').style.width = pct + '%';
                    row.querySelector('.today-progress').setAttribute('aria-valuenow', pct);
                });
                const u = panel.querySelector('[data-today-updated]');
                if (u && data.updated) u.textContent = data.updated;
            })
            .catch(function () {});
    };
    setInterval(refresh, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();

// =====================================================================
// v64 touch feel: ripple on tap, cards that rise in as you scroll, numbers that count up,
// and a short vibration when a QR code is scanned (Android). All of it is skipped when the
// device asks for reduced motion.
// =====================================================================
(function () {
    'use strict';
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---- haptics: one short buzz per new code; success / problem patterns on the check-in result
    function buzz(pattern) {
        try { if (navigator.vibrate) { navigator.vibrate(pattern); } } catch (e) { /* not supported */ }
    }
    window.evoraHaptic = buzz;
    if (window.Html5Qrcode && !window.Html5Qrcode.prototype.__evoraHaptic) {
        var start = window.Html5Qrcode.prototype.start;
        var lastText = '', lastAt = 0;
        window.Html5Qrcode.prototype.start = function (camera, config, onSuccess, onError) {
            var wrapped = typeof onSuccess === 'function' ? function (text) {
                var now = Date.now();
                if (text !== lastText || now - lastAt > 2500) { buzz(30); }
                lastText = text; lastAt = now;
                return onSuccess.apply(this, arguments);
            } : onSuccess;
            return start.call(this, camera, config, wrapped, onError);
        };
        window.Html5Qrcode.prototype.__evoraHaptic = true;
    }
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (r) {
                var t = r.target;
                if (r.attributeName !== 'class' || !t.classList || !t.classList.contains('scan-result')) { return; }
                if (t.classList.contains('is-success')) { buzz([40, 60, 40]); }
                else if (t.classList.contains('is-danger')) { buzz([120, 80, 120]); }
            });
        }).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class'] });
    }

    // ---- ripple from the exact point that was tapped or clicked
    var RIPPLE = '.btn:not(.btn-link):not(.btn-close), .quick-action, .bottom-nav a, .list-group-item-action, .account-type-option, .event-type-card, .back-link, .evora-sidebar .nav-link';
    document.addEventListener('pointerdown', function (ev) {
        if (ev.button !== 0) { return; }
        var el = ev.target.closest(RIPPLE);
        if (!el || el.disabled || el.classList.contains('disabled')) { return; }
        if (getComputedStyle(el).position === 'static') { el.style.position = 'relative'; }
        var host = el.querySelector(':scope > .ripple-host');
        if (!host) {
            host = document.createElement('span');
            host.className = 'ripple-host';
            host.setAttribute('aria-hidden', 'true');
            el.appendChild(host);
        }
        var box = el.getBoundingClientRect();
        var size = Math.max(box.width, box.height) * 2;
        var dot = document.createElement('span');
        dot.className = 'ripple';
        dot.style.width = dot.style.height = size + 'px';
        dot.style.left = (ev.clientX - box.left - size / 2) + 'px';
        dot.style.top = (ev.clientY - box.top - size / 2) + 'px';
        host.appendChild(dot);
        dot.addEventListener('animationend', function () { dot.remove(); });
    }, { passive: true });

    // Press feedback and ripple stay on with reduced motion (they answer a tap); the rest is decoration.
    if (reduced) { return; }

    // ---- count-up for dashboard numbers
    function countUp(el) {
        var text = el.textContent.trim();
        var m = text.match(/^([^\d-]*)(-?[\d,]*\.?\d+)(.*)$/);
        if (!m) { return; }
        var raw = m[2], target = parseFloat(raw.replace(/,/g, ''));
        if (!isFinite(target) || target === 0) { return; }
        var decimals = (raw.split('.')[1] || '').length, commas = raw.indexOf(',') !== -1 || Math.abs(target) >= 1000;
        var fmt = function (n) {
            return m[1] + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping: commas }) + m[3];
        };
        var t0 = null, dur = 800;
        el.setAttribute('aria-label', text);
        (function step(ts) {
            if (t0 === null) { t0 = ts; }
            var p = Math.min(1, (ts - t0) / dur), eased = 1 - Math.pow(1 - p, 3);
            el.textContent = p < 1 ? fmt(target * eased) : text;
            if (p < 1) { requestAnimationFrame(step); } else { el.removeAttribute('aria-label'); }
        })(performance.now());
    }

    // ---- cards rise in: the first screen one by one, the rest as they scroll into view
    var REVEAL = '.evora-content .card-evora, .evora-content .stat-card, .evora-content .quick-action, .evora-content .event-type-card';
    var items = Array.prototype.filter.call(document.querySelectorAll(REVEAL), function (el) {
        return !el.closest('.modal, .dropdown-menu, .offcanvas, [data-no-reveal]') && el.parentElement.closest(REVEAL) === null;
    });
    if (!('IntersectionObserver' in window) || !items.length) { return; }
    var shown = 0;
    function reveal(el, delay) {
        el.style.animationDelay = delay + 'ms';
        el.classList.remove('reveal-wait');
        el.classList.add('reveal-in');
        el.addEventListener('animationend', function done(e) {
            if (e.target !== el) { return; }
            el.classList.remove('reveal-in');
            el.style.animationDelay = '';
            el.removeEventListener('animationend', done);
        });
        el.querySelectorAll('.stat-value').forEach(function (v) { setTimeout(function () { countUp(v); }, delay); });
    }
    var io = new IntersectionObserver(function (entries) {
        var batch = 0;
        entries.forEach(function (en) {
            if (!en.isIntersecting) { return; }
            io.unobserve(en.target);
            reveal(en.target, Math.min(batch++, 5) * 70);
        });
    }, { rootMargin: '0px 0px -40px 0px', threshold: 0.08 });
    items.forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.top < window.innerHeight && r.bottom > 0) {
            reveal(el, Math.min(shown++, 8) * 60);
        } else {
            el.classList.add('reveal-wait');
            io.observe(el);
        }
    });
})();

// ---- required fields (v79): every required field shows a red * after its label, and a form with
// several required fields explains it. Labels that already show * keep it, restyled the same way.
(function () {
    const MARK = '<span class="req-mark" aria-hidden="true">*</span>';
    const FIELDS = 'input[required], select[required], textarea[required]';
    function labelFor(el) {
        // A radio group is marked once, on its question (legend, or the label just before the group),
        // never on each option such as a star or "Yes".
        if (el.type === 'radio') {
            const set = el.closest('fieldset');
            if (set) return set.querySelector('legend');
            const group = el.closest('[role="radiogroup"], .btn-group');
            const prev = group ? group.previousElementSibling : null;
            return prev && prev.tagName === 'LABEL' ? prev : null;
        }
        if (el.id) {
            const byFor = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
            if (byFor) return byFor;
        }
        const wrapping = el.closest('label');
        if (wrapping) return wrapping;
        const box = el.closest('.form-floating, .form-check, .mb-3, .mb-2, .input-group, [class*="col-"]');
        return box ? box.querySelector('label, legend') : null;
    }
    function mark(el) {
        if (el.type === 'hidden' || el.closest('[data-no-required-mark]')) return;
        const label = labelFor(el);
        if (!label) {
            if (el.type !== 'radio' && el.placeholder && el.placeholder.indexOf('*') === -1) el.placeholder += ' *';
            return;
        }
        if (label.querySelector('.req-mark')) return;
        const red = Array.from(label.querySelectorAll('.text-danger')).find(function (s) { return s.textContent.trim() === '*'; });
        if (red) { red.classList.add('req-mark'); return; }
        const last = label.lastChild;
        if (last && last.nodeType === 3 && /\*\s*$/.test(last.nodeValue)) {
            last.nodeValue = last.nodeValue.replace(/\s*\*\s*$/, '');
        } else if (label.textContent.indexOf('*') !== -1) {
            return;
        }
        label.insertAdjacentHTML('beforeend', ' ' + MARK);
    }
    function run(root) {
        root.querySelectorAll(FIELDS).forEach(mark);
        root.querySelectorAll('form').forEach(function (form) {
            if (form.querySelector('.req-note') || 'noRequiredNote' in form.dataset) return;
            const count = Array.from(form.querySelectorAll(FIELDS)).filter(function (el) { return el.type !== 'hidden' && el.type !== 'checkbox'; }).length;
            if (count < 3 || getComputedStyle(form).display === 'flex' && !form.classList.contains('row')) return;
            form.insertAdjacentHTML('afterbegin', '<p class="req-note' + (form.classList.contains('row') ? ' col-12' : '') + '">' + MARK + ' Required field</p>');
        });
    }
    run(document);
    window.evoraMarkRequired = run;
})();

// ---- review photos (v81): preview the chosen photos and keep at most data-max-files
(function () {
    document.querySelectorAll('input[type="file"][data-max-files]').forEach(function (input) {
        const max = parseInt(input.dataset.maxFiles, 10) || 3;
        const box = document.getElementById('reviewPhotoPreview');
        input.addEventListener('change', function () {
            if (input.files.length > max) {
                const keep = new DataTransfer();
                Array.from(input.files).slice(0, max).forEach(function (f) { keep.items.add(f); });
                input.files = keep.files;
                alert('You can add up to ' + max + ' photos. Only the first ' + max + ' were kept.');
            }
            if (!box) return;
            box.innerHTML = '';
            Array.from(input.files).forEach(function (f) {
                if (!/^image\//.test(f.type)) return;
                const img = document.createElement('img');
                img.alt = f.name;
                img.src = URL.createObjectURL(f);
                box.appendChild(img);
            });
        });
    });
})();
