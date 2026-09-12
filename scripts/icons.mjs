// Иконки PWA из favicon.svg на три поверхности: 192, 512, maskable 512 (с полем), apple-touch 180, favicon.svg.
// site — знак на тёмном; crm — знак на лайме; park — тёмный с лаймовой полосой снизу.
// node scripts/icons.mjs — один раз, результат лежит в git.
import sharp from 'sharp';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const base = readFileSync(new URL('../public/images/favicon.svg', import.meta.url), 'utf8');
const DARK = '#121212';
const LIME = '#97BF0D';

const variants = {
    site: { svg: base, bg: DARK },
    crm: { svg: base.replaceAll(`fill="${DARK}"`, 'fill="__BG__"').replaceAll(`fill="${LIME}"`, `fill="${DARK}"`).replaceAll('fill="__BG__"', `fill="${LIME}"`), bg: LIME },
    park: { svg: base.replace('</svg>', `<rect y="904" width="1024" height="120" fill="${LIME}"/></svg>`), bg: DARK },
};

for (const [name, { svg, bg }] of Object.entries(variants)) {
    const dir = new URL(`../public/pwa/${name}/`, import.meta.url);
    mkdirSync(dir, { recursive: true });
    const out = (file) => new URL(file, dir).pathname;
    const buf = Buffer.from(svg);
    writeFileSync(out('favicon.svg'), svg);
    for (const size of [192, 512]) {
        await sharp(buf).resize(size, size).png().toFile(out(`icon-${size}.png`));
    }
    await sharp(buf).resize(180, 180).png().toFile(out('apple-touch-icon.png'));
    // Maskable: содержимое в безопасной зоне 80% по центру на фоне.
    const inner = await sharp(buf).resize(410, 410).png().toBuffer();
    await sharp({ create: { width: 512, height: 512, channels: 4, background: bg } })
        .composite([{ input: inner, gravity: 'centre' }]).png().toFile(out('icon-maskable-512.png'));
}
console.log('иконки готовы');
