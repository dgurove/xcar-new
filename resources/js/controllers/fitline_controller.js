import { Controller } from '@hotwired/stimulus';
import { watch, unwatch } from '../lib/fit-batch';

// Строка фактов под названием (госномер, логотип страховой, номер убытка): номера не переносятся и не режутся —
// если не влезают, шрифт всей строки ужимается ровно настолько, чтобы влезли. Меряется только кусок `.fit-core`
// (от начала строки до его конца); то, что после него (ошибки данных, парковка), может уйти за край. Замер — общим
// тактом всех строк (lib/fit-batch).
export default class extends Controller {
    // Не мельче 11 — нижней ступени шкалы (20.10.2026: 9 px в «Сделках» читались с трудом).
    static values = { min: { type: Number, default: 11 } };

    connect() {
        this.item = { element: this.element, measure: () => this.measure() };
        watch(this.item);
    }

    disconnect() {
        unwatch(this.item);
    }

    measure() {
        const el = this.element;
        const core = el.querySelector('.fit-core');
        if (!core || el.clientWidth === 0) return 0;
        const base = parseFloat(getComputedStyle(el).fontSize);
        // Хвост за номерами не влез — строка кончится многоточием: ему и отступу перед хвостом нужно место, иначе оно ложится на последние цифры номера.
        const tail = el.scrollWidth > el.clientWidth && core.nextElementSibling ? base * 1.6 : 0;
        const need = core.getBoundingClientRect().right - el.getBoundingClientRect().left + tail;
        const room = el.clientWidth;
        return need > room ? Math.max(this.minValue, Math.floor(base * room / need * 10) / 10) : 0;
    }
}
