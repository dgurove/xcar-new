import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { cpSync } from 'node:fs';

// Шрифты, cmaps и wasm pdf.js (шторка документов, resources/js/docs/pdf.js) — рядом со сборкой, /build/pdfjs.
const pdfjsAssets = {
    name: 'pdfjs-assets',
    apply: 'build',
    closeBundle() {
        for (const dir of ['cmaps', 'standard_fonts', 'wasm', 'iccs']) {
            cpSync(`node_modules/pdfjs-dist/${dir}`, `public/build/pdfjs/${dir}`, { recursive: true });
        }
    },
};

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        pdfjsAssets,
    ],
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
