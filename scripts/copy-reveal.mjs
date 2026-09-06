// Copies reveal.js core, themes, and fonts to public/vendor/reveal so the
// awards presentation (views/awards/show.blade.php) can link reveal via a
// plain <link> per-request theme, offline and CSP-friendly. Vite bundles
// the custom awards.css/awards.js; reveal itself stays a static vendor
// asset because the theme must switch at runtime via ?view=.
import { cp, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const dist = fileURLToPath(new URL('../node_modules/reveal.js/dist', import.meta.url));
const out = fileURLToPath(new URL('../public/vendor/reveal', import.meta.url));

await mkdir(path.join(out, 'theme'), { recursive: true });

await cp(path.join(dist, 'reset.css'), path.join(out, 'reset.css'));
await cp(path.join(dist, 'reveal.css'), path.join(out, 'reveal.css'));

for (const theme of ['white.css', 'black.css', 'moon.css']) {
    await cp(path.join(dist, 'theme', theme), path.join(out, 'theme', theme));
}

// Themes @import ./fonts/…; copy the whole fonts tree.
await cp(path.join(dist, 'theme', 'fonts'), path.join(out, 'theme', 'fonts'), {
    recursive: true,
});

console.log('reveal.js assets copied to public/vendor/reveal');