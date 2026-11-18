// Снимки экранов сайта для сверки «было / стало» (18.11.2026, перестройка навигации и вида xcar.ru): один человек,
// одна ширина, список адресов — по снимку на адрес в `storage/app/shots-vid/<OUT>/`.
//
// Запуск при своём сервере (`php artisan serve --port=8017 --no-reload`):
//   USER_ID=6 OUT=now W=390 PATHS=/offers,/deals node scripts/site-shots.mjs
// W=390 — телефон (iPhone, касания), иначе окно такой ширины. FULL=1 — страница целиком, а не экран.
// KEEP=1 — не закрывать шторки, что выезжают сами (вопрос менеджеру, уведомления). HOST=crm — другой хост.
// TAP="селектор" — нажать перед снимком (открыть чат, шторку); DARK=1 — тёмная тема; NAME — приставка имени файла.
import { mkdirSync } from 'node:fs';
const { chromium, devices } = await import(process.env.PLAYWRIGHT || '/home/claude/tools/playwright/node_modules/playwright/index.mjs');
const port = process.env.PORT || '8017';
const host = process.env.HOST || 'site';
const base = host === 'site' ? `http://xcar.localhost:${port}` : `http://${host}.xcar.localhost:${port}`;
const user = Number(process.env.USER_ID || 6);
const width = Number(process.env.W || 390);
const phone = width < 700;
const out = `storage/app/shots-vid/${process.env.OUT || 'now'}`;
mkdirSync(out, { recursive: true });
const paths = (process.env.PATHS || '/offers').split(',').map((p) => p.trim()).filter(Boolean);
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
const ctx = await browser.newContext(phone
    ? { ...devices['iPhone 14'], viewport: { width, height: 844 }, deviceScaleFactor: 2, serviceWorkers: 'block', colorScheme: process.env.DARK ? 'dark' : 'light' }
    : { viewport: { width, height: 900 }, deviceScaleFactor: 1, serviceWorkers: 'block', colorScheme: process.env.DARK ? 'dark' : 'light' });
const page = await ctx.newPage();
page.on('pageerror', (e) => console.log('  ! ошибка страницы:', e.message.slice(0, 160)));
await page.goto(`http://xcar.localhost:${port}/dev/login/${user}`);
const slug = (p) => (p.replace(/^\//, '').replace(/[/?=&]+/g, '-').replace(/-+$/, '') || 'root');
for (const path of paths) {
    const response = await page.goto(base + path, { waitUntil: 'load' }).catch((e) => { console.log('  ! не открылся', path, e.message.slice(0, 80)); return null; });
    await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
    // Вопрос менеджеру выезжает через 1,2 с, шторки Telegram и уведомлений — следом.
    await page.waitForTimeout(process.env.KEEP ? 2200 : 1700);
    if (!process.env.KEEP) {
        await page.evaluate(() => document.querySelectorAll('dialog[open]').forEach((d) => { try { d.close(); } catch {} }));
        await page.waitForTimeout(350);
    }
    if (process.env.TAP) {
        const target = page.locator(process.env.TAP).first();
        if (await target.count()) { if (phone) await target.tap().catch(() => {}); else await target.click().catch(() => {}); await page.waitForTimeout(1200); }
    }
    const file = `${out}/${process.env.NAME ? process.env.NAME + '-' : ''}u${user}-${width}-${slug(path)}.png`;
    await page.screenshot({ path: file, fullPage: !!process.env.FULL });
    console.log(response?.status() ?? '—', path, '→', file);
}
await browser.close();
