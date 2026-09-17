// Copies Font Awesome's CSS + webfonts to public/vendor/fontawesome so the
// awards presentation (views/awards/show.blade.php) can load the icons from
// the local vendor tree instead of cdnjs — the venue wifi cannot be trusted
// mid-ceremony. Vite bundles the custom awards.css/awards.js; Font Awesome
// stays a static vendor asset because its CSS resolves webfonts by relative
// URL, which a plain <link> preserves. Resolves from node_modules only, so
// `npm run build` stays network-free (same rule as copy-reveal.mjs).
import { cp, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const src = fileURLToPath(new URL('../node_modules/@fortawesome/fontawesome-free', import.meta.url));
const out = fileURLToPath(new URL('../public/vendor/fontawesome', import.meta.url));

await mkdir(path.join(out, 'css'), { recursive: true });

await cp(path.join(src, 'css', 'all.min.css'), path.join(out, 'css', 'all.min.css'));

// all.min.css @font-face URLs point at ../webfonts/; copy the whole tree.
await cp(path.join(src, 'webfonts'), path.join(out, 'webfonts'), {
    recursive: true,
});

console.log('Font Awesome assets copied to public/vendor/fontawesome');
