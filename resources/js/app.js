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
const navLinks = [...document.querySelectorAll('#site-nav a[href^="#"]')];
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
