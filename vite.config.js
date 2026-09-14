import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fontsource } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/awards.css', 'resources/js/awards.js'],
            refresh: true,
            fonts: [
                // fontsource resolves the .woff2 files from node_modules, so
                // `npm run build` never touches the network. The bunny()/google()
                // providers fetch the font CSS + files at build time, which made
                // a release fail on a bad network moment.
                fontsource('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
