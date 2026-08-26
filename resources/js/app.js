// Mobile navbar: CSS peer-checkbox toggles #nav-menu; close on link click.
const navToggle = document.getElementById('nav-toggle');
if (navToggle) {
    document.querySelectorAll('#nav-menu a').forEach((a) =>
        a.addEventListener('click', () => {
            navToggle.checked = false;
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


// Admin chrome (nav.sec.php semantics without Bootstrap JS):
// top-bar + offcanvas dropdowns toggle on click, close on outside click.
document.querySelectorAll('.dropdown-toggle, .my-dropdown').forEach((toggler) =>
    toggler.addEventListener('click', (e) => {
        e.preventDefault();
        const li = toggler.closest('.dropdown');
        const wasOpen = li.classList.contains('open');
        document.querySelectorAll('.dropdown.open').forEach((d) => d.classList.remove('open'));
        if (!wasOpen) li.classList.add('open');
    }),
);
document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown.open').forEach((d) => d.classList.remove('open'));
    }
});


// Admin dashboard accordion (Bootstrap panel collapse without Bootstrap JS):
// clicking a panel title toggles its body; open one per group (accordion).
document.querySelectorAll('.panel-collapse-toggle').forEach((toggler) =>
    toggler.addEventListener('click', (e) => {
        e.preventDefault();
        const target = document.getElementById(toggler.dataset.target);
        if (!target) return;
        const group = toggler.closest('.panel-group');
        if (group) {
            group.querySelectorAll('.panel-collapse.collapse.in').forEach((open) => {
                if (open !== target) open.classList.remove('in');
            });
        }
        target.classList.toggle('in');
    }),
);

// daisyUI <dialog class="modal">: [data-open-modal="id"] opens, backdrop
// click and [data-close-modal] close (Bootstrap modal semantics without
// Bootstrap JS).
document.querySelectorAll('[data-open-modal]').forEach((btn) =>
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.getElementById(btn.dataset.openModal)?.showModal();
    }),
);
document.querySelectorAll('dialog.modal').forEach((dialog) => {
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) dialog.close();
    });
    dialog.querySelectorAll('[data-close-modal]').forEach((btn) =>
        btn.addEventListener('click', () => dialog.close()),
    );
});

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
        if (el) el.classList.toggle('hidden', !on);
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
                specialInfo.classList.remove('hidden');
            } else {
                specialInfo.classList.add('hidden');
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
