import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';
import { shrink } from '../lib/image';
import { pool, sha } from '../lib/upload';
import { plural } from '../lib/plural.js';

// Форма отчёта о приёме (x-park.report-form). Файлы уходят на сервер сразу при выборе, по одному (29.10.2026, Никита:
// «работает через раз» — раньше все снимки в исходном размере шли одним запросом с формой и упирались в 64 МБ и 30 с):
// кадр ужимается в телефоне (`shrink`) и ложится в «Фото при приёме», снимок «Снять» — страницей документа (один выбор —
// один PDF при отправке, `scan`), «Файл» — документом как есть. Каждый — плиткой или чипом с ходом; не загрузился —
// красным, тап — снова. В форму встаёт только номер файла. Кнопка называет, что уйдёт («Отправить 24 фото и 3
// документа»), и не жмётся, пока что-то грузится или не загрузилось. Фраза письма сама меняется «фотоотчет» ↔
// «фотоотчет и скан-копии», пока её не правили руками.

export default class extends Controller {
    static targets = ['photo', 'shots', 'photoCount', 'doc', 'slot', 'text', 'submit'];
    static values = { url: String, docs: String, plain: String };

    connect() {
        this.touched = false;
        this.pending = 0;
        this.uploads = pool((job) => this.run(job));
        this.element.addEventListener('submit', this.guard = (e) => {
            if (!this.pending && !this.failed()) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            window.toast?.(this.pending ? 'Файлы ещё загружаются — дождитесь' : 'Файл не загрузился — повторите или уберите', 'danger');
        }, true);
        this.count();
    }

    disconnect() {
        this.element.removeEventListener('submit', this.guard, true);
    }

    edited() {
        this.touched = true;
    }

    // «Добавить» — кадры приёма.
    photos(e) {
        const files = [...e.target.files];
        e.target.value = '';
        const add = this.shotsTarget.querySelector('.report-add');
        for (const file of files) {
            const tile = document.createElement('label');
            tile.className = 'report-shot is-uploading';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.onload = () => URL.revokeObjectURL(img.src);
            img.alt = '';
            tile.append(img);
            this.shotsTarget.insertBefore(tile, add);
            this.enqueue({ file, kind: 'photo', node: tile, done: (id) => this.photoDone(tile, id) });
        }
    }

    photoDone(tile, id) {
        const box = Object.assign(document.createElement('input'), { type: 'checkbox', name: 'photos[]', value: id, checked: true });
        box.dataset.action = 'report#count';
        box.dataset.reportTarget = 'photo';
        const tick = document.createElement('span');
        tick.className = 'report-tick';
        tick.innerHTML = '<svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5 9-10"/></svg>';
        tile.prepend(box);
        tile.append(tick);
    }

    // «Снять» — страницы одного документа: одним PDF при отправке (общий `scan`, порядок — `page`).
    scan(e) {
        const files = [...e.target.files];
        e.target.value = '';
        const scan = crypto.randomUUID?.() ?? `s${Date.now()}${Math.random().toString(36).slice(2)}`;
        files.forEach((file, page) => this.doc(e.target, file, `снимок ${page + 1}`, { scan, page }));
    }

    // «Из фото» (`x-ui.photo-pdf`) — PDF уже собран и лежит у ТС: строкой вложения (`x-park.report-file`, разметка с сервера).
    pdf(e) {
        const { html } = e.detail ?? {};
        if (!html) return;
        e.currentTarget.querySelector('[data-chips]').insertAdjacentHTML('beforeend', html);
        this.count();
    }

    // «×» у документа из фото — не прикладывать: строка уходит, с ней и галка формы.
    unattach(e) {
        e.target.closest('.report-file')?.remove();
        this.count();
    }

    // «Файл» — каждый своим документом: строкой, как PDF из фото (13.11.2026, Сергей: снимки висели обрезанными чипами
    // «5456269815564673091.jp»). Пока грузится — заготовка строки с подписью слота, готово — строка с сервера.
    files(e) {
        const files = [...e.target.files];
        e.target.value = '';
        const slot = e.target.closest('[data-report-target="slot"]');
        const title = slot.dataset.kind === 'other' ? null : slot.querySelector(':scope > span')?.textContent.trim();
        files.forEach((file) => this.doc(e.target, file, title || file.name, {}));
    }

    doc(input, file, label, extra) {
        const slot = input.closest('[data-report-target="slot"]');
        const kind = slot.dataset.kind;
        if (extra.scan) { this.page(slot, kind, file, label, extra); return; }
        const row = document.createElement('div');
        row.className = 'file-row report-file is-uploading';
        // Значок как у готовой строки (`x-ui.file-icon`): расширение и под ним вес, без миниатюры.
        const icon = Object.assign(document.createElement('span'), { className: 'file-icon file-icon-sized' });
        const ext = (file.name.split('.').pop() || '?').toLowerCase().replace('jpeg', 'jpg').slice(0, 4);
        icon.append(Object.assign(document.createElement('span'), { className: 'file-icon-ext', textContent: ext }),
            Object.assign(document.createElement('span'), { className: 'file-icon-size', textContent: weight(file.size) }));
        const name = Object.assign(document.createElement('span'), { className: 'file-name' });
        name.append(Object.assign(document.createElement('span'), { textContent: label }));
        name.firstChild.dataset.fileName = '';
        row.append(icon, name);
        slot.querySelector('[data-chips]').append(row);
        this.enqueue({
            file, kind, extra, node: row,
            done: (id, answer) => {
                if (answer?.html) { row.insertAdjacentHTML('afterend', answer.html); row.remove(); return; }
                const box = Object.assign(document.createElement('input'), { type: 'checkbox', name: `docs[${kind}][]`, value: id, checked: true, className: 'sr-only' });
                box.dataset.reportTarget = 'doc';
                row.append(box);
            },
        });
    }

    // Снимок «Снять» — страница будущего PDF: чипом «снимок N» со своим выбором.
    page(slot, kind, file, label, extra) {
        const chip = document.createElement('label');
        chip.className = 'choice report-chip is-uploading';
        chip.dataset.scan = extra.scan;
        chip.append(Object.assign(document.createElement('span'), { className: 'max-w-[10rem] truncate', textContent: label }));
        slot.querySelector('[data-chips]').append(chip);
        this.enqueue({
            file, kind, extra, node: chip,
            done: (id) => {
                const box = Object.assign(document.createElement('input'), { type: 'checkbox', name: `docs[${kind}][]`, value: id, checked: true });
                box.dataset.action = 'report#count';
                box.dataset.reportTarget = 'doc';
                chip.prepend(box);
            },
        });
    }

    // Очередь загрузки — общая с рядом фото (`lib/upload.js`): по три сразу, экран не гаснет.
    enqueue(job) {
        this.pending++;
        this.count();
        this.uploads.push(job);
    }

    async run(job) {
        const { node } = job;
        node.classList.remove('is-failed');
        node.querySelector('.report-drop')?.remove();
        node.classList.add('is-uploading');
        node.style.setProperty('--p', '0.1');
        try {
            const image = job.file.type.startsWith('image/');
            // Кадр — 1600, как ряд фото; страница документа — 2200, чтобы мелкий текст читался.
            const file = image ? await shrink(job.file, { max: job.kind === 'photo' ? 1600 : 2200, quality: 0.85 }) : job.file;
            const form = new FormData();
            form.append('file', file, file.name);
            form.append('kind', job.kind);
            if (job.kind === 'photo') {
                const print = await sha(job.file);
                if (print) form.append('sha', print);
            }
            if (job.extra?.scan) { form.append('scan', job.extra.scan); form.append('page', String(job.extra.page)); }
            node.style.setProperty('--p', '0.4');
            const r = await request(this.urlValue, { method: 'POST', body: form, quiet: true, statuses: [422] });
            const answer = r ? await r.json().catch(() => ({})) : null;
            if (!r || !r.ok || !answer?.id) throw new Error(answer?.message || (r ? 'Не загрузилось' : 'Нет связи'));
            node.classList.remove('is-uploading');
            node.style.removeProperty('--p');
            job.done(answer.id, answer);
        } catch (e) {
            node.classList.remove('is-uploading');
            node.classList.add('is-failed');
            node.title = e.message || 'Не загрузилось';
            // Не грузится совсем (файл не тот) — «×» убирает его, отчёт уходит без него.
            if (!node.querySelector('.report-drop')) {
                const drop = Object.assign(document.createElement('button'), { type: 'button', className: 'report-drop', textContent: '×' });
                drop.setAttribute('aria-label', 'Убрать');
                drop.addEventListener('click', (ev) => { ev.preventDefault(); ev.stopPropagation(); node.remove(); this.count(); });
                node.append(drop);
            }
            // Тап по красному — снова; новый обработчик на каждый сбой, прежний снимается.
            node.onclick = (ev) => {
                ev.preventDefault();
                node.onclick = null;
                this.enqueue(job);
            };
        } finally {
            this.pending--;
            this.count();
        }
    }

    failed() {
        return this.element.querySelectorAll('.is-failed').length;
    }

    count() {
        const photos = this.photoTargets.filter((i) => i.checked).length;
        if (this.hasPhotoCountTarget) this.photoCountTarget.textContent = photos ? `${photos}` : '';

        // Документ — отмеченный файл; снимки одного «Снять» — один документ.
        const scans = new Set();
        let docs = 0;
        for (const box of this.docTargets.filter((d) => d.checked)) {
            const scan = box.closest('[data-scan]')?.dataset.scan;
            if (scan) scans.add(scan); else docs++;
        }
        docs += scans.size;

        if (!this.touched && this.hasTextTarget) this.textTarget.value = docs ? this.docsValue : this.plainValue;
        const failed = this.failed();
        if (this.pending) {
            this.submitTarget.textContent = `Загружается ${this.pending} ${plural(this.pending, ['файл', 'файла', 'файлов'])}`;
        } else if (failed) {
            this.submitTarget.textContent = `Не загрузилось ${failed}: нажмите на красное — повторить или ×`;
        } else {
            const parts = [photos ? `${photos} фото` : '', docs ? `${docs} ${plural(docs, ['документ', 'документа', 'документов'])}` : ''].filter(Boolean);
            this.submitTarget.textContent = parts.length ? `Отправить ${parts.join(' и ')}` : 'Отправить отчёт';
        }
        this.submitTarget.disabled = this.pending > 0 || failed > 0 || !(photos || docs);
    }
}

// Вес как `App\Support\FileSize::short`: «812 б», «384 Кб», «2,4 Мб».
function weight(bytes) {
    if (bytes >= 1048576) return `${String(Math.round(bytes / 104857.6) / 10).replace('.', ',')}\u00A0Мб`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)}\u00A0Кб`;
    return `${bytes}\u00A0б`;
}
