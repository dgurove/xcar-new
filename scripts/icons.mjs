// Иконки PWA из favicon.svg: 192, 512, maskable 512 (с полем), apple-touch 180.
// node scripts/icons.mjs — один раз, результат лежит в git.
import sharp from 'sharp';
import { readFileSync } from 'node:fs';

const svg = readFileSync(new URL('../public/images/favicon.svg', import.meta.url));
const out = (name) => new URL(`../public/pwa/${name}`, import.meta.url).pathname;

for (const size of [192, 512]) {
    await sharp(svg).resize(size, size).png().toFile(out(`icon-${size}.png`));
}
await sharp(svg).resize(180, 180).png().toFile(out('apple-touch-icon.png'));
// Maskable: содержимое в безопасной зоне 80% по центру на фоне хрома.
const inner = await sharp(svg).resize(410, 410).png().toBuffer();
await sharp({ create: { width: 512, height: 512, channels: 4, background: '#121212' } })
    .composite([{ input: inner, gravity: 'centre' }]).png().toFile(out('icon-maskable-512.png'));
console.log('иконки готовы');
