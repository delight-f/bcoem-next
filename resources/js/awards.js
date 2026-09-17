import Reveal from 'reveal.js';
// v5 ESM notes entry (the bare `reveal.js/plugin/notes` path does not
// resolve — no exports map / index.js); avoids the UMD commonjs interop.
import Notes from 'reveal.js/plugin/notes/notes.esm.js';

// Awards presentation (legacy awards.php). reveal.js 5 initialization
// (hash:true, notes plugin — identical API to legacy 4.1). slideNumber
// gives the operator a "current/total" position readout.
Reveal.initialize({
    hash: true,
    slideNumber: 'c/t',
    plugins: [Notes],
});

// Scoring-methodology <dialog> (legacy #scoring-method, formerly fancybox).
// Opened by the [Scoring Methodology] links on the Best Brewer/Club slides.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-scoring-method]');
    if (trigger) {
        event.preventDefault();
        const dialog = document.getElementById('scoring-method');
        if (dialog) {
            dialog.showModal();
        }
    }
});