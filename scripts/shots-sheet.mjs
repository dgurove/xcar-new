// Лист сравнения из снимков (`scripts/site-shots.mjs`): несколько картинок в ряд с подписями — одной картинкой владельцу.
//   node scripts/shots-sheet.mjs out.png "Было=путь.png" "Стало=путь.png" [...]      H=высота ряда, px (по умолчанию 760)
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT || '/home/claude/tools/playwright/node_modules/playwright/index.mjs');
const [out, ...items] = process.argv.slice(2);
const h = Number(process.env.H || 760);
const cells = items.map((item) => { const i = item.indexOf('='); return [item.slice(0, i), item.slice(i + 1)]; })
    .map(([label, file]) => `<figure><figcaption>${label}</figcaption><img src="data:image/png;base64,${readFileSync(file).toString('base64')}"></figure>`).join('');
const html = `<meta charset="utf-8"><style>body{margin:0;padding:20px;background:#e9e9e6;font:500 22px/1.2 system-ui,sans-serif;color:#1d1d1b;display:inline-flex;gap:20px;align-items:flex-start}
figure{margin:0}figcaption{margin:0 0 10px 4px}img{display:block;height:${h}px;border-radius:14px;box-shadow:0 1px 4px rgb(0 0 0/.18)}</style>${cells}`;
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
const page = await browser.newPage({ viewport: { width: 2400, height: 1200 }, deviceScaleFactor: 1 });
await page.setContent(html, { waitUntil: 'load' });
const box = await page.evaluate(() => { const r = document.body.getBoundingClientRect(); return { w: Math.ceil(r.width), h: Math.ceil(r.height) }; });
await page.screenshot({ path: out, clip: { x: 0, y: 0, width: box.w, height: box.h } });
await browser.close();
console.log(out, box.w, box.h);
