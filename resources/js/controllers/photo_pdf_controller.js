import { Controller } from '@hotwired/stimulus';
import { shrink } from '../lib/image.js';
import { request } from '../lib/http.js';
import { closeSheet } from '../sheet.js';
import * as Turbo from '@hotwired/turbo';

// Документ из фото одним PDF (06.11.2026, Сергей: «проще сфоткать документ, чем сканить»; `x-ui.photo-pdf`): «Снять» —
// камера, «Из галереи» — несколько снимков сразу; страницы плитками в порядке выбора, «×» убирает, «‹» ставит раньше,
// «+» — ещё; название (подсказки чипами) обязательно. «Сохранить PDF» ужимает кадры в телефоне (страница — 2000 px: мелкий
// текст читается) и шлёт их одним запросом; сервер склеивает PDF (`PhotosPdf`). Готово — событие `photo-pdf:saved` с ответом
// ({id|html, name, pages}) на кнопке шторки, а `html` (строка вложения письма) встаёт в `append`.
export default class extends Controller {
    static targets = ['pages', 'name', 'save', 'error', 'bar', 'empty'];
    static values = { url: String, kind: String, stage: String, vehicle: String, append: String, name: String };

    connect() {
        this.files = [];
    }

    disconnect() {
        this.files.forEach((f) => URL.revokeObjectURL(f.url));
    }

    add(e) {
        const picked = [...e.target.files].filter((f) => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name));
        e.target.value = '';
        picked.forEach((file) => this.files.push({ file, url: URL.createObjectURL(file) }));
        this.render();
    }

    remove(e) {
        const i = Number(e.params.index);
        URL.revokeObjectURL(this.files[i].url);
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
        this.files.forEach(({ url }, i) => {
            const tile = document.createElement('div');
            tile.className = 'report-shot photo-pdf-page';
            tile.innerHTML = `<img alt=""><span class="photo-pdf-n nums">${i + 1}</span>`
                + `<button type="button" class="photo-pdf-x" aria-label="Убрать страницу" data-action="photo-pdf#remove" data-photo-pdf-index-param="${i}">×</button>`
                + (i ? `<button type="button" class="photo-pdf-up" aria-label="Раньше" data-action="photo-pdf#earlier" data-photo-pdf-index-param="${i}">‹</button>` : '');
            tile.querySelector('img').src = url;
            box.append(tile);
        });
        box.hidden = !this.files.length;
        this.emptyTarget.hidden = this.files.length > 0;
        const n = this.files.length;
        this.saveTarget.disabled = !n;
        this.saveTarget.textContent = n ? `Сохранить PDF · ${n} ${plural(n, 'страница', 'страницы', 'страниц')}` : 'Сохранить PDF';
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
            for (const [i, { file }] of this.files.entries()) {
                const page = await shrink(file, { max: 2000, quality: 0.85 });
                form.append('pages[]', page, page.name || `page-${i + 1}.jpg`);
                this.progress(0.05 + 0.5 * (i + 1) / this.files.length);
            }
            form.append('name', name);
            if (this.kindValue) form.append('kind', this.kindValue);
            if (this.stageValue) form.append('stage', this.stageValue);
            if (this.vehicleValue) form.append('vehicle', this.vehicleValue);
            this.progress(0.7);
            const r = await request(this.urlValue, { method: 'POST', body: form, quiet: true, statuses: [422], timeout: 120000 });
            const answer = r ? await r.json().catch(() => ({})) : null;
            if (!r || !r.ok) throw new Error(answer?.errors?.name?.[0] || answer?.message || (r ? 'PDF не собрался' : 'Нет связи'));
            this.progress(1);
            if (answer.html && this.appendValue) document.querySelector(this.appendValue)?.insertAdjacentHTML('beforeend', answer.html);
            // Файлы шага окна приёма и выдачи — список бумаг заново потоком (`park.vehicles.papers-stream`).
            if (answer.stream) Turbo.renderStreamMessage(answer.stream);
            this.element.dispatchEvent(new CustomEvent('photo-pdf:saved', { bubbles: true, detail: answer }));
            window.toast?.(`${answer.name} — ${answer.pages} ${plural(answer.pages, 'страница', 'страницы', 'страниц')}`);
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
        this.files.forEach((f) => URL.revokeObjectURL(f.url));
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
