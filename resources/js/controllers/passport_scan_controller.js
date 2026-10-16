import { Controller } from '@hotwired/stimulus';
import { filler } from '../fill.js';
import { getJson } from '../lib/http';

// Скан паспорта (x-billing.passport-scan): страница уходит на сервер, читает задача `ReadPassport`, плитка опрашивает
// `live` раз в 2 с, пока читается, и ставит прочитанное в пустые поля формы — с подсветкой, набранное руками не трогает.
export default class extends Controller {
    static values = { url: String, form: String, prefix: String };

    static targets = ['tile'];

    connect() {
        this.filled = new Set();
        if (this.tileTargets.some((t) => t.dataset.state === 'reading')) this.poll();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async upload(event) {
        const input = event.target;
        const tile = input.closest('[data-page]');
        const file = input.files?.[0];
        if (!file) return;
        const body = new FormData();
        body.append('file', file);
        tile.dataset.state = 'reading';
        tile.classList.add('spark-busy', 'scan-tile--on');
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
        input.value = '';
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
            if (page.state === 'done' && !this.filled.has(tile.dataset.page)) {
                this.filled.add(tile.dataset.page);
                this.fill(page.fields || {}, page.checked || []);
            }
        }
        if (data.reading) this.poll();
    }

    // Сверенное контрольными цифрами (серия и номер, даты, код, ФИО по зоне) — поверх введённого: скан главнее ручного
    // ввода; прочее — только в пустые поля.
    async fill(fields, checked) {
        const form = document.getElementById(this.formValue);
        if (!form) return;
        const name = (key) => (this.prefixValue ? `${this.prefixValue}[${key}]` : key);
        const sure = filler(form, { overwrite: true });
        const rest = filler(form);
        for (const [key, value] of Object.entries(fields)) (checked.includes(key) ? sure : rest).plain(name(key), value);
        await sure.run();
        await rest.run();
        // Даты — скрытое поле под календарём (`date_controller`): видимый текст перерисовывается им самим.
        for (const el of form.querySelectorAll('[data-controller~="date"]')) this.application.getControllerForElementAndIdentifier(el, 'date')?.render();
    }

    preview(tile, src, type) {
        const box = tile.querySelector('.scan-tile-img');
        if (!type.startsWith('image/')) return;
        box.innerHTML = '';
        const img = document.createElement('img');
        img.src = src;
        img.alt = '';
        box.append(img);
    }
}
