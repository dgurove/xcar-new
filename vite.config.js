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
        // CSS сжимает Tailwind, а не Vite: lightningcss Vite склеивал списки селекторов в `:is(…)`, а такое правило браузер
        // проверяет на каждом элементе страницы — на «Все» CRM (30 тыс. узлов) это десятки мс на любой пересчёт (29.10.2026).
        tailwindcss({ optimize: { minify: true } }),
        pdfjsAssets,
    ],
    build: { cssMinify: false },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
