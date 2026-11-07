// Сторож Partner XCar (07.11.2026, владелец: «не палить, что у нас есть парсер документов и Мигторг»): в том, что получает
// браузер поставщика — основная сборка, её стили и куски, которые грузят его экраны (фото, справочники, дата, просмотр
// документов и фото), — нет слов, выдающих внутреннее. Внутреннее CRM живёт в своих кусках (`app.js`, ленивые контроллеры),
// поставщику они не приходят. Сборка — свежая: `npm run build` перед проверкой.
//   node scripts/partner-lint.mjs [--no-build]
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const root = new URL('..', import.meta.url).pathname;
if (!process.argv.includes('--no-build')) execSync('npm run build --silent', { cwd: root, stdio: ['ignore', 'ignore', 'inherit'] });

const manifest = JSON.parse(readFileSync(`${root}public/build/manifest.json`, 'utf8'));
const FORBIDDEN = /migtorg|мигторг|распозна|парсер|парсинг/i;
// Куски, что грузят экраны партнёра: контроллеры его страниц и то, что они тянут за собой.
const PARTNER_CHUNKS = ['resources/js/controllers/photos_controller.js', 'resources/js/controllers/combobox_controller.js', 'resources/js/controllers/date_controller.js', 'resources/js/docs/viewer.js'];

const files = new Set();
const walk = (key, dynamic = false) => {
    const chunk = manifest[key];
    if (!chunk || files.has(chunk.file)) return;
    files.add(chunk.file);
    (chunk.css ?? []).forEach((css) => files.add(css));
    (chunk.imports ?? []).forEach((k) => walk(k));
    if (dynamic) (chunk.dynamicImports ?? []).forEach((k) => walk(k, true));
};
walk('resources/js/app.js');
walk('resources/css/app.css');
PARTNER_CHUNKS.forEach((k) => walk(k, true));

const found = [];
for (const file of files) {
    const text = readFileSync(`${root}public/build/${file}`, 'utf8');
    const m = text.match(new RegExp(`.{0,40}(${FORBIDDEN.source}).{0,40}`, 'i'));
    if (m) found.push(`${file}: …${m[0]}…`);
}
if (found.length) {
    console.error('Partner XCar: в сборке поставщика внутреннее —\n' + found.join('\n'));
    process.exit(1);
}
console.log(`Partner XCar: сборка поставщика чистая (${files.size} файлов)`);
