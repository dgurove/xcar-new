import { Controller } from '@hotwired/stimulus';

// Цена, которая не ломается посередине. Разряды и знак рубля в разметке держатся
// неразрывными пробелами (App\Support\Money), так что «3 890 000 → 4 170 000 ₽» в
// узкой колонке сначала переносится целыми частями («3 890 000 →» / «4 170 000 ₽»),
// а если и одна часть не влезает — шрифт ужимается ровно настолько, чтобы влезла.
export default class extends Controller {
    static values = { min: { type: Number, default: 12 } };

    connect() {
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
        // При обычном переносе scrollWidth больше ширины только когда не влезает неразрывный кусок.
        if (room > 0 && el.scrollWidth > room) {
            el.style.fontSize = `${Math.max(this.minValue, Math.floor(base * room / el.scrollWidth))}px`;
        }
    }
}
