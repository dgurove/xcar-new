import { Controller } from '@hotwired/stimulus';
import { copyText } from '../lib/clipboard.js';
import { getJson } from '../lib/http.js';

// Окно «Выгрузка» (`x-offer.export-sheet`): при открытии берёт все строки списка по всем столбцам тем же адресом, что у
// страницы (`?format=json`, чипы и вкладка — из адреса, набранный поиск — из поля лупы), и дальше всё у себя: образец —
// первые строки выбранных столбцов, «Скопировать» — без заголовков, один столбец строками, несколько — через табуляцию
// (вставка в Excel раскладывает по столбцам; владелец 21.10.2026), в том же нажатии (иначе iOS не пускает в буфер).
// «Excel» — файл выбранных столбцов (`cols=`). Выбор помнит браузер по списку.
const SAMPLE = 5;

export default class extends Controller {
    static targets = ['box', 'all', 'sample', 'copy', 'file'];
    static values = { url: String, list: String };

    connect() {
        this.data_ = null;
        try {
            const saved = JSON.parse(localStorage.getItem(this.memoryKey()) || 'null');
            if (Array.isArray(saved) && saved.length) {
                for (const box of this.boxTargets) box.checked = saved.includes(box.value);
            }
        } catch {
            // Без памяти — отмеченные по умолчанию.
        }
        this.sync();
    }

    memoryKey() {
        return 'export:' + this.listValue;
    }

    // Адрес выгрузки: параметры списка со страницы, без карточки и страницы; лупа адрес не меняет — её поле отдельно.
    query() {
        const params = new URLSearchParams(location.search);
        for (const key of ['peek', 'page', 'pill']) params.delete(key);
        const q = document.querySelector('.toolbar[data-searching] input[name="q"]')?.value.trim();
        if (q) params.set('q', q);
        return params;
    }

    async load() {
        const params = this.query();
        const key = params.toString();
        if (this.data_ && this.loadedFor === key) return;
        this.data_ = null;
        this.loadedFor = key;
        this.sync();
        params.set('format', 'json');
        const data = await getJson(this.urlValue + '?' + params);
        if (this.loadedFor !== key) return;
        if (!data) {
            this.loadedFor = null;
            this.sampleTarget.textContent = 'Не загрузилось, откройте ещё раз';
            return;
        }
        this.data_ = data;
        this.sync();
    }

    keys() {
        return this.boxTargets.filter((b) => b.checked).map((b) => b.value);
    }

    pick() {
        try {
            localStorage.setItem(this.memoryKey(), JSON.stringify(this.keys()));
        } catch {
            // Выбор просто не запомнится.
        }
        this.sync();
    }

    all() {
        const on = this.keys().length !== this.boxTargets.length;
        for (const box of this.boxTargets) box.checked = on;
        this.pick();
    }

    // Строки выбранных столбцов из данных окна.
    rows() {
        const index = this.keys().map((k) => this.data_.cols.indexOf(k)).filter((i) => i >= 0);
        return this.data_.rows.map((row) => index.map((i) => row[i] ?? ''));
    }

    sync() {
        const keys = this.keys();
        this.allTarget.textContent = keys.length === this.boxTargets.length ? 'Снять все' : 'Выбрать все';
        const ready = !!this.data_ && keys.length > 0 && this.data_.rows.length > 0;
        this.copyTarget.disabled = !ready;
        this.fileTarget.setAttribute('aria-disabled', ready ? 'false' : 'true');
        const params = this.query();
        if (keys.length) params.set('cols', keys.join(','));
        this.fileTarget.href = this.urlValue + '?' + params;
        if (!this.data_) return;
        this.renderSample(keys);
    }

    renderSample(keys) {
        const box = this.sampleTarget;
        box.replaceChildren();
        const total = this.data_.rows.length;
        if (!total || !keys.length) {
            box.textContent = total ? 'Отметьте хотя бы один столбец' : 'В списке нет строк';
            return;
        }
        const labels = new Map(this.boxTargets.map((b) => [b.value, b.dataset.label]));
        const table = document.createElement('table');
        table.className = 'export-table';
        const head = table.createTHead().insertRow();
        for (const k of keys) head.appendChild(Object.assign(document.createElement('th'), { textContent: labels.get(k) }));
        const body = table.createTBody();
        for (const row of this.rows().slice(0, SAMPLE)) {
            const tr = body.insertRow();
            for (const v of row) tr.insertCell().textContent = String(v);
        }
        const scroll = document.createElement('div');
        scroll.className = 'export-scroll';
        scroll.append(table);
        box.append(scroll);
        if (total > SAMPLE) {
            const more = total - SAMPLE;
            box.append(Object.assign(document.createElement('div'), {
                className: 'mt-2 text-sm text-ink-dim',
                textContent: `и ещё ${more} ${plural(more, ['строка', 'строки', 'строк'])}`,
            }));
        }
    }

    copy() {
        if (!this.data_) return;
        const rows = this.rows();
        const n = rows.length;
        copyText(rows.map((r) => r.join('\t')).join('\n'), `В буфере ${n} ${plural(n, ['строка', 'строки', 'строк'])}`);
    }

    file(event) {
        if (this.fileTarget.getAttribute('aria-disabled') === 'true') event.preventDefault();
    }
}

function plural(n, [one, few, many]) {
    const a = n % 10, b = n % 100;
    return a === 1 && b !== 11 ? one : a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many;
}
