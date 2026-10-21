import { Controller } from '@hotwired/stimulus';
import { copyText } from '../lib/clipboard.js';
import { debounce } from '../lib/debounce';
import { getJson } from '../lib/http.js';
import { plural } from '../lib/plural.js';

// Окно «Выгрузка» (`x-offer.export-sheet`): строки выбранных столбцов — тем же адресом, что у страницы (`?format=json&cols=`,
// чипы и вкладка — из адреса, набранный поиск — из поля лупы), заново при каждом открытии и смене столбцов: список живой,
// строки уходят и приходят. Образец — первые строки; «Скопировать» — без заголовков, один столбец строками, несколько —
// через табуляцию (вставка в Excel раскладывает по столбцам; владелец 21.10.2026), в том же нажатии из уже пришедших
// данных (иначе iOS не пускает в буфер), пока они идут — кнопка ждёт. «Excel» — файл (`x-ui.doc`, шторка документов),
// данных окна ему не нужно. Выбор помнит браузер по списку.
const SAMPLE = 5;

export default class extends Controller {
    static targets = ['box', 'all', 'sample', 'copy', 'file'];
    static values = { url: String, list: String };

    connect() {
        this.rows_ = null;
        this.seq = 0;
        this.later = debounce(() => this.load(), 200);
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

    disconnect() {
        this.later.cancel();
        this.pending?.abort();
    }

    memoryKey() {
        return 'export:' + this.listValue;
    }

    keys() {
        return this.boxTargets.filter((b) => b.checked).map((b) => b.value);
    }

    // Параметры списка со страницы, без карточки и страницы; лупа адрес не меняет — её поле отдельно.
    query() {
        const params = new URLSearchParams(location.search);
        for (const key of ['peek', 'page', 'pill']) params.delete(key);
        const q = document.querySelector('.toolbar[data-searching] input[name="q"]')?.value.trim();
        if (q) params.set('q', q);
        const keys = this.keys();
        if (keys.length) params.set('cols', keys.join(','));
        return params;
    }

    open() {
        this.sync();
        this.load();
    }

    async load() {
        this.later.cancel();
        this.pending?.abort();
        const seq = ++this.seq;
        this.rows_ = null;
        this.sync();
        if (!this.keys().length) return;
        this.pending = new AbortController();
        const params = this.query();
        params.set('format', 'json');
        const data = await getJson(this.urlValue + '?' + params, { signal: this.pending.signal, quiet: true });
        if (seq !== this.seq) return;
        if (!data) {
            this.sampleTarget.textContent = 'Не загрузилось, откройте ещё раз';
            return;
        }
        this.cols_ = data.cols;
        this.rows_ = data.rows;
        this.sync();
    }

    pick() {
        try {
            localStorage.setItem(this.memoryKey(), JSON.stringify(this.keys()));
        } catch {
            // Выбор просто не запомнится.
        }
        this.rows_ = null;
        this.sync();
        this.later();
    }

    all() {
        const on = this.keys().length !== this.boxTargets.length;
        for (const box of this.boxTargets) box.checked = on;
        this.pick();
    }

    sync() {
        const keys = this.keys();
        this.allTarget.textContent = keys.length === this.boxTargets.length ? 'Снять все' : 'Выбрать все';
        this.copyTarget.disabled = !this.rows_?.length;
        this.fileTarget.setAttribute('aria-disabled', keys.length ? 'false' : 'true');
        this.fileTarget.href = this.urlValue + '?' + this.query();
        if (!keys.length) {
            this.sampleTarget.textContent = 'Отметьте хотя бы один столбец';
            return;
        }
        if (!this.rows_) {
            this.skeleton();
            return;
        }
        this.renderSample();
    }

    skeleton() {
        if (this.sampleTarget.querySelector('.skeleton')) return;
        this.sampleTarget.replaceChildren(...['w-3/4', 'w-2/3', 'w-1/2'].map((w, i) => Object.assign(document.createElement('div'), { className: `skeleton h-4 ${w}${i ? ' mt-2' : ''}` })));
    }

    renderSample() {
        const box = this.sampleTarget;
        box.replaceChildren();
        const total = this.rows_.length;
        if (!total) {
            box.textContent = 'В списке нет строк';
            return;
        }
        const labels = new Map(this.boxTargets.map((b) => [b.value, b.dataset.label]));
        const table = document.createElement('table');
        table.className = 'export-table';
        const head = table.createTHead().insertRow();
        for (const k of this.cols_) head.appendChild(Object.assign(document.createElement('th'), { textContent: labels.get(k) }));
        const body = table.createTBody();
        for (const row of this.rows_.slice(0, SAMPLE)) {
            const tr = body.insertRow();
            for (const v of row) tr.insertCell().textContent = v ?? '';
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

    // Табуляция и перевод строки внутри значения порвали бы столбцы при вставке — пробелом.
    copy() {
        if (!this.rows_?.length) return;
        const n = this.rows_.length;
        const text = this.rows_.map((r) => r.map((v) => String(v ?? '').replace(/[\t\r\n]+/g, ' ')).join('\t')).join('\n');
        copyText(text, `В буфере ${n} ${plural(n, ['строка', 'строки', 'строк'])}`);
    }

    file(event) {
        if (this.fileTarget.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
}
