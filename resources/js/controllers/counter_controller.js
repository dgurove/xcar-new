import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';

// Шторка контрпредложения (26.10.2026): чип вписывает цену («+5 000», «Продажная»), под суммой — насколько выше цены
// менеджера и зона цены словом (админу порог знать можно, `Offer::bidZone`): не ниже порога — зелёным, ниже — жёлтым,
// недопустимая — красным. Кнопка живая, только когда цена выше цены менеджера.
export default class extends Controller {
    static targets = ['input', 'diff', 'zone', 'submit'];
    static values = { bid: Number, min: Number, hard: Number };

    connect() {
        this.update();
    }

    set(event) {
        const value = event.params.add ? this.bidValue + Number(event.params.add) : Number(event.params.value);
        this.inputTarget.value = nums(value);
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    update() {
        const value = rubles(this.inputTarget.value) || 0;
        const over = value - this.bidValue;
        this.diffTarget.textContent = value ? (over > 0 ? `+${nums(over)} ₽ к цене менеджера` : 'Не выше цены менеджера') : '';
        const [word, tone] = !value || over <= 0 ? ['', '']
            : this.hardValue && value < this.hardValue ? ['цена недопустимая', 'text-danger']
                : this.minValue && value < this.minValue ? ['ниже порога', 'text-warn'] : ['не ниже порога', 'text-open'];
        this.zoneTarget.textContent = word;
        this.zoneTarget.className = `text-sm ${tone}`;
        this.submitTarget.disabled = over <= 0;
    }
}
