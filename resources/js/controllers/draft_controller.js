import { Controller } from '@hotwired/stimulus';

// Черновик формы: набранное переживает выгрузку приложения из фона (iOS делает
// это без предупреждения) и уход со страницы. Пишется в localStorage по вводу и
// при уходе в фон, возвращается в поля, которые сервер не менял (значение поля
// совпадает с тем, от которого ушли), пропадает после успешной отправки и через
// сутки. Файлы, токен и пароли не сохраняются. Ошибки валидации важнее черновика:
// сервер уже вернул old().
const TTL = 24 * 3600 * 1000;

export default class extends Controller {
    static values = { key: String };

    connect() {
        const user = document.querySelector('meta[name="user-id"]')?.content ?? '';
        this.key = `draft:${user}:${this.keyValue || new URL(this.element.action || location.href).pathname}`;
        this.restore();
        this.onInput = () => { clearTimeout(this.timer); this.timer = setTimeout(() => this.save(), 300); };
        this.onHide = () => document.visibilityState === 'hidden' && this.save();
        this.onCache = () => this.save();
        this.onEnd = (e) => e.detail.success && this.clear();
        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('change', this.onInput);
        this.element.addEventListener('trix-change', this.onInput);
        this.element.addEventListener('turbo:submit-end', this.onEnd);
        this.element.addEventListener('draft:clear', () => this.clear());
        document.addEventListener('visibilitychange', this.onHide);
        document.addEventListener('turbo:before-cache', this.onCache);
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.onHide);
        document.removeEventListener('turbo:before-cache', this.onCache);
    }

    fields() {
        return [...this.element.elements].filter((el) => el.name && !el.disabled
            && !['file', 'password', 'submit', 'button'].includes(el.type)
            && !['_token', '_method'].includes(el.name)
            && (el.type !== 'hidden' || this.trix(el)));
    }

    trix(input) {
        return input.id ? this.element.querySelector(`trix-editor[input="${input.id}"]`) : null;
    }

    // [значение, значение с сервера] — по второму видно, менял ли сервер поле.
    read(el) {
        if (el.type === 'checkbox' || el.type === 'radio') return [el.checked, el.defaultChecked];
        if (el.tagName === 'SELECT') return [el.value, [...el.options].find((o) => o.defaultSelected)?.value ?? el.options[0]?.value ?? ''];
        return [el.value, el.defaultValue];
    }

    // Ключ поля: радио и чекбоксы одного имени различаются значением.
    key(el) {
        return el.type === 'checkbox' || el.type === 'radio' ? `${el.name}=${el.value}` : el.name;
    }

    save() {
        const data = {};
        let touched = false;
        for (const el of this.fields()) {
            const [value, base] = this.read(el);
            data[this.key(el)] = [value, base];
            if (value !== base) touched = true;
        }
        try {
            if (touched) localStorage.setItem(this.key, JSON.stringify({ at: Date.now(), data }));
            else localStorage.removeItem(this.key);
        } catch {}
    }

    restore() {
        let saved;
        try { saved = JSON.parse(localStorage.getItem(this.key) || 'null'); } catch { return; }
        if (!saved) return;
        if (Date.now() - saved.at > TTL || this.element.querySelector('.field-invalid')) { this.clear(); return; }
        let restored = false;
        for (const el of this.fields()) {
            const entry = saved.data[this.key(el)];
            if (!entry) continue;
            const [value, base] = entry;
            const [current, serverBase] = this.read(el);
            if (value === current || serverBase !== base) continue;
            if (el.type === 'checkbox' || el.type === 'radio') el.checked = value;
            else if (this.trix(el)) this.trix(el).editor?.loadHTML(value);
            else el.value = value;
            el.dispatchEvent(new Event('change', { bubbles: true }));
            restored = true;
        }
        if (restored) this.element.dataset.dirty = '1';
    }

    clear() {
        try { localStorage.removeItem(this.key); } catch {}
    }
}
