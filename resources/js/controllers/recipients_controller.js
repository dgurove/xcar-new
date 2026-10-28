import { Controller } from '@hotwired/stimulus';
import { getJson } from '../lib/http.js';

// Адресаты письма тегами, как в Gmail (28.10.2026, владелец: «пробел ставишь — почта превращается в тег»). Поле формы —
// прежнее скрытое `to` / `cc` через запятую (сервер и черновик его и читают); теги рисуются отсюда. Пробел, запятая, «;»,
// Enter и уход с поля превращают набранный адрес в тег, вставка нескольких — в несколько; Backspace в пустом поле
// возвращает последний тег в текст; подсказки — `{base}/addresses?q=`.
const EMAIL = /^[^\s@<>,;"]+@[^\s@<>,;"]+\.[^\s@<>,;"]+$/;

const parse = (text) => {
    const out = [];
    const re = /(?:"?([^"<>,;]*?)"?\s*<([^<>\s]+)>)|([^\s,;<>"]+)/g;
    let m;
    while ((m = re.exec(text))) {
        const email = (m[2] || m[3] || '').trim().replace(/^mailto:/i, '').toLowerCase();
        if (email) out.push({ email, name: (m[1] || '').trim() || null });
    }
    return out;
};

export default class extends Controller {
    static targets = ['value', 'input', 'pop'];
    static values = { url: String, names: Object };

    connect() {
        this.list = [];
        this.load();
        this.form = this.element.closest('form');
        // Отправка: недописанный адрес — тегом; без адреса или с неверным — ошибка у строки, а не пустое письмо.
        this.onSubmit = (e) => {
            this.commit();
            const required = this.valueTarget.dataset.required !== undefined;
            const bad = this.list.find((r) => !EMAIL.test(r.email));
            const error = bad ? `Проверьте адрес ${bad.email}` : (required && !this.list.length ? 'Нужен хотя бы один адрес' : null);
            this.showError(error);
            if (error) { e.preventDefault(); e.stopImmediatePropagation(); this.inputTarget.focus(); }
        };
        this.form?.addEventListener('submit', this.onSubmit, true);
    }

    disconnect() {
        this.form?.removeEventListener('submit', this.onSubmit, true);
    }

    // Значение поля → теги (первый показ, черновик, old()).
    load() {
        this.list = parse(this.valueTarget.value).map((r) => ({ ...r, name: r.name || this.namesValue[r.email] || null }));
        this.render();
    }

    sync() {
        const value = this.list.map((r) => r.email).join(', ');
        if (this.valueTarget.value === value) return;
        this.valueTarget.value = value;
        this.valueTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    render() {
        this.element.querySelectorAll('.recipient').forEach((n) => n.remove());
        this.list.forEach((r, i) => {
            const chip = document.createElement('span');
            chip.className = 'recipient' + (EMAIL.test(r.email) ? '' : ' is-bad');
            chip.title = r.email;
            const label = document.createElement('button');
            label.type = 'button';
            label.className = 'recipient-label';
            label.textContent = r.name || r.email;
            label.addEventListener('click', () => this.edit(i));
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'recipient-x';
            x.setAttribute('aria-label', 'Убрать');
            x.textContent = '×';
            x.addEventListener('click', (e) => { e.stopPropagation(); this.list.splice(i, 1); this.render(); this.sync(); });
            chip.append(label, x);
            this.inputTarget.before(chip);
        });
        this.inputTarget.placeholder = this.list.length ? '' : (this.inputTarget.dataset.placeholder || '');
    }

    focus(e) {
        if (e.target === this.element || e.target.classList?.contains('recipients')) this.inputTarget.focus();
    }

    // Набранное → теги. keep — оставить хвост без разделителя в поле (пока печатают).
    commit(keep = false) {
        const text = this.inputTarget.value;
        const tail = keep ? (text.match(/[^\s,;]*$/)?.[0] ?? '') : '';
        const head = keep ? text.slice(0, text.length - tail.length) : text;
        const add = parse(head).filter((r) => !this.list.some((x) => x.email === r.email));
        add.forEach((r) => { r.name ||= this.namesValue[r.email] || null; });
        if (add.length) { this.list.push(...add); this.render(); this.sync(); }
        this.inputTarget.value = tail;
        if (!keep) this.close();
    }

    key(e) {
        const pop = this.popTarget;
        const items = [...pop.querySelectorAll('[data-email]')];
        const at = items.findIndex((n) => n.classList.contains('is-active'));
        if (!pop.hidden && items.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            const next = (at + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items.forEach((n, i) => n.classList.toggle('is-active', i === next));
            return;
        }
        if (e.key === 'Enter' || e.key === 'Tab') {
            if (!pop.hidden && at >= 0) { e.preventDefault(); this.pick(items[at]); return; }
            if (this.inputTarget.value.trim()) { if (e.key === 'Enter') e.preventDefault(); this.commit(); }
            else if (e.key === 'Enter') e.preventDefault();
            return;
        }
        if (e.key === ' ' || e.key === ',' || e.key === ';') {
            if (this.inputTarget.value.trim()) { e.preventDefault(); this.commit(); }
            else e.preventDefault();
            return;
        }
        if (e.key === 'Escape' && !pop.hidden) { e.preventDefault(); e.stopPropagation(); this.close(); return; }
        if (e.key === 'Backspace' && this.inputTarget.value === '' && this.list.length) {
            e.preventDefault();
            this.edit(this.list.length - 1);
        }
    }

    // Тег обратно в текст — поправить.
    edit(i) {
        this.commit();
        const [r] = this.list.splice(i, 1);
        this.render();
        this.sync();
        this.inputTarget.value = r.email;
        this.inputTarget.focus();
        this.inputTarget.setSelectionRange(r.email.length, r.email.length);
    }

    typed() {
        // Мобильные клавиатуры шлют пробел и запятую без keydown — разделитель в тексте тоже делает тег.
        if (/[\s,;]/.test(this.inputTarget.value)) this.commit(true);
        this.suggest();
    }

    paste(e) {
        const text = e.clipboardData?.getData('text') ?? '';
        if (!/[\s,;<]/.test(text.trim())) return;
        e.preventDefault();
        this.inputTarget.value += text;
        this.commit();
    }

    blur() {
        // Нажатие по подсказке приходит после blur — даём ему случиться.
        setTimeout(() => { if (document.activeElement !== this.inputTarget) this.commit(); }, 150);
    }

    suggest() {
        const q = this.inputTarget.value.trim();
        clearTimeout(this.timer);
        if (q.length < 2 || !this.urlValue) { this.close(); return; }
        this.timer = setTimeout(async () => {
            this.abort?.abort();
            this.abort = new AbortController();
            let rows;
            try { rows = await getJson(`${this.urlValue}?q=${encodeURIComponent(q)}`, { signal: this.abort.signal, quiet: true }); } catch { return; }
            if (this.inputTarget.value.trim() !== q) return;
            rows = (rows || []).filter((r) => !this.list.some((x) => x.email === r.email));
            if (!rows.length) { this.close(); return; }
            this.popTarget.replaceChildren(...rows.map((r, i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'recipients-option' + (i === 0 ? ' is-active' : '');
                b.dataset.email = r.email;
                b.dataset.name = r.name || '';
                const name = document.createElement('span');
                name.className = 'truncate';
                name.textContent = r.name || r.email;
                const sub = document.createElement('span');
                sub.className = 'truncate text-sm text-ink-muted';
                sub.textContent = [r.name ? r.email : null, r.vendor].filter(Boolean).join(' · ');
                b.append(name, sub);
                b.addEventListener('mousedown', (e) => e.preventDefault());
                b.addEventListener('click', () => this.pick(b));
                return b;
            }));
            this.popTarget.hidden = false;
        }, 120);
    }

    pick(node) {
        const email = node.dataset.email;
        if (!this.list.some((x) => x.email === email)) this.list.push({ email, name: node.dataset.name || null });
        this.inputTarget.value = '';
        this.render();
        this.sync();
        this.close();
        this.inputTarget.focus();
    }

    close() {
        this.popTarget.hidden = true;
        this.popTarget.replaceChildren();
    }

    showError(text) {
        const box = this.element.querySelector('[data-recipients-error]');
        if (!box) return;
        box.textContent = text || '';
        box.hidden = !text;
        this.element.classList.toggle('is-invalid', !!text);
    }
}
