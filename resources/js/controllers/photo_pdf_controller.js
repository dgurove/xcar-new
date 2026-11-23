import { Controller } from '@hotwired/stimulus';
import { shrink } from '../lib/image.js';
import { request } from '../lib/http.js';
import { closeSheet, openSheet } from '../sheet.js';
import * as Turbo from '@hotwired/turbo';

// «Приложить» одним документом (`x-ui.photo-pdf`; 06.11.2026 парковка, 24.11.2026 сделка): кнопка — выбор файлов, телефон
// показывает своё меню (медиатека, камера, файлы); выбрали — шторка: плитками по порядку, «×» убирает, «‹» ставит раньше,
// «+» — ещё; название (подсказки чипами) обязательно. «Сохранить» ужимает фото в телефоне (страница — 2000 px: мелкий текст
// читается), PDF и Word шлёт как есть — одним запросом; сервер собирает один документ (`PhotosPdf::document`). Word — только
// один и без фото (иначе слово в шторке). Готово — событие `photo-pdf:saved` с ответом ({id|html, name, pages}), `html`
// (строка вложения письма) встаёт в `append`, `reload` — страница перечитывается (сделка: тост пришёл с сервера).
export default class extends Controller {
    static targets = ['pages', 'name', 'save', 'error', 'bar', 'more'];
    static values = { url: String, kind: String, stage: String, vehicle: String, append: String, name: String, word: Boolean, fields: Object };

    connect() {
        this.files = [];
    }

    disconnect() {
        this.files.forEach((f) => f.url && URL.revokeObjectURL(f.url));
    }

    add(e) {
        const picked = [...e.target.files].map((file) => ({ file, kind: kindOf(file) })).filter((f) => f.kind && (f.kind !== 'word' || this.wordValue));
        e.target.value = '';
        picked.forEach((f) => this.files.push({ ...f, url: f.kind === 'image' ? URL.createObjectURL(f.file) : null }));
        this.errorTarget.hidden = true;
        this.render();
        const dialog = this.element.querySelector('dialog');
        if (picked.length && dialog && !dialog.open) openSheet(dialog);
    }

    remove(e) {
        const i = Number(e.params.index);
        if (this.files[i].url) URL.revokeObjectURL(this.files[i].url);
        this.files.splice(i, 1);
        this.render();
    }

    earlier(e) {
        const i = Number(e.params.index);
        if (i > 0) [this.files[i - 1], this.files[i]] = [this.files[i], this.files[i - 1]];
        this.render();
    }

    pick(e) {
        this.nameTarget.value = e.params.name;
        this.nameTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.errorTarget.hidden = true;
    }

    render() {
        const box = this.pagesTarget;
        box.replaceChildren();
        this.files.forEach(({ url, file, kind }, i) => {
            const tile = document.createElement('div');
            tile.className = 'report-shot photo-pdf-page' + (url ? '' : ' photo-pdf-file');
            tile.innerHTML = (url ? '<img alt="">' : `<span class="photo-pdf-ext photo-pdf-ext--${kind}"></span><span class="photo-pdf-fname"></span>`)
                + `<span class="photo-pdf-n nums">${i + 1}</span>`
                + `<button type="button" class="photo-pdf-x" aria-label="Убрать" data-action="photo-pdf#remove" data-photo-pdf-index-param="${i}">×</button>`
                + (i ? `<button type="button" class="photo-pdf-up" aria-label="Раньше" data-action="photo-pdf#earlier" data-photo-pdf-index-param="${i}">‹</button>` : '');
            if (url) tile.querySelector('img').src = url;
            else {
                tile.querySelector('.photo-pdf-ext').textContent = (file.name.split('.').pop() || kind).toUpperCase();
                tile.querySelector('.photo-pdf-fname').textContent = file.name;
            }
            box.append(tile);
        });
        if (this.hasMoreTarget) box.append(this.moreTarget.content.cloneNode(true));
        const n = this.files.length;
        const images = this.files.filter((f) => f.kind === 'image').length;
        const word = this.files.some((f) => f.kind === 'word');
        const mixed = word && n > 1;
        if (mixed) this.fail('Word прикладывается отдельно: уберите фото или Word');
        else if (this.errorTarget.textContent.startsWith('Word')) this.errorTarget.hidden = true;
        this.saveTarget.disabled = !n || mixed;
        this.saveTarget.textContent = n === 1 && !images ? 'Сохранить'
            : images === n && n ? `Сохранить PDF, ${n} ${plural(n, 'страница', 'страницы', 'страниц')}` : 'Сохранить PDF';
    }

    async save() {
        const name = this.nameTarget.value.trim();
        if (!name) return this.fail('Как назвать документ?', true);
        if (!this.files.length || this.busy) return;
        this.busy = true;
        this.saveTarget.disabled = true;
        this.errorTarget.hidden = true;
        this.progress(0.05);
        try {
            const form = new FormData();
            for (const [i, { file, kind }] of this.files.entries()) {
                const page = kind === 'image' ? await shrink(file, { max: 2000, quality: 0.85 }) : file;
                form.append('pages[]', page, page.name || `page-${i + 1}.jpg`);
                this.progress(0.05 + 0.5 * (i + 1) / this.files.length);
            }
            for (const [k, v] of Object.entries(this.fieldsValue)) form.append(k, v);
            form.append('name', name);
            if (this.kindValue) form.append('kind', this.kindValue);
            if (this.stageValue) form.append('stage', this.stageValue);
            if (this.vehicleValue) form.append('vehicle', this.vehicleValue);
            this.progress(0.7);
            const r = await request(this.urlValue, { method: 'POST', body: form, quiet: true, statuses: [422], timeout: 120000 });
            const answer = r ? await r.json().catch(() => ({})) : null;
            if (!r || !r.ok) throw new Error(answer?.errors?.name?.[0] || answer?.message || (r ? 'PDF не собрался' : 'Нет связи'));
            this.progress(1);
            // Сделка: файл лёг в договор или шаг, шаг мог пройти — страница целиком заново, тост — с сервера.
            if (answer.reload) {
                this.element.dispatchEvent(new CustomEvent('photo-pdf:saved', { bubbles: true, detail: answer }));
                this.reset();
                const open = this.element.querySelector('dialog');
                if (open?.open) closeSheet(open);
                Turbo.visit(location.href, { action: 'replace' });
                return;
            }
            if (answer.html && this.appendValue) document.querySelector(this.appendValue)?.insertAdjacentHTML('beforeend', answer.html);
            // Файлы шага окна приёма и выдачи — список бумаг заново потоком (`park.vehicles.papers-stream`).
            if (answer.stream) Turbo.renderStreamMessage(answer.stream);
            this.element.dispatchEvent(new CustomEvent('photo-pdf:saved', { bubbles: true, detail: answer }));
            window.toast?.(answer.pages ? `${answer.name}, ${answer.pages} ${plural(answer.pages, 'страница', 'страницы', 'страниц')}` : answer.name);
            this.reset();
            const dialog = this.element.querySelector('dialog');
            if (dialog?.open) closeSheet(dialog);
        } catch (err) {
            this.fail(err.message || 'PDF не собрался');
        } finally {
            this.busy = false;
            this.progress(null);
            this.saveTarget.disabled = !this.files.length;
        }
    }

    reset() {
        this.files.forEach((f) => f.url && URL.revokeObjectURL(f.url));
        this.files = [];
        this.nameTarget.value = this.nameValue;
        this.nameTarget.defaultValue = this.nameValue;
        this.render();
    }

    fail(text, field = false) {
        this.errorTarget.textContent = text;
        this.errorTarget.hidden = false;
        if (field) (window.focusField ?? ((el) => el.focus()))(this.nameTarget);
    }

    progress(p) {
        if (!this.hasBarTarget) return;
        this.barTarget.hidden = p === null;
        if (p !== null) this.barTarget.style.setProperty('--p', String(p));
    }
}

function plural(n, one, few, many) {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
    return many;
}

// Фото (и HEIC без типа), PDF, Word; остальное не берём.
function kindOf(file) {
    if (file.type.startsWith('image/') || /\.(heic|heif)$/i.test(file.name)) return 'image';
    if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name)) return 'pdf';
    if (/\.docx?$/i.test(file.name)) return 'word';
    return null;
}
