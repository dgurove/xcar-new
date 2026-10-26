import { Controller } from '@hotwired/stimulus';
import { getJson } from '../lib/http';
import { shrink } from '../lib/image';
import { nums, rub } from '../lib/money';
import { reducedMotion as calm } from '../lib/motion';
import { plural } from '../lib/plural.js';

// «Оценить → Из текста» (x-offer.valuation-sheet). Первый экран — источник: «Вставить текст» (буфер), «Таблица Excel»,
// «Фото или скриншот»; файл встаёт карточкой с ходом чтения, перетаскивание и ⌘V — в любом месте окна. Текст уходит на
// разбор, этапы идут один за другим: лишние строки схлопываются, номера и суммы подсвечиваются, находятся предложения,
// ненайденные отходят, оценочные и закупочные бегут счётчиком. Итог — сегмент по группам и «Сохранить (N)»; сохранили —
// экран «Готово». Без анимаций (prefers-reduced-motion) — то же самое сразу.
const sleep = (ms) => new Promise((r) => setTimeout(r, calm() ? 0 : ms));

// Что можно прочитать файлом: картинка (OCR) или таблица Excel.
const SHEET = /\.(xlsx|xls|csv|ods)$/i;
const readable = (f) => f.type.startsWith('image/') || SHEET.test(f.name);
const size = (b) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} КБ` : `${(b / 1024 / 1024).toFixed(1).replace('.', ',')} МБ`);

export default class extends Controller {
    static targets = ['paste', 'sources', 'file', 'fileIcon', 'fileName', 'fileSub', 'editor', 'note', 'text', 'runBar',
        'work', 'lines', 'fail', 'reason', 'result', 'rows', 'token', 'save', 'what', 'done', 'doneTitle', 'doneSub', 'created', 'copyCode'];
    static values = { url: String, fileUrl: String };

    disconnect() {
        this.dropThumb();
    }

    // Источник виден, пока окно открыто и разбор не начат.
    get choosing() {
        return this.element.closest('dialog')?.open && !this.pasteTarget.hidden && !this.reading;
    }

    // ⌘V в любом месте окна: картинка или таблица — файлом, текст — в поле и сразу в разбор.
    pasted(event) {
        if (!this.choosing) return;
        const file = [...(event.clipboardData?.files ?? [])].find(readable);
        if (file) {
            event.preventDefault();
            return this.read(file);
        }
        if (event.target === this.textTarget) return setTimeout(() => this.run(), 0);
        const text = event.clipboardData?.getData('text/plain')?.trim();
        if (!text || event.target.closest?.('input, textarea, [contenteditable]')) return;
        event.preventDefault();
        this.showText(text);
        this.run();
    }

    // «Вставить текст»: из буфера, если браузер даёт; не даёт или пусто — поле с клавиатурой.
    async pasteText() {
        let text = '';
        try { text = (await navigator.clipboard?.readText?.())?.trim() ?? ''; } catch { /* нет доступа — руками */ }
        this.showText(text);
        if (text) this.run();
        else (window.focusField ?? ((el) => el.focus()))(this.textTarget);
    }

    showText(text, note = false) {
        this.textTarget.value = text;
        this.sourcesTarget.hidden = true;
        this.editorTarget.hidden = false;
        this.noteTarget.hidden = !note;
        this.typed();
    }

    // Поле растёт по строкам; «Разобрать» — когда есть что разбирать.
    typed() {
        const t = this.textTarget;
        t.style.height = 'auto';
        t.style.height = `${t.scrollHeight + 2}px`;
        this.runBarTarget.hidden = this.editorTarget.hidden;
    }

    pick(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (file) this.read(file);
    }

    dragOver(event) {
        if (this.choosing && [...(event.dataTransfer?.items ?? [])].some((i) => i.kind === 'file')) {
            event.preventDefault();
            this.element.classList.add('is-dragging');
        }
    }

    dragLeave(event) {
        if (!this.element.contains(event.relatedTarget)) this.element.classList.remove('is-dragging');
    }

    drop(event) {
        this.element.classList.remove('is-dragging');
        const file = [...(event.dataTransfer?.files ?? [])].find(readable);
        if (!file || !this.choosing) return;
        event.preventDefault();
        this.read(file);
    }

    // Файл — карточкой: значок таблицы или миниатюра, имя, объём, кольцо хода. Сервер даёт текст «номер сумма»
    // (OCR — ValuationPhoto, Excel — ValuationSheet): уверенный — сразу в разбор, спорный — в поле с просьбой проверить.
    async read(file) {
        if (this.busy || this.reading) return;
        const sheet = SHEET.test(file.name);
        this.reading = true;
        this.sourcesTarget.hidden = true;
        this.editorTarget.hidden = true;
        this.runBarTarget.hidden = true;
        this.fileTarget.hidden = false;
        this.fileNameTarget.textContent = file.name || (sheet ? 'Таблица' : 'Снимок экрана');
        this.dropThumb();
        if (sheet) {
            this.fileIconTarget.innerHTML = '<span class="valuation-file-sheet">XLS</span>';
        } else {
            this.thumb = URL.createObjectURL(file);
            this.fileIconTarget.innerHTML = `<img src="${this.thumb}" alt="">`;
        }
        this.fileState('read', `${size(file.size)}, ${sheet ? 'читаем таблицу' : 'читаем текст на фото'}`);
        const body = new FormData();
        body.append('file', sheet ? file : await shrink(file, { max: 2400, quality: 0.92 }));
        const data = await getJson(this.fileUrlValue, { method: 'POST', body, timeout: 60_000, quiet: true });
        this.reading = false;
        if (!data) return this.fileState('fail', 'Не получилось прочитать, попробуйте ещё раз');
        if (!data.text) return this.fileState('fail', sheet ? 'В таблице нет номеров убытка' : 'На фото нет номеров убытка');
        this.fileState('done', size(file.size));
        if (data.sure) {
            this.textTarget.value = data.text;
            await sleep(350);
            return this.run();
        }
        this.showText(data.text, true);
    }

    fileState(state, sub) {
        this.fileTarget.querySelector('.valuation-file').dataset.state = state;
        this.fileSubTarget.textContent = sub;
    }

    dropThumb() {
        if (this.thumb) URL.revokeObjectURL(this.thumb);
        this.thumb = null;
    }

    async run() {
        const text = this.textTarget.value.trim();
        if (!text || this.busy) return;
        this.busy = true;
        // Шторка во весь рост — с началом разбора; пока вставляют, она по содержимому.
        this.element.classList.add('is-working');
        this.pasteTarget.hidden = true;
        this.workTarget.hidden = false;
        this.steps().forEach((s) => this.mark(s.dataset.step, 'wait', ''));
        this.linesTarget.replaceChildren();
        this.linesTarget.hidden = false;
        this.failTarget.hidden = true;

        this.mark('clean', 'run');
        const started = performance.now();
        // Сбой пишется в самом окне разбора, поэтому запрос — без тоста.
        const data = await getJson(this.urlValue, { method: 'POST', json: { text }, quiet: true });
        if (!data) return this.fail('Не получилось разобрать, попробуйте ещё раз');
        await sleep(Math.max(0, 300 - (performance.now() - started)));

        const { counts } = data;
        // 1. Лишнее уходит, номера и суммы остаются.
        await this.clean(data.lines);
        if (!counts.refs) return this.fail('Номеров убытка в тексте не нашли');
        this.mark('clean', 'done', `${counts.refs} ${plural(counts.refs, ['номер', 'номера', 'номеров'])}`);

        // 2. Предложения.
        this.mark('match', 'run');
        await sleep(650);
        this.mark('match', 'done', counts.found ? `нашли ${counts.found}` : 'не нашли');

        // 3. Ненайденные.
        this.mark('drop', 'run');
        await sleep(450);
        this.mark('drop', 'done', counts.absent ? `нет в CRM ${counts.absent}` : 'все нашлись');

        // Строки итога появляются под этапами — дальше считаются прямо в них.
        this.rowsTarget.innerHTML = data.html;
        this.tokenTarget.value = data.token;
        await this.collapseLines();
        this.resultTarget.hidden = false;
        const rows = [...this.rowsTarget.querySelectorAll('[data-valuation-row]')];
        rows.forEach((r) => r.classList.add('is-pending'));
        await this.reveal(rows);

        // 4–5. Оценочные, затем закупочные — счётчиком.
        this.mark('value', 'run');
        await this.countUp(rows, '[data-v]', 'value', false);
        this.mark('value', 'done', counts.take ? `${counts.take} ${plural(counts.take, ['предложение', 'предложения', 'предложений'])}` : '');
        this.mark('floor', 'run');
        rows.forEach((r) => r.classList.add('show-floor'));
        await this.countUp(rows, '[data-f]', 'floor', true);
        this.mark('floor', 'done');

        // 6. Готово: галка, этапы уходят — итог получает всю высоту окна.
        this.mark('done', 'done');
        rows.forEach((r) => r.classList.remove('is-pending'));
        this.count();
        await sleep(600);
        if (!calm()) await this.workTarget.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200 }).finished.catch(() => {});
        this.workTarget.hidden = true;
        this.busy = false;
    }

    // Строки текста: сначала все, потом лишние схлопываются по очереди, а номера и суммы загораются.
    async clean(lines) {
        const els = lines.map((l) => {
            const el = document.createElement('div');
            el.className = 'valuation-line';
            el.textContent = l.text;
            if (l.keep) el.dataset.keep = '';
            this.linesTarget.append(el);
            return el;
        });
        if (calm()) {
            els.filter((el) => !('keep' in el.dataset)).forEach((el) => el.remove());
            els.forEach((el) => el.classList.add('is-kept'));
            return;
        }
        await sleep(250);
        const junk = els.filter((el) => !('keep' in el.dataset));
        const step = Math.max(6, Math.min(40, 1400 / Math.max(1, junk.length)));
        const anims = junk.map((el, i) => el.animate(
            [{ opacity: 1, height: `${el.offsetHeight}px` }, { opacity: 0, height: '0px', paddingBlock: 0 }],
            { duration: 260, delay: i * step, easing: 'ease-in', fill: 'forwards' },
        ));
        // Номера и суммы загораются волной не дольше секунды (таблица — это 80 строк без лишнего).
        const kept = els.filter((el) => 'keep' in el.dataset);
        const glow = Math.min(step * 2, 1000 / Math.max(1, kept.length));
        kept.forEach((el, i) => setTimeout(() => el.classList.add('is-kept'), i * glow));
        if (!junk.length) await sleep(Math.min(1000, kept.length * glow));
        await Promise.all(anims.map((a) => a.finished.catch(() => {})));
        junk.forEach((el) => el.remove());
    }

    async collapseLines() {
        if (calm()) { this.linesTarget.hidden = true; return; }
        const box = this.linesTarget;
        await box.animate([{ opacity: 1, height: `${box.offsetHeight}px` }, { opacity: 0, height: '0px' }], { duration: 320, easing: 'ease-in-out' }).finished.catch(() => {});
        box.hidden = true;
    }

    async reveal(rows) {
        if (calm()) return;
        const step = Math.max(12, Math.min(60, 900 / Math.max(1, rows.length)));
        await Promise.all(rows.map((r, i) => r.animate(
            [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
            { duration: 240, delay: i * step, easing: 'ease-out', fill: 'backwards' },
        ).finished.catch(() => {})));
    }

    // Число бежит от нуля до своего значения во всех строках разом.
    async countUp(rows, selector, key, money) {
        const cells = rows.map((r) => [r.querySelector(selector), Number(r.dataset[key])]).filter(([el, n]) => el && n);
        if (calm()) return;
        const duration = 700;
        const start = performance.now();
        await new Promise((done) => {
            const frame = (now) => {
                const t = Math.min(1, (now - start) / duration);
                const k = 1 - (1 - t) ** 3;
                // Закупочная бежит тысячами — как её и округляют.
                cells.forEach(([el, n]) => { el.textContent = money ? rub(t < 1 ? Math.ceil((n * k) / 1000) * 1000 : n) : nums(n * k); });
                t < 1 ? requestAnimationFrame(frame) : done();
            };
            requestAnimationFrame(frame);
        });
    }

    overwriteAll(event) {
        event.preventDefault();
        this.rowsTarget.querySelectorAll('input[type=radio][value="overwrite"]').forEach((r) => { r.checked = true; });
        this.count();
    }

    // Сегмент групп итога: видна одна.
    tab(event) {
        const key = event.currentTarget.dataset.group;
        this.rowsTarget.querySelectorAll('.valuation-tabs [data-group]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.group === key)));
        this.rowsTarget.querySelectorAll('section.valuation-group').forEach((g) => { g.hidden = g.dataset.group !== key; });
        this.rowsTarget.scrollTop = 0;
    }

    // Что сохранится — по карточкам: галка, «Оставить / Перезаписать», VIN и город из текста и вписанные.
    // Кнопка — сколько ТС, строка над ней — что именно: «оценка 8, VIN 5, город 4».
    count() {
        let price = 0, vin = 0, city = 0, cars = 0, create = 0;
        for (const card of this.rowsTarget.querySelectorAll('.valuation-card')) {
            if ('create' in card.dataset) {
                const on = card.querySelector('input[name="create[]"]').checked;
                card.classList.toggle('is-off', !on);
                create += on; cars += on;
                continue;
            }
            const box = card.querySelector('input[type=checkbox][name="offers[]"]');
            const conflict = 'conflict' in card.dataset;
            const keep = conflict && card.querySelector('input[value="keep"]')?.checked;
            card.classList.toggle('is-keep', !!keep);
            card.classList.toggle('is-off', !!box && !box.checked);
            if (box && !box.checked) continue;
            const p = 'priceChange' in card.dataset && !(keep && 'valueDiffers' in card.dataset);
            const typedVin = card.querySelector('input[name^="vin["]')?.value.replace(/[^A-Za-z0-9]/g, '').length === 17;
            const typedCity = !!card.querySelector('input[type=hidden][name^="city["]')?.value;
            const v = 'vinFill' in card.dataset || typedVin;
            const c = 'cityFill' in card.dataset || typedCity;
            price += p; vin += v; city += c;
            if (p || v || c) cars++;
        }
        const parts = [price ? `оценка ${price}` : null, create ? `завести ${create}` : null, vin ? `VIN ${vin}` : null, city ? `город ${city}` : null].filter(Boolean);
        this.saveTarget.disabled = cars === 0;
        this.saveTarget.textContent = cars ? `Сохранить ${cars} ТС` : 'Нечего сохранять';
        this.whatTarget.textContent = parts.join(', ');
    }

    // Сохранение без перехода: ответ — итог словами, экран «Готово»; список под окном обновится по «Готово».
    async save(event) {
        event.preventDefault();
        if (this.saving) return;
        this.saving = true;
        this.saveTarget.setAttribute('aria-busy', 'true');
        const data = await getJson(this.resultTarget.action, { method: 'POST', body: new FormData(this.resultTarget) });
        this.saving = false;
        this.saveTarget.removeAttribute('aria-busy');
        if (!data) return;
        this.saved = true;
        this.resultTarget.hidden = true;
        this.doneTitleTarget.textContent = data.title;
        this.doneSubTarget.textContent = data.sub ?? '';
        this.doneTarget.hidden = false;
        if (data.created?.length) await this.arrive(data.created);
    }

    // Заведённые с Мигторга входят по одной: строка проявляется, номер убытка печатается, закупочная бежит тысячами.
    async arrive(created) {
        const box = this.createdTarget;
        box.replaceChildren();
        box.hidden = false;
        const rows = created.map((c) => {
            const a = document.createElement('a');
            a.className = 'row';
            a.href = `/offers/${c.number}`;
            a.dataset.turboFrame = '_top';
            a.innerHTML = '<span class="min-w-0 flex-1"><span class="block truncate"></span><span class="row-sub"><span class="nums"></span><span></span></span>'
                + '<span class="row-sub text-ink-dim">фото подтянутся с Мигторга</span></span><span class="nums text-sm"></span>';
            const [title, slot, city] = a.querySelectorAll('.block, .row-sub .nums, .row-sub .nums + span');
            // Номер — копируется нажатием (строку при этом не открывает); печатается текстом перед иконкой.
            const code = this.copyCodeTarget.content.firstElementChild.cloneNode(true);
            code.dataset.copyTextValue = c.ref;
            slot.replaceWith(code);
            const ref = code.firstChild;
            ref.textContent = '';
            title.textContent = c.title;
            city.textContent = c.city ?? '';
            a.lastElementChild.textContent = rub(c.floor);
            box.append(a);
            return [a, ref, a.lastElementChild, c];
        });
        if (calm()) { rows.forEach(([, ref, , c]) => { ref.textContent = c.ref; }); return; }
        for (const [a, ref, floor, c] of rows) {
            a.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 260, easing: 'ease-out', fill: 'backwards' });
            const typing = (async () => { for (let i = 1; i <= c.ref.length; i++) { ref.textContent = c.ref.slice(0, i); await sleep(18); } })();
            const start = performance.now();
            const run = new Promise((done) => {
                const frame = (now) => {
                    const t = Math.min(1, (now - start) / 600);
                    floor.textContent = rub(t < 1 ? Math.ceil((c.floor * (1 - (1 - t) ** 3)) / 1000) * 1000 : c.floor);
                    t < 1 ? requestAnimationFrame(frame) : done();
                };
                requestAnimationFrame(frame);
            });
            await sleep(140);
            if (a === rows.at(-1)[0]) await Promise.all([typing, run]);
        }
    }

    finish() {
        const again = this.saved;
        this.reset();
        if (again) window.Turbo?.visit(location.href, { action: 'replace' });
    }

    mark(step, state, count = null) {
        const li = this.element.querySelector(`[data-step="${step}"]`);
        if (!li) return;
        li.dataset.state = state;
        if (count !== null) li.querySelector('[data-count]').textContent = count;
    }

    steps() {
        return [...this.element.querySelectorAll('[data-step]')];
    }

    fail(reason) {
        this.busy = false;
        const running = this.element.querySelector('[data-step][data-state="run"]');
        if (running) running.dataset.state = 'fail';
        this.reasonTarget.textContent = reason;
        this.failTarget.hidden = false;
    }

    reset() {
        this.busy = false;
        this.saved = false;
        this.dropThumb();
        this.element.classList.remove('is-working');
        this.textTarget.value = '';
        this.textTarget.style.height = '';
        this.pasteTarget.hidden = false;
        this.sourcesTarget.hidden = false;
        this.fileTarget.hidden = true;
        this.editorTarget.hidden = true;
        this.runBarTarget.hidden = true;
        this.workTarget.hidden = true;
        this.resultTarget.hidden = true;
        this.doneTarget.hidden = true;
        this.createdTarget.hidden = true;
        this.createdTarget.replaceChildren();
        this.rowsTarget.replaceChildren();
    }
}
