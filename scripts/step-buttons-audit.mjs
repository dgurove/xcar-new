// Главная кнопка шага — самая большая (17.11.2026, владелец: «самая большая кнопка — пропустить письмо, максимально нелогично»;
// главное там — «Спросить менеджера»). Проходит текущий шаг «Хода сделки» у идущих сделок (окно из «Сделок» и страница машины,
// 390 и 1440 px) и пишет шаги, где серая кнопка шире лаймовой или стоит над ней во всю ширину шага.
// Запуск при поднятом `php artisan serve --port=8012` (или PORT=…): node scripts/step-buttons-audit.mjs [номер сделки …]
import { execFileSync } from 'node:child_process';
import { chromium } from '/home/claude/tools/playwright/node_modules/playwright/index.mjs';

const port = process.env.PORT || '8012';
const base = (host) => `http://${host}.xcar.localhost:${port}`;
const only = process.argv.slice(2);
const deals = only.length ? only.map((id) => ({ id })) : JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute',
    'echo json_encode(App\\Offers\\Deal::whereIn("state", ["active", "parallel"])->whereNotNull("buyer_id")->pluck("id")->map(fn ($id) => ["id" => $id])->all());'],
{ encoding: 'utf8' }).trim().split('\n').pop());

const browser = await chromium.launch();
let bad = 0;
for (const width of [390, 1440]) {
    const ctx = await browser.newContext({ viewport: { width, height: 1000 }, serviceWorkers: 'block' });
    const page = await ctx.newPage();
    await page.goto(`${base('xcar')}/dev/login/3`);
    for (const { id } of deals) {
        await page.goto(`${base('crm')}/work/deals?peek=${id}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
        const step = page.locator('#detail .step--current').first();
        if (!(await step.waitFor({ timeout: 8000 }).then(() => true, () => false))) continue;
        await page.waitForTimeout(300);
        const found = await step.evaluate((el) => {
            const btns = [...el.querySelectorAll('.btn')].filter((b) => b.offsetParent && !b.closest('dialog, .menu, .sheet'));
            const box = (b) => b.getBoundingClientRect();
            const main = btns.find((b) => b.classList.contains('btn-accent'));
            if (!main) return [];
            const m = box(main);
            const out = [];
            for (const b of btns) {
                if (b === main || b.classList.contains('btn-accent')) continue;
                const r = box(b);
                if (r.width > m.width + 1) out.push(`«${b.textContent.trim()}» шире «${main.textContent.trim()}» (${Math.round(r.width)} > ${Math.round(m.width)})`);
                else if (r.bottom <= m.top && r.width >= el.getBoundingClientRect().width * 0.8) out.push(`«${b.textContent.trim()}» во всю ширину над «${main.textContent.trim()}»`);
            }
            return out;
        });
        for (const f of found) {
            bad++;
            console.log(`сделка ${id}, ${width}px: ${f}`);
        }
    }
    await ctx.close();
}
await browser.close();
console.log(bad ? `Нарушений: ${bad}` : 'Нарушений нет');
process.exit(bad ? 1 : 0);
