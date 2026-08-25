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

// daisyUI <dialog> modals: open via data-open-modal="modal-id".
document.querySelectorAll('[data-open-modal]').forEach((btn) =>
    btn.addEventListener('click', () => {
        const modal = document.getElementById(btn.dataset.openModal);
        if (modal && typeof modal.showModal === 'function') modal.showModal();
    }),
);
