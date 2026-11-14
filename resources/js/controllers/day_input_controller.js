import { Controller } from '@hotwired/stimulus';

// Дата текстом «дд.мм.гггг» (`x-ui.day-input`): точки сами, в скрытое поле — «гггг-мм-дд» только верной даты в min…max.
// Неверное — своя ошибка поля (formCheck берёт validationMessage), скрытое пусто. Скрытое поменял кто-то другой (скан
// паспорта) — `render()` переписывает видимое.
export default class extends Controller {
    static targets = ['value', 'text'];
    static values = { min: String, max: String };

    connect() {
        this.render();
        this.onMorph = () => this.render();
        document.addEventListener('turbo:morph', this.onMorph);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
    }

    render() {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(this.valueTarget.value);
        if (m) this.textTarget.value = `${m[3]}.${m[2]}.${m[1]}`;
        else if (this.valueTarget.value === '' && document.activeElement !== this.textTarget) this.textTarget.value = '';
        this.check();
    }

    // Набор: цифры, точки — сами по 2/2/4. Своя точка после одной цифры дня или месяца — с нулём («1.4.» → «01.04.»).
    type() {
        const el = this.textTarget;
        const parts = el.value.replace(/[,/\-\s]/g, '.').replace(/[^\d.]/g, '').split('.');
        const d = parts.map((p, i) => (i < parts.length - 1 && p.length === 1 ? `0${p}` : p)).join('').slice(0, 8);
        const out = d.slice(0, 2) + (d.length > 2 ? `.${d.slice(2, 4)}` : '') + (d.length > 4 ? `.${d.slice(4)}` : '');
        if (out !== el.value) {
            el.value = out;
            el.setSelectionRange(out.length, out.length);
        }
        this.sync();
    }

    // Выход из поля: год двумя цифрами — ближайший прошлый век («90» → 1990, «05» → 2005).
    finish() {
        const m = /^(\d{2})\.(\d{2})\.(\d{2})$/.exec(this.textTarget.value);
        if (m) {
            const now = new Date().getFullYear() % 100;
            this.textTarget.value = `${m[1]}.${m[2]}.${Number(m[3]) > now ? '19' : '20'}${m[3]}`;
        }
        this.sync();
    }

    sync() {
        const iso = this.parse(this.textTarget.value);
        const next = iso && this.inRange(iso) ? iso : '';
        if (this.valueTarget.value !== next) {
            this.valueTarget.value = next;
            // Несохранённое (`save_bar`, `sheet.js` dirty) смотрит на поле с именем.
            this.valueTarget.dispatchEvent(new Event('input', { bubbles: true }));
            this.valueTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this.check();
    }

    check() {
        const text = this.textTarget.value;
        let message = '';
        if (text !== '') {
            const iso = this.parse(text);
            if (!/^\d{2}\.\d{2}\.\d{4}$/.test(text)) message = 'Дата — дд.мм.гггг';
            else if (!iso) message = 'Нет такой даты';
            else if (this.minValue && iso < this.minValue) message = `Не раньше ${this.dmy(this.minValue)}`;
            else if (this.maxValue && iso > this.maxValue) message = `Не позже ${this.dmy(this.maxValue)}`;
        }
        this.textTarget.setCustomValidity(message);
    }

    parse(text) {
        const m = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(text);
        if (!m) return null;
        const [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])];
        const date = new Date(Date.UTC(y, mo - 1, d));
        if (date.getUTCFullYear() !== y || date.getUTCMonth() !== mo - 1 || date.getUTCDate() !== d) return null;
        return `${m[3]}-${m[2]}-${m[1]}`;
    }

    inRange(iso) {
        return !(this.minValue && iso < this.minValue) && !(this.maxValue && iso > this.maxValue);
    }

    dmy(iso) {
        return iso.split('-').reverse().join('.');
    }
}
