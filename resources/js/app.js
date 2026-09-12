import * as bootstrap from 'bootstrap/dist/js/bootstrap.bundle.js';

// Mark the document as JS-enabled. CSS scopes JS-only states (the
// .reveal-element hidden state) under this class, so content is visible by
// default whenever the bundle fails to load or JS is disabled.
document.documentElement.classList.add('js');

// The BS5 UMD attaches to module.exports under Vite (CJS branch), so it never
// reaches window.bootstrap — expose it explicitly for callers that use
// bootstrap.Modal/.Offcanvas programmatically (session modals, login reopen).
window.bootstrap = bootstrap.default ?? bootstrap;

// Logout is a POST route (CSRF-protected). window.location.replace() sends a
// GET, which the router rejects with 405 after an auto-logout. Build and submit
// a real POST form instead — shared by the countdown fallback and the admin
// session modals.
window.bcoemLogout = (url) => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = url || '/logout';
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_token';
        input.value = token;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
};

// Mobile navbar: BS5 collapse toggles #nav-menu; close on link click.
const navMenu = document.getElementById('nav-menu');
if (navMenu) {
    document.querySelectorAll('#nav-menu a').forEach((a) =>
        a.addEventListener('click', () => {
            // No-op on desktop (navbar-expand-md forces it open); hides the
            // mobile collapse after tapping a destination link.
            bootstrap.Collapse.getOrCreateInstance(navMenu).hide();
        }),
    );
}

// Scrollspy: highlight the nav link of the section currently in view.
const navLinks = [...document.querySelectorAll('#site-nav a[href^="#"]:not([href="#"])')];

const spyTargets = navLinks
    .map((l) => document.querySelector(l.getAttribute('href')))
    .filter(Boolean);
if ('IntersectionObserver' in window && spyTargets.length > 0) {
    const spy = new IntersectionObserver(
        (entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) {
                    navLinks.forEach((l) =>
                        l.classList.toggle(
                            'active',
                            l.getAttribute('href') === `#${e.target.id}`,
                        ),
                    );
                }
            });
        },
        { rootMargin: '-40% 0px -55% 0px' },
    );
    spyTargets.forEach((t) => spy.observe(t));
}

// Reveal elements: fade sections in as they enter the viewport
// (legacy invoke.js behavior).
const revealables = document.querySelectorAll('.reveal-element');
if ('IntersectionObserver' in window && revealables.length > 0) {
    const revealer = new IntersectionObserver(
        (entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) {
                    e.target.classList.add('active-element');
                    revealer.unobserve(e.target);
                }
            });
        },
        { threshold: 0.1 },
    );
    revealables.forEach((el) => revealer.observe(el));
} else {
    revealables.forEach((el) => el.classList.add('active-element'));
}

// Auto-logout countdown resync (legacy js_includes/autologout.min.js
// heartbeat() → ajax/heartbeat.ajax.php). Genuine activity that never causes
// a page load (DataTables filtering/sorting, type-ahead) still refreshes the
// framework session server-side, so the visible countdowns re-read the
// server's expiry instead of running against a session that was extended.
// Sent on click/keydown, throttled to one request a minute; the server, not
// this file, knows the effective timeout (preferences.prefsSessionTimeout,
// else config('session.lifetime')).
const sessionResyncHandlers = [];
let heartbeatLastSent = 0;
const sessionHeartbeat = (url) => {
    if (!url) return;
    const now = Date.now();
    if (now - heartbeatLastSent < 60000) return;
    heartbeatLastSent = now;
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
            if (!data || data.status !== '1') return;
            sessionResyncHandlers.forEach((fn) => fn(Number(data.session_end_seconds)));
        })
        .catch(() => {});
};

// Public-nav "Auto Log Out in <mm:ss>" countdown (legacy nav.pub.php
// session-end). Ticks the seconds span in the logged-in user dropdown from
// the session lifetime and auto-logs-out (submits the navbar logout form)
// when it reaches zero. Admin pages use the session-expiry modal instead.
(() => {
    const span = document.getElementById('session-end');
    const total = Number(span?.dataset?.sessionEndSeconds ?? NaN);
    if (!span || !Number.isFinite(total)) return;

    const pad = (n) => String(n).padStart(2, '0');
    let remaining = total;
    // Legacy session-end format (eval_warnings.pub.php): > 1440 min →
    // D:HH:MM:SS, > 60 min → H:MM:SS, else MM:SS — so 120 min reads
    // "1:59:30", not the ambiguous "119:30".
    const fmt = () => {
        const s = remaining;
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (s > 86400) {
            return Math.floor(s / 86400) + ':' + pad(h) + ':' + pad(m) + ':' + pad(sec);
        }
        if (h > 0) {
            return h + ':' + pad(m) + ':' + pad(sec);
        }
        return m + ':' + pad(sec);
    };
    const render = () => {
        span.textContent = fmt();
        if (remaining <= 0) {
            clearInterval(timer);
            const btn = [...document.querySelectorAll('#nav-menu form button')]
                .find((b) => (b.closest('form')?.getAttribute('action') || '').includes('logout'));
            if (btn) {
                btn.click();
            } else {
                window.bcoemLogout('/logout');
            }
        }
    };
    // Activity heartbeat: restart the countdown from the server's expiry.
    sessionResyncHandlers.push((endSeconds) => {
        remaining = Math.max(0, endSeconds - Math.floor(Date.now() / 1000));
        render();
    });
    render();
    const timer = setInterval(() => {
        remaining -= 1;
        render();
    }, 1000);
})();

// Entry form (pub/brew.pub.php + js_includes/entry.min.js): show/hide the
// required-info, optional-info, carbonation, sweetness (mead vs cider),
// strength, and pouring fieldsets based on the selected style. Flag data
// comes from #style-flag-map JSON emitted by brew/_fields.blade.php.
(() => {
    const styleSelect = document.getElementById('brewStyle');
    if (!styleSelect) return;

    const flagMap = JSON.parse(document.getElementById('style-flag-map').textContent);
    const optionalStyles = JSON.parse(document.getElementById('optional-info-styles').textContent);

    const setOn = (id, on) => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('d-none', !on);
    };
    const requireRadios = (name, on) => document.querySelectorAll(`input[name="${name}"]`)
        .forEach((input) => { input.required = on; });

    const apply = () => {
        const flags = flagMap[styleSelect.value];
        const code = styleSelect.value;
        setOn('req-special', !!flags?.reqSpec);
        setOn('req-strength', !!flags?.strength);
        setOn('req-carbonation', !!flags?.carb);
        setOn('req-sweetness', !!flags?.sweet);
        setOn('special', !!flags?.reqSpec);
        if (document.getElementById('brewInfo')) document.getElementById('brewInfo').required = !!flags?.reqSpec;
        // Style-specific entry text (#specialInfo).
        const specialInfo = document.getElementById('specialInfo');
        if (specialInfo) {
            if (flags && flags.entry) {
                document.getElementById('specialInfoText').textContent = flags.entry;
                specialInfo.classList.remove('d-none');
            } else {
                specialInfo.classList.add('d-none');
            }
        }
        const optional = optionalStyles.includes(code);
        setOn('optional', optional);
        // Cider styles start with C, mead with M (entry.min.js disp_sweetness).
        const isCider = !!flags?.sweet && (code.startsWith('C') || flags.type === '2');
        const isMead = !!flags?.sweet && (code.startsWith('M') || flags.type === '3');
        setOn('sweetness-cider', isCider);
        setOn('sweetness-mead', isMead);
        requireRadios('brewMead2-cider', isCider);
        requireRadios('brewMead2-mead', isMead);
        setOn('carbonation', !!flags?.carb);
        requireRadios('brewMead1', !!flags?.carb);
        setOn('strength', !!flags?.strength);
        requireRadios('brewMead3', !!flags?.strength);
        setOn('specify-pouring', !!flags && flags.type === '1');
    };

    styleSelect.addEventListener('change', apply);
    apply();

    // Character counters (prefsSpecialCharLimit help blocks).
    [['brewInfo', 'countInfo'], ['brewInfoOptional', 'countInfoOptional'], ['brewComments', 'countComments']]
        .forEach(([field, counter]) => {
            const input = document.getElementById(field);
            const target = document.getElementById(counter);
            if (!input || !target) return;
            input.addEventListener('input', () => { target.textContent = input.value.length; });
        });
})();

// Admin Essentials offcanvas is driven by Bootstrap 5 data-api
// (data-bs-toggle="offcanvas" on the trigger, data-bs-dismiss on close).

// Modern admin date picker (flatpickr, loaded via CDN on the admin layout head
// only — public pages neither load flatpickr nor render these inputs). Matches
// the value the controller pre-renders (AllDatesController::edit -> DateFmt):
// prefsTimeFormat 1 => 24h "Y-m-d H:i"; otherwise 12h "Y-m-d h:i K". Both are
// parsed by AllDatesController::toUtcEpoch (PHP DateTimeImmutable). allowInput
// keeps legacy type-to-edit; altInput stays OFF so the posted value IS the input.
const dateTimeInputs = document.querySelectorAll('.date-time-picker-system');
if (dateTimeInputs.length > 0 && window.flatpickr) {
    const pickerForm = dateTimeInputs[0].closest('form');
    // data-time-24hr (dashed) => dataset["time-24hr"], not .time24hr.
    const time24hr = pickerForm?.dataset['time-24hr'] === '1';
    const dateFormat = time24hr ? 'Y-m-d H:i' : 'Y-m-d h:i K';
    dateTimeInputs.forEach((el) =>
        window.flatpickr(el, {
            enableTime: true,
            dateFormat,
            time_24hr: time24hr,
            allowInput: true,
            altInput: false,
        }),
    );
}

// Admin session-expiry modals + auto-logout. Port of legacy
// js_includes/autologout.min.js (index.legacy.php:302-311 wires session_end_*
// globals); shown via Bootstrap 5's Modal API (issue 8 — the modals are BS5
// markup; bootstrap.Modal is the bundled BS5 global).
if (window.bcoemAdminSession) {
    const { redirect } = window.bcoemAdminSession;
    let expiryShown = null;
    const tick = () => {
        const remaining = window.bcoemAdminSession.endSeconds - Math.floor(Date.now() / 1000);
        if (remaining <= 0) {
            window.bcoemLogout(redirect);
            return;
        }
        if (remaining <= 30 && expiryShown !== 30) {
            if (expiryShown === 120) {
                bootstrap.Modal.getOrCreateInstance('#session-expire-warning').hide();
            }
            bootstrap.Modal.getOrCreateInstance('#session-expire-warning-30').show();
            expiryShown = 30;
        } else if (remaining <= 120 && expiryShown !== 120) {
            bootstrap.Modal.getOrCreateInstance('#session-expire-warning').show();
            expiryShown = 120;
        }
    };
    setInterval(tick, 1000);
    // Activity heartbeat: server expiry wins, and a fresh expiry re-arms
    // whichever warning has already fired.
    sessionResyncHandlers.push((endSeconds) => {
        window.bcoemAdminSession.endSeconds = endSeconds;
        expiryShown = null;
    });
}

// Heartbeat triggers: any click or keydown counts as activity (legacy
// autologout.min.js); the throttle in sessionHeartbeat keeps it to one
// request a minute. Bound once — both countdowns share the single heartbeat.
const heartbeatUrl = window.bcoemAdminSession?.heartbeatUrl
    ?? document.getElementById('session-end')?.dataset.sessionHeartbeatUrl;
if (sessionResyncHandlers.length > 0 && heartbeatUrl) {
    document.addEventListener('click', () => sessionHeartbeat(heartbeatUrl), true);
    document.addEventListener('keydown', () => sessionHeartbeat(heartbeatUrl), true);
}
// ── Tooltips (INTERACTION-PARITY; legacy $('[data-toggle="tooltip"]').tooltip()).
// Bootstrap JS is loaded on admin pages only; a CSS tooltip works on every
// surface (public + admin). Title-bearing [data-toggle=tooltip] /
// [data-tooltip=true] and migrated [data-bs-toggle="tooltip"] elements get a
// .bcoem-tooltip on hover/focus.
document.querySelectorAll('[data-toggle="tooltip"], [data-tooltip="true"], [data-bs-toggle="tooltip"]').forEach((el) => {
    const title = el.getAttribute('title') || el.getAttribute('data-original-title');
    if (!title || el.getAttribute('data-bcoem-tooltip')) return;
    el.setAttribute('data-bcoem-tooltip', '1');
    el.setAttribute('tabindex', '0');
    el.setAttribute('aria-label', title);
    const tip = document.createElement('span');
    tip.className = 'bcoem-tooltip';
    tip.textContent = title;
    el.appendChild(tip);
});

// ── Loader overlay (INTERACTION-PARITY; legacy #loader-submit). .hide-loader
// links/buttons show a brief overlay before navigating; #loader-submit element
// (if present) is toggled. Public pages have no #loader-submit, so a fixed
// overlay is created on demand.
if (!document.getElementById('loader-submit')) {
    const loader = document.createElement('div');
    loader.id = 'loader-submit';
    loader.className = 'loader-submit';
    loader.setAttribute('aria-hidden', 'true');
    document.body.appendChild(loader);
}
const loaderEl = document.getElementById('loader-submit');
document.querySelectorAll('.hide-loader').forEach((el) => {
    if (el.getAttribute('data-hide-loader')) return;
    el.setAttribute('data-hide-loader', '1');
    el.addEventListener('click', () => {
        if (el.getAttribute('target') === '_blank') return;
        loaderEl.classList.add('show');
    });
});

// ── Sticky back-to-top (INTERACTION-PARITY; legacy #sticky-home). A fixed
// "back to top" link appears after scrolling past the hero.
if (!document.getElementById('sticky-home')) {
    const sticky = document.createElement('a');
    sticky.id = 'sticky-home';
    sticky.className = 'sticky-home';
    sticky.href = '#top';
    sticky.setAttribute('aria-label', 'Back to top');
    sticky.innerHTML = '<i class="fa fa-chevron-up"></i>';
    document.body.appendChild(sticky);
}
const stickyHome = document.getElementById('sticky-home');
window.addEventListener('scroll', () => {
    stickyHome.classList.toggle('show', window.scrollY > window.innerHeight);
}, { passive: true });
// ── DataTables parity (PARITY-023): client-side column sort + pagination
// for <table data-dt>. Legacy uses the DataTables jQuery plugin on admin
// participants/entries/styles/BOS + public winners surfaces; this is a
// dependency-free equivalent: click a header to sort (toggle asc/desc),
// a pager footer splits long tables at data-dt-page rows (default 25).
// Opt-in via the attribute so server-rendered tables stay untouched.
document.querySelectorAll('table[data-dt]').forEach((table) => {
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    if (!thead || !tbody) return;
    const rows = [...tbody.querySelectorAll('tr')];
    if (rows.length <= 1) return;
    const pageSize = parseInt(table.getAttribute('data-dt-page') || '25', 10);

    let sortCol = -1;
    let sortDir = 1;
    let page = 0;

    const render = () => {
        const start = page * pageSize;
        rows.forEach((row, i) => {
            row.style.display = (i >= start && i < start + pageSize) ? '' : 'none';
        });
        const pager = table.nextElementSibling;
        if (!pager?.classList.contains('data-dt-pager')) return;
        const pages = Math.max(1, Math.ceil(rows.length / pageSize));
        pager.innerHTML = '';
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.textContent = '‹';
        prev.disabled = page === 0;
        prev.addEventListener('click', () => { if (page > 0) { page--; render(); } });
        pager.appendChild(prev);
        const info = document.createElement('span');
        info.className = 'data-dt-info';
        info.textContent = `${start + 1}–${Math.min(start + pageSize, rows.length)} of ${rows.length}`;
        pager.appendChild(info);
        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = '›';
        next.disabled = page >= pages - 1;
        next.addEventListener('click', () => { if (page < pages - 1) { page++; render(); } });
        pager.appendChild(next);
    };

    [...thead.querySelectorAll('th')].forEach((th, col) => {
        if (th.getAttribute('data-no-sort') !== null) return;
        th.classList.add('data-dt-sortable');
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            if (sortCol === col) sortDir *= -1;
            else { sortCol = col; sortDir = 1; }
            const colRows = rows.filter((r) => r.cells[col]);
            const get = (r) => (r.cells[col].textContent || '').trim().toLowerCase();
            colRows.sort((a, b) => {
                const av = get(a);
                const bv = get(b);
                const an = parseFloat(av);
                const bn = parseFloat(bv);
                if (!isNaN(an) && !isNaN(bn)) return (an - bn) * sortDir;
                return av.localeCompare(bv) * sortDir;
            });
            rows.sort((a, b) => {
                const ia = colRows.indexOf(a);
                const ib = colRows.indexOf(b);
                return (ia === -1 ? 1e9 : ia) - (ib === -1 ? 1e9 : ib);
            });
            rows.forEach((r) => tbody.appendChild(r));
            page = 0;
            render();
        });
    });

    if (rows.length > pageSize) {
        const pager = document.createElement('div');
        pager.className = 'data-dt-pager';
        pager.setAttribute('aria-label', 'Table pagination');
        table.after(pager);
    }
    render();
});
