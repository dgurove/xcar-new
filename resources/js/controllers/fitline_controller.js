import { Controller } from '@hotwired/stimulus';

// Строка фактов под названием (госномер, логотип страховой, номер убытка): номера не переносятся и не режутся —
// если не влезают, шрифт всей строки ужимается ровно настолько, чтобы влезли. Меряется только кусок `.fit-core`
// (от начала строки до его конца); то, что после него (ошибки данных, парковка), может уйти за край.
export default class extends Controller {
    static values = { min: { type: Number, default: 9 } };

    connect() {
        this.observer = new ResizeObserver(() => this.fit());
        this.observer.observe(this.element.parentElement);
        this.fit();
        // Первый замер мог пройти до загрузки Onest — по запасному шрифту, который шире: без пересчёта строка так и
        // оставалась ужатой при свободном месте справа.
        document.fonts?.ready.then(() => this.element.isConnected && this.fit());
    }

    disconnect() {
        this.observer?.disconnect();
    }

    fit() {
        const el = this.element;
        const core = el.querySelector('.fit-core');
        el.style.fontSize = '';
        if (!core || el.clientWidth === 0) return;
        const base = parseFloat(getComputedStyle(el).fontSize);
        // Хвост за номерами не влез — строка кончится многоточием: ему и отступу перед хвостом нужно место, иначе оно ложится на последние цифры номера.
        const tail = el.scrollWidth > el.clientWidth && core.nextElementSibling ? base * 1.6 : 0;
        const need = core.getBoundingClientRect().right - el.getBoundingClientRect().left + tail;
        const room = el.clientWidth;
        if (need > room) el.style.fontSize = `${Math.max(this.minValue, Math.floor(base * room / need * 10) / 10)}px`;
    }
}
