import Reveal from 'reveal.js';
import Notes from 'reveal.js/plugin/notes/notes';

// Awards presentation (legacy awards.php). reveal.js 5 initialization
// (hash:true, notes plugin — identical API to legacy 4.1).
Reveal.initialize({
    hash: true,
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