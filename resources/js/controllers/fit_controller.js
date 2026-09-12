import { Controller } from '@hotwired/stimulus';

// Строка, которая не переносится: если не помещается — шрифт ужимается ровно настолько, чтобы влезть.
// Цена «3 890 000 → 4 170 000 ₽» в узкой колонке остаётся одной строкой.
export default class extends Controller {
    static values = { min: { type: Number, default: 12 } };

    connect() {
        this.element.style.whiteSpace = 'nowrap';
        this.observer = new ResizeObserver(() => this.fit());
        this.observer.observe(this.element.parentElement);
        this.fit();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    fit() {
        const el = this.element;
        el.style.fontSize = '';
        const base = parseFloat(getComputedStyle(el).fontSize);
        const room = el.clientWidth;
        if (room > 0 && el.scrollWidth > room) {
            el.style.fontSize = `${Math.max(this.minValue, Math.floor(base * room / el.scrollWidth))}px`;
        }
    }
}
