// Сторож таблиц глазами (30.10.2026, владелец: «почему ты не можешь хоть раз взглянуть, что получилось»): каждый список
// из реестра `lists:check` открывается в браузере на ширинах ПК — с закрытой и с открытой карточкой строки — и
// проверяется то, что видит человек:
//   · строка на ПК в одну линию (не в два-три этажа);
//   · «…» только когда места правда нет: текст режется, а другие столбцы во всех строках недобирают 3rem и больше — ошибка;
//   · шапка столбца не режется; таблица не листается вбок (кроме подробной `is-wide`).
// Снимок каждого нарушения — в OUT. Запуск: поднятый `php artisan serve --port=8012`, затем
//   node scripts/table-audit.mjs [часть адреса]      (W=1280,1512,1728 PORT=8012 OUT=/tmp/table-audit)
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

const { chromium } = await import(process.env.PLAYWRIGHT || '/home/claude/tools/playwright/node_modules/playwright/index.mjs');
const port = process.env.PORT || '8012';
const widths = (process.env.W || '1280,1512,1728').split(',').map(Number);
const out = process.env.OUT || '/tmp/table-audit';
const only = process.argv[2] || '';
mkdirSync(out, { recursive: true });

const lists = execFileSync('php', ['artisan', 'lists:check', '--paths', ...(only ? [`--only=${only}`] : [])], { encoding: 'utf8' })
  .trim().split('\n').filter(Boolean).map(l => l.split('|'));
const host = s => s === 'site' ? 'xcar.localhost' : `${s}.xcar.localhost`;

// Что не так в видимых строках таблицы: считается в странице.
function inspect() {
  const wrap = document.querySelector('.table-wrap');
  if (!wrap) return null;
  const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
  const wide = wrap.classList.contains('is-wide');
  const problems = [];
  const box = wrap.querySelector('.table-box');
  if (!wide && box && box.scrollWidth > box.clientWidth + 1) problems.push(`листается вбок на ${box.scrollWidth - box.clientWidth} px`);
  for (const th of wrap.querySelectorAll('thead th')) {
    if (th.offsetWidth && th.scrollWidth > th.clientWidth + 1) problems.push(`шапка «${th.textContent.trim()}» режется`);
  }
  // Режется — содержимое заходит за правый край коробки (по самим буквам и значкам: хвостовой `margin` значка раздувает
  // scrollWidth, а на экране ничего не режет).
  const clipped = el => {
    if (!el.offsetWidth || el.scrollWidth <= el.clientWidth + 1 || getComputedStyle(el).textOverflow !== 'ellipsis') return false;
    const r = document.createRange(); r.selectNodeContents(el);
    const edge = el.getBoundingClientRect().right - parseFloat(getComputedStyle(el).paddingRight);
    return r.getBoundingClientRect().right > edge + 1;
  };
  // Пустое место ячейки: ширина без полей минус то, что в ней нарисовано.
  const free = td => {
    const cs = getComputedStyle(td);
    const inner = td.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    const r = document.createRange(); r.selectNodeContents(td);
    const used = td.textContent.trim() || td.querySelector('svg, img') ? r.getBoundingClientRect().width : 0;
    return Math.max(inner - used, 0);
  };
  const rows = [...wrap.querySelectorAll('tbody tr[data-detail-key]')].filter(tr => { const r = tr.getBoundingClientRect(); return r.height && r.top < innerHeight && r.bottom > 0; }).slice(0, 40);
  const tall = rows.filter(tr => tr.getBoundingClientRect().height > 2.25 * rem);
  if (tall.length) problems.push(`строк в несколько этажей: ${tall.length} из ${rows.length} (${Math.round(tall[0].getBoundingClientRect().height)} px)`);
  // Свободное место столбца — сколько в нём пустует во всех строках сразу (ширину столбца задаёт самое длинное значение,
  // пустота одной строки места не даёт); отнять его у столбцов без потери можно — это и есть «куча места».
  // Шапка тоже занимает столбец: пустой столбец шириной в подпись не «пустует».
  const cols = new Map();
  const heads = [...wrap.querySelectorAll('thead th')];
  for (const tr of rows) [...tr.children].forEach((td, i) => {
    if (td.offsetWidth) cols.set(i, Math.min(cols.get(i) ?? Infinity, free(td), heads[i]?.offsetWidth ? free(heads[i]) : Infinity));
  });
  const cut = new Map();
  for (const tr of rows) {
    [...tr.children].forEach((td, i) => {
      if (!td.offsetWidth) return;
      const victims = [td, ...td.querySelectorAll('*')].filter(clipped);
      if (!victims.length) return;
      const room = [...cols].reduce((s, [j, v]) => s + (j === i ? 0 : v), 0);
      if (room >= 3 * rem) {
        const head = wrap.querySelectorAll('thead th')[i]?.textContent.trim() || `#${i}`;
        const was = cut.get(head) || { n: 0, room: 0, text: victims[0].textContent.replace(/\s+/g, ' ').trim().slice(0, 40) };
        cut.set(head, { ...was, n: was.n + 1, room: Math.max(was.room, Math.round(room)) });
      }
    });
  }
  for (const [head, c] of cut) problems.push(`«${head}» режется, а в других столбцах пустует ${c.room} px: ${c.n} строк, напр. «${c.text}»`);
  return { problems, rows: rows.length };
}

const b = await chromium.launch({ executablePath: process.env.CHROME_PATH });
let bad = 0, total = 0, shot = 0;
const byUser = Map.groupBy(lists, ([, , user]) => user);
for (const [user, items] of byUser) {
  const ctx = await b.newContext({ viewport: { width: widths[0], height: 900 }, serviceWorkers: 'block' });
  const pg = await ctx.newPage();
  await pg.goto(`http://xcar.localhost:${port}/dev/login/${user}`, { waitUntil: 'domcontentloaded' });
  for (const [surface, path] of items) {
    for (const w of widths) {
      await pg.setViewportSize({ width: w, height: 900 });
      for (const open of [false, true]) {
        try {
          await pg.goto(`http://${host(surface)}:${port}${path}`, { waitUntil: 'networkidle', timeout: 30000 });
        } catch (e) { console.log(`ОШИБКА ${surface} ${path}: ${e.message.split('\n')[0]}`); continue; }
        if (open) {
          const row = pg.locator('.table-wrap tbody tr[data-detail-key] td.grow').first();
          if (!(await row.count())) continue;
          await row.click({ position: { x: 3, y: 6 } });
          await pg.waitForSelector('.split[data-open] .detail-card', { timeout: 8000 }).catch(() => {});
          await pg.waitForTimeout(400);
          if (!(await pg.locator('.split[data-open]').count())) continue;
        }
        const r = await pg.evaluate(inspect);
        if (!r) continue;
        total++;
        if (r.problems.length) {
          bad++;
          const file = `${out}/${String(++shot).padStart(3, '0')}-${surface}-${path.replace(/[^a-z0-9]+/gi, '_')}-${w}${open ? '-open' : ''}.png`;
          await pg.screenshot({ path: file });
          console.log(`✗ ${surface} ${path} ${w}${open ? ' с карточкой' : ''}\n  · ${r.problems.join('\n  · ')}\n  снимок: ${file}`);
        }
      }
    }
  }
  await ctx.close();
}
await b.close();
console.log(`Проверено видов: ${total}, с нарушениями: ${bad}`);
process.exit(bad ? 1 : 0);
