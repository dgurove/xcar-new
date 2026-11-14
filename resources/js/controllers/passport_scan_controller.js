import { Controller } from '@hotwired/stimulus';
import { filler } from '../fill.js';
import { getJson } from '../lib/http';
import { openLightbox } from '../lightbox';

// Скан паспорта (x-billing.passport-scan): страница уходит на сервер, читает задача `ReadPassport`, плитка опрашивает
// `live` раз в 2 с, пока читается, и ставит прочитанное в поля формы с подсветкой: ФИО и сверенное — поверх, прочее — в
// пустые. Нажатие на скан — наш просмотр на весь экран по обеим страницам.
export default class extends Controller {
    static values = { url: String, form: String, prefix: String };

    static targets = ['tile', 'reread'];

    connect() {
        this.filled = new Set();
        if (this.tileTargets.some((t) => t.dataset.state === 'reading')) this.poll();
        // Прочитано раньше, а поля формы пустые (13.11.2026: закрыли окно до конца чтения) — подставить сразу, в пустые.
        else if (this.tileTargets.some((t) => t.dataset.state === 'done')) getJson(`${this.urlValue}/live`, { quiet: true }).then((data) => data && this.apply(data));
    }

    // «Распознать заново» (13.11.2026, владелец: «скачивать и заново закидывать — ебанистика»): обе страницы читаются снова,
    // прочитанное — поверх полей формы (и данных на сервере, `ReadPassport::apply`).
    async reread() {
        this.over = true;
        this.filled.clear();
        for (const tile of this.tileTargets) if (tile.classList.contains('passport-tile--on')) { tile.dataset.state = 'reading'; tile.classList.add('spark-busy'); }
        this.toggleReread(true);
        const data = await getJson(`${this.urlValue}/read`, { method: 'POST', quiet: true });
        if (data) this.apply(data);
        else window.toast?.('Не получилось, попробуйте ещё раз');
    }

    toggleReread(busy) {
        if (this.hasRereadTarget) this.rereadTarget.hidden = busy || !this.tileTargets.some((t) => t.classList.contains('passport-tile--on'));
    }

    disconnect() {
        clearTimeout(this.timer);
        this.viewer?.destroy();
        this.viewer = null;
    }

    // Просмотр (07.11.2026, владелец: «пока корректирую распознанное — быстро посмотреть, что в паспорте»): обе страницы,
    // листаются; только что выбранный файл — его же картинкой, пока сервер не ответил.
    async view(event) {
        const tiles = this.tileTargets.filter((t) => t.classList.contains('passport-tile--on'));
        const items = tiles.map((t) => {
            const img = t.querySelector('.passport-tile-view img');
            const local = img?.src.startsWith('blob:') ? img.src : null;
            return { src: local || t.dataset.url || img?.src, thumb: local || t.dataset.thumb, el: img };
        }).filter((it) => it.src);
        const index = Math.max(0, tiles.indexOf(event.currentTarget.closest('[data-page]')));
        this.viewer = await openLightbox({ items, index, download: true, onClose: () => { this.viewer = null; } });
    }

    // Две фотографии сразу — разворот и прописка по порядку; PDF из двух страниц сервер сам делит на обе плитки.
    async upload(event) {
        const input = event.target;
        const files = [...(input.files || [])];
        input.value = '';
        if (files.length >= 2) {
            for (const [i, tile] of this.tileTargets.slice(0, 2).entries()) await this.send(tile, files[i]);
            return;
        }
        if (files[0]) await this.send(input.closest('[data-page]'), files[0]);
    }

    async send(tile, file) {
        const body = new FormData();
        body.append('file', file);
        tile.dataset.state = 'reading';
        tile.dataset.sent = '1';
        tile.classList.add('spark-busy', 'passport-tile--on');
        this.preview(tile, URL.createObjectURL(file), file.type);
        this.filled.delete(tile.dataset.page);
        // Сбой называет свой тост ниже, поэтому запрос — без своего.
        const data = await getJson(`${this.urlValue}/${tile.dataset.page}`, { method: 'POST', body, quiet: true });
        if (data) this.apply(data);
        else {
            tile.dataset.state = 'failed';
            tile.classList.remove('spark-busy');
            window.toast?.('Скан не загрузился');
        }
    }

    poll() {
        clearTimeout(this.timer);
        this.timer = setTimeout(async () => {
            const data = await getJson(`${this.urlValue}/live`, { quiet: true });
            if (data) this.apply(data);
        }, 2000);
    }

    apply(data) {
        for (const tile of this.tileTargets) {
            const page = data.pages?.[tile.dataset.page];
            if (!page) continue;
            tile.dataset.state = page.state;
            tile.classList.toggle('spark-busy', page.state === 'reading');
            tile.classList.add('passport-tile--on');
            // Страница с сервера (PDF разделили на обе плитки) — её миниатюра, если плитка показывает другое.
            if (page.thumb && tile.dataset.thumb !== page.thumb) {
                tile.dataset.thumb = page.thumb;
                tile.dataset.url = page.url;
                if (!tile.querySelector('img')?.src.startsWith('blob:') || tile.dataset.sent !== '1') this.preview(tile, page.thumb, 'image/jpeg');
            }
            delete tile.dataset.sent;
            if (page.state === 'done' && !this.filled.has(tile.dataset.page)) {
                this.filled.add(tile.dataset.page);
                this.fill(page.fields || {}, page.checked || []);
            }
        }
        this.toggleReread(!!data.reading);
        if (data.reading) this.poll();
        else this.over = false;
    }

    // ФИО и сверенное контрольными цифрами (серия и номер, даты, код) — поверх введённого: скан главнее ручного ввода
    // (07.11.2026, владелец: вписали «Гусейн Максутов», паспорт — «Максутов Гусейн Максутович», должно встать с паспорта);
    // буква, в которой ошибся OCR, — по вписанному (`ReadPassport::spelled`). Прочее — только в пустые поля.
    async fill(fields, checked) {
        const form = document.getElementById(this.formValue);
        if (!form) return;
        const name = (key) => (this.prefixValue ? `${this.prefixValue}[${key}]` : key);
        const sure = filler(form, { overwrite: true });
        const rest = this.over ? sure : filler(form);
        for (let [key, value] of Object.entries(fields)) {
            const to = key === 'name' || checked.includes(key) ? sure : rest;
            if (key === 'name') value = spelled(value, form.elements[name('name')]?.value);
            // Серия и номер — двумя полями, где форма их так держит (`x-billing.passport-fields`).
            if (key === 'passport' && form.elements[name('passport_series')]) {
                const [, series, number] = String(value).match(/^(\d{2}\s?\d{2})\s*(\d{6})$/) || [];
                if (series) { to.plain(name('passport_series'), series.replace(/\s/g, '')); to.plain(name('passport_number'), number); }
                continue;
            }
            to.plain(name(key), value);
        }
        await sure.run();
        await rest.run();
        // Даты — скрытое поле под календарём (`date_controller`): видимый текст перерисовывается им самим.
        for (const el of form.querySelectorAll('[data-controller~="date"]')) this.application.getControllerForElementAndIdentifier(el, 'date')?.render();
        for (const el of form.querySelectorAll('[data-controller~="day-input"]')) this.application.getControllerForElementAndIdentifier(el, 'day-input')?.render();
    }

    preview(tile, src, type) {
        const box = tile.querySelector('.passport-tile-view');
        if (!type.startsWith('image/')) return;
        box.innerHTML = '';
        const img = document.createElement('img');
        img.src = src;
        img.alt = '';
        box.append(img);
    }
}

// Слово паспорта той же длины, что вписанное, и с одной другой буквой — вписанным написанием (как `ReadPassport::spelled`).
function spelled(read, typed) {
    const norm = (w) => [...w.toLowerCase().replace(/ё/g, 'е')];
    const typedWords = String(typed || '').trim().split(/\s+/).filter(Boolean);
    return String(read || '').trim().split(/\s+/).map((w) => {
        const a = norm(w);
        const t = typedWords.find((x) => { const b = norm(x); return a.length === b.length && a.filter((c, i) => c !== b[i]).length === 1; });
        return t ? t.charAt(0).toUpperCase() + t.slice(1).toLowerCase() : w;
    }).join(' ');
}
