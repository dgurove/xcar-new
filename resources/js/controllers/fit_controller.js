import { Controller } from '@hotwired/stimulus';
import { watch, unwatch } from '../lib/fit-batch';

// Цена, которая не ломается посередине. Разряды и знак рубля в разметке держатся
// неразрывными пробелами (App\Support\Money), так что «3 890 000 → 4 170 000 ₽» в
// узкой колонке сначала переносится целыми частями («3 890 000 →» / «4 170 000 ₽»),
// а если и одна часть не влезает — шрифт ужимается ровно настолько, чтобы влезла.
// Замер — общим тактом (lib/fit-batch).
export default class extends Controller {
    static values = { min: { type: Number, default: 12 } };

    connect() {
        this.item = { element: this.element, measure: () => this.measure() };
        watch(this.item);
    }

    disconnect() {
        unwatch(this.item);
    }

    measure() {
        const el = this.element;
        const base = parseFloat(getComputedStyle(el).fontSize);
        const room = el.clientWidth;
        // При обычном переносе scrollWidth больше ширины только когда не влезает неразрывный кусок.
        return room > 0 && el.scrollWidth > room ? Math.max(this.minValue, Math.floor(base * room / el.scrollWidth)) : 0;
    }
}
