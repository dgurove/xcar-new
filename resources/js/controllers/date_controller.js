import { Controller } from '@hotwired/stimulus';
import 'cally';

// Выбор даты и времени вместо системного поля (владелец 10.10.2026: «вписывать год и время вручную — дрочево, системный
// календарь уебанский»). Поле — словами («9 окт, 12:00», год — только если не текущий), значение для сервера — скрытое
// поле прежнего вида (`Y-m-d` / `Y-m-dTH:i`). Нажатие — всплывашка: календарь Cally (`<calendar-date>`, выбор месяца и
// года в шапке) и у поля со временем — одно поле `чч:мм` с временем по умолчанию (CRM 17:30, парковка 12:00). Без
// времени день выбирается одним нажатием и всплывашка закрывается. На ПК дату можно напечатать: «8.9», «8.9.25 1730».
// Скрытое поле получает `input` и `change` — формы, черновики и `autosubmit` видят правку как раньше.
const MONTHS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

export default class extends Controller {
    static targets = ['value', 'text', 'pop', 'cal', 'time', 'clear', 'error'];
    static values = { time: Boolean, defaultTime: { type: String, default: '12:00' }, min: String, max: String };

    connect() {
        // Телефон — без клавиатуры: поле только открывает календарь.
        if (matchMedia('(pointer: coarse)').matches) this.textTarget.readOnly = true;
        this.render();
    }

    disconnect() { this.close(); }

    get parts() {
        const [date, time] = (this.valueTarget.value || '').split('T');
        return date ? { date, time: (time || '').slice(0, 5) } : null;
    }

    render() {
        const p = this.parts;
        if (!p) { this.textTarget.value = ''; return; }
        const [y, m, d] = p.date.split('-').map(Number);
        const year = y !== new Date().getFullYear() ? ` ${y}` : '';
        this.textTarget.value = `${d} ${MONTHS[m - 1]}${year}${this.timeValue && p.time ? `, ${p.time}` : ''}`;
    }

    open() {
        if (this.valueTarget.disabled || this.popTarget.matches(':popover-open')) return;
        const p = this.parts;
        this.calTarget.value = p?.date || '';
        this.calTarget.focusedDate = p?.date || iso(new Date());
        if (this.hasTimeTarget) this.timeTarget.value = p?.time || this.defaultTimeValue;
        if (this.hasClearTarget) this.clearTarget.hidden = !p;
        const pop = this.popTarget, r = this.textTarget.getBoundingClientRect(), w = document.documentElement.clientWidth;
        pop.showPopover();
        // ПК — под полем; снизу не влезает — над ним; нигде не влезает — прижать к низу экрана. Телефон — лист снизу (CSS до 640).
        if (w >= 640) {
            const h = pop.offsetHeight, below = r.bottom + 6, above = r.top - 6 - h;
            pop.style.top = `${below + h <= innerHeight - 8 ? below : (above >= 8 ? above : Math.max(8, innerHeight - h - 8))}px`;
            pop.style.left = `${Math.max(8, Math.min(r.left, w - pop.offsetWidth - 8))}px`;
        }
    }

    close() {
        if (this.hasPopTarget && this.popTarget.matches(':popover-open')) this.popTarget.hidePopover();
    }

    // Телефон: клавиатура поля времени не закрывает лист — низ листа встаёт на неё (visualViewport), пока лист открыт.
    lift() {
        const vv = window.visualViewport;
        if (!vv || document.documentElement.clientWidth >= 640) return;
        const place = () => { this.popTarget.style.bottom = `${Math.max(0, innerHeight - vv.height - vv.offsetTop)}px`; };
        place();
        vv.addEventListener('resize', place);
        this.popTarget.addEventListener('toggle', () => { vv.removeEventListener('resize', place); this.popTarget.style.bottom = ''; }, { once: true });
    }

    // День в календаре: без времени — сразу в поле и закрыть; со временем — день с временем из поля, закрывает «Готово».
    pick() {
        const date = this.calTarget.value;
        if (!date) return;
        if (!this.timeValue) { this.set(date); this.close(); return; }
        this.set(`${date}T${this.clock() || this.defaultTimeValue}`);
    }

    // Время набором: двоеточие ставится само («1730» → 17:30), часы и минуты в своих пределах.
    typeTime() {
        const digits = this.timeTarget.value.replace(/\D/g, '').slice(0, 4);
        this.timeTarget.value = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
        const t = this.clock();
        if (t && this.parts) this.set(`${this.parts.date}T${t}`);
    }

    // «Готово» закрывает; время ложится только к выбранному дню — пустое поле от одного взгляда не заполняется.
    done() {
        const t = this.clock();
        if (t && this.parts) this.set(`${this.parts.date}T${t}`);
        this.close();
    }

    clear() {
        this.set('');
        this.close();
    }

    // Набор на ПК: «8.9», «08.09.2025», «8.9 1730», «8/9/25 17:30»; год — текущий, время — по умолчанию.
    typed(event) {
        if (event.type === 'keydown') { if (event.key !== 'Enter') return; event.preventDefault(); }
        const raw = this.textTarget.value.trim();
        if (!raw) { this.set(''); return; }
        const m = raw.match(/^(\d{1,2})[./-](\d{1,2})(?:[./-](\d{2,4}))?(?:[\s,]+(\d{1,2})[:.]?(\d{2}))?$/);
        if (!m) { this.render(); return; }
        const year = m[3] ? (m[3].length === 2 ? 2000 + Number(m[3]) : Number(m[3])) : new Date().getFullYear();
        const day = new Date(year, Number(m[2]) - 1, Number(m[1]));
        if (day.getDate() !== Number(m[1])) { this.render(); return; }
        // Набранное за границами поля — как у календаря, где такие дни не нажать: словами под полем, значение прежнее.
        const out = (this.minValue && iso(day) < this.minValue) ? `Не раньше ${this.human(this.minValue)}` : (this.maxValue && iso(day) > this.maxValue ? `Не позже ${this.human(this.maxValue)}` : null);
        if (out) { this.fail(out); this.render(); return; }
        const time = m[4] ? `${pad(Math.min(23, Number(m[4])))}:${pad(Math.min(59, Number(m[5])))}` : (this.parts?.time || this.defaultTimeValue);
        this.set(this.timeValue ? `${iso(day)}T${time}` : iso(day));
        if (event.type === 'keydown') this.close();
    }

    human(date) {
        const [y, m, d] = date.split('-').map(Number);
        return `${d} ${MONTHS[m - 1]}${y !== new Date().getFullYear() ? ` ${y}` : ''}`;
    }

    fail(message) {
        if (!this.hasErrorTarget) return;
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    clock() {
        const m = (this.hasTimeTarget ? this.timeTarget.value : '').match(/^(\d{1,2}):(\d{2})$/);
        return m && Number(m[1]) < 24 && Number(m[2]) < 60 ? `${pad(m[1])}:${m[2]}` : null;
    }

    set(value) {
        if (this.hasErrorTarget) this.errorTarget.hidden = true;
        const changed = this.valueTarget.value !== value;
        this.valueTarget.value = value;
        this.render();
        if (!changed) return;
        this.valueTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.valueTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
