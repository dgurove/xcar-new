import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { filler } from '../fill.js';
import { liveOpen } from '../live.js';

// Читалка «Завести» (x-mail.reader-body, Scan\Reader) — блок «Документы» разбора письма парковки и редактора
// предложения. Документы читаются сами по порядку (autoValue: открыли из «Завести»), поля формы formValue
// заполняются по мере чтения: пустое — со вспышкой (fill.js), совпадающее — молча, другое — строкой расхождения
// «в форме → в документе» с «Взять» в [data-reader-diffs="<форма>"] над полями; документы спорят о пустом поле —
// строка на каждый вариант. Взятое и снятое не возвращается. Ход чтения — строка хода с сервера морфом и отметки на
// строках документов (`data-scan`). Новое — по событию live:scan своего предмета, при возврате на вкладку и, пока хаб недоступен, раз в 3 с.
// Искра шторки документов дописывает открытый файл в чтение (reader:add, docs_controller).
const norm = (v) => String(v ?? '').toLowerCase().replace(/[\s\-.]+/g, '');

export default class extends Controller {
    static targets = ['body', 'values', 'marks', 'row', 'spark'];
    static values = { url: String, subject: String, form: String, auto: Boolean };

    connect() {
        this.dropped = new Set();
        this.onScan = (e) => { if (e.detail?.subject === this.subjectValue) this.refresh(); };
        this.onVisible = () => { if (document.visibilityState === 'visible' && this.active) this.refresh(); };
        this.onAdd = (e) => this.read([e.detail.id]);
        this.onChange = () => this.compare();
        // Строки расхождений стоят над полями, вне читалки: data-action до неё не дойдёт — ловим нажатие на группе.
        this.onTake = (e) => { const row = e.target.closest('[data-index]'); if (row) this.take(row.dataset.index); };
        this.box?.addEventListener('click', this.onTake);
        document.addEventListener('live:scan', this.onScan);
        document.addEventListener('visibilitychange', this.onVisible);
        this.element.addEventListener('reader:add', this.onAdd);
        this.form?.addEventListener('change', this.onChange);
        this.apply({ state: this.bodyTarget.dataset.state, values: JSON.parse(this.valuesTarget.textContent || '{}'), marks: JSON.parse(this.marksTarget.textContent || '{}') });
        if (this.autoValue && this.state === 'idle') this.read();
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('live:scan', this.onScan);
        document.removeEventListener('visibilitychange', this.onVisible);
        this.element.removeEventListener('reader:add', this.onAdd);
        this.form?.removeEventListener('change', this.onChange);
        this.box?.removeEventListener('click', this.onTake);
    }

    get form() {
        return document.getElementById(this.formValue);
    }

    get box() {
        return document.querySelector(`[data-reader-diffs="${this.formValue}"]`);
    }

    get active() {
        return ['reading', 'queued'].includes(this.state);
    }

    // Искра — человек сам попросил прочитать: когда дочитается и в форме ничего не поменяется, скажем об этом.
    read(event) {
        const ids = Array.isArray(event) ? event : [];
        if (!ids.length && event) this.asked = true;
        return this.send('read', ids);
    }

    stop() {
        return this.send('stop');
    }

    async send(action, ids = []) {
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        ids.forEach((id) => body.append('ids[]', id));
        try {
            const r = await fetch(`${this.urlValue}/${action}`, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (r.ok) this.apply(await r.json());
        } catch {}
    }

    // Запросы не наслаиваются: пришло событие, пока ждём ответ, — ещё один после него.
    async refresh() {
        if (this.loading) { this.again = true; return; }
        this.loading = true;
        try {
            const r = await fetch(`${this.urlValue}/live`, { headers: { Accept: 'application/json' } });
            if (r.ok) this.apply(await r.json());
        } catch {}
        this.loading = false;
        if (this.again) { this.again = false; this.refresh(); }
    }

    apply({ state, html, values, marks }) {
        this.state = state;
        this.bodyTarget.dataset.state = state;
        if (html != null) {
            const next = document.createElement('div');
            next.innerHTML = html;
            Turbo.morphChildren(this.bodyTarget, next);
        }
        if (marks) this.mark(marks);
        if (this.hasSparkTarget) this.sparkTarget.classList.toggle('spark-busy', this.active);
        clearTimeout(this.timer);
        if (this.active && !liveOpen()) this.timer = setTimeout(() => this.refresh(), 3000);
        if (values) {
            this.values = values;
            this.fill();
        }
    }

    // Ход чтения — на строках документов страницы (`data-scan-key` — отпечаток файла): читаемый, ждущий, прочитанный.
    // Пока не читали, строки как строки; прочитанное на этой странице остаётся с галкой.
    mark(marks) {
        if (['reading', 'queued', 'stopped'].includes(this.state)) this.seen = true;
        document.querySelectorAll('[data-scan-key]').forEach((row) => {
            if (this.bodyTarget.contains(row)) return;
            const scan = this.seen ? marks[row.dataset.scanKey] : null;
            if (scan) row.dataset.scan = scan;
            else delete row.dataset.scan;
        });
    }

    // Пустые поля — значением документа, если он один; дальше — сравнение.
    async fill() {
        const form = this.form;
        if (!form) return;
        const fill = filler(form);
        for (const [field, f] of Object.entries(this.values)) {
            const [one] = f.options;
            if (f.options.length !== 1 || this.dropped.has(this.key(field, one))) continue;
            if (field === 'car') {
                const brand = this.input('brand_id');
                if (!brand || brand.value === '') {
                    fill.combo('brand_id', one.brand.id, one.brand.text);
                    if (one.model) fill.combo('model_id', one.model.id, one.model.text);
                } else if (String(brand.value) === String(one.brand.id) && one.model) {
                    fill.combo('model_id', one.model.id, one.model.text);
                }
                continue;
            }
            if (!this.input(f.name)) continue;
            if (one.id) fill.combo(f.name, one.id, one.text);
            else fill.plain(f.name, one.value);
        }
        await fill.run();
        this.compare();
        if (this.asked && !this.active) {
            this.asked = false;
            if (!fill.count && !this.rows?.length) window.toast?.('Всё совпадает с документами');
        }
    }

    // Строки расхождений по тому, что сейчас в форме.
    compare() {
        const box = this.box;
        if (!box || !this.values) return;
        this.rows = [];
        for (const [field, f] of Object.entries(this.values)) {
            const now = this.now(field, f);
            // Поля нет или оно пустое, а документ один (его вписали) — строки нет. Иначе — строка на каждый вариант
            // документа, которого в форме нет: и когда форма с документами расходится, и когда документы спорят.
            if (now === null || (now.text === '' && f.options.length < 2)) continue;
            f.options.filter((o) => !this.dropped.has(this.key(field, o)) && !this.same(field, f, o)).forEach((o) => this.rows.push({ field, f, o, now: now.text }));
        }
        box.replaceChildren(...this.rows.map((row, i) => {
            const el = this.rowTarget.content.firstElementChild.cloneNode(true);
            el.dataset.index = i;
            el.querySelector('[data-slot="label"]').textContent = row.f.label;
            el.querySelector('[data-slot="now"]').textContent = row.now;
            el.querySelector('[data-slot="now"]').hidden = row.now === '';
            el.querySelector('[data-slot="arrow"]').hidden = row.now === '';
            el.querySelector('[data-slot="doc"]').textContent = row.o.text;
            el.querySelector('[data-slot="from"]').textContent = (row.o.from || []).join(', ');
            return el;
        }));
        box.hidden = !this.rows.length;
    }

    // «Взять»: значение документа поверх формы; вариант больше не предлагается, остальные варианты поля — тоже.
    async take(index) {
        const row = this.rows?.[index];
        if (!row) return;
        const fill = filler(this.form, { overwrite: true });
        if (row.field === 'car') {
            fill.combo('brand_id', row.o.brand.id, row.o.brand.text);
            if (row.o.model) fill.combo('model_id', row.o.model.id, row.o.model.text);
        } else if (row.o.id) {
            fill.combo(row.f.name, row.o.id, row.o.text);
        } else {
            fill.plain(row.f.name, row.o.value);
        }
        row.f.options.forEach((o) => this.dropped.add(this.key(row.field, o)));
        await fill.run();
        this.compare();
    }

    key(field, option) {
        return `${field}|${option.text}`;
    }

    input(name) {
        const el = this.form?.elements.namedItem(name);
        // Спрятанное поле (оценочная стоимость не у того вендора) не заполняется и не сравнивается.
        return el && !el.disabled && !el.closest('[hidden]') ? el : null;
    }

    // Что в форме: значение и как его показать в строке; поля нет — null.
    now(field, f) {
        if (field === 'car') {
            const brand = this.input('brand_id');
            if (!brand) return null;
            const text = [document.getElementById('f-brand_id')?.value, document.getElementById('f-model_id')?.value].filter(Boolean).join(' ');
            return { text: brand.value === '' ? '' : text };
        }
        const el = this.input(f.name);
        if (!el) return null;
        if (el.value === '') return { text: '' };
        if (el.tagName === 'SELECT') return { text: el.selectedOptions[0]?.text ?? el.value };
        // Комбобокс: id в скрытом поле, видимый текст — в f-<имя>.
        return { text: el.type === 'hidden' ? (document.getElementById(`f-${f.name}`)?.value || el.value) : el.value };
    }

    same(field, f, o) {
        if (field === 'car') {
            const brand = this.input('brand_id')?.value;
            const model = this.input('model_id')?.value;
            return String(brand) === String(o.brand.id) && (!o.model || String(model) === String(o.model.id));
        }
        const el = this.input(f.name);
        if (!el) return true;
        return o.id ? String(el.value) === String(o.id) : norm(el.value) === norm(o.value);
    }
}
