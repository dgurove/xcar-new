import { Controller } from '@hotwired/stimulus';
import { rub } from '../lib/money';

// Форма заявки логиста (18.10.2026): выбрали заказчика — его обычный адрес встаёт в «Откуда», если там пусто; цена
// заказчику и исполнителю — под ними «останется вам N ₽» (минус — красным).
export default class extends Controller {
    static targets = ['from', 'price', 'cost', 'margin'];

    connect() {
        this.money();
    }

    picked(event) {
        const { kind, address } = event.detail ?? {};
        if (kind === 'client' && address && this.hasFromTarget && this.fromTarget.value.trim() === '') {
            this.fromTarget.value = address;
            this.fromTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    money() {
        if (!this.hasMarginTarget) return;
        const n = (el) => Number(String(el?.value ?? '').replace(/[^\d]/g, '')) || 0;
        const price = n(this.priceTarget), cost = n(this.costTarget);
        this.marginTarget.hidden = !(price && cost);
        if (!price || !cost) return;
        const left = price - cost;
        this.marginTarget.textContent = left >= 0 ? `Останется вам ${rub(left)}` : `В минус ${rub(-left)}`;
        this.marginTarget.classList.toggle('text-danger', left < 0);
    }
}
