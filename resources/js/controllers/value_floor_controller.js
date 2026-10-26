import { Controller } from '@hotwired/stimulus';
import { floorFrom } from '../lib/floor';
import { nums, rubles } from '../lib/money';

// Оценочная → закупочная прямо в форме, по правилу сервера (`Offers\Pricing`, ступени — значением `rules`). Закупочная
// пересчитывается, только пока она пустая или посчитана от прежней оценочной — вписанную рукой не трогаем.

export default class extends Controller {
    static targets = ['value', 'floor'];
    static values = { rules: Array };

    // Оценочной в форме может не быть (не Альфа, или она сверху карточки) — тогда считать нечего.
    connect() {
        this.was = this.hasValueTarget ? (rubles(this.valueTarget.value) ?? 0) : 0;
    }

    sync() {
        if (!this.hasValueTarget || !this.hasFloorTarget) return;
        const value = rubles(this.valueTarget.value) ?? 0;
        const floor = rubles(this.floorTarget.value) ?? 0;
        const auto = !floor || (this.was && floor === floorFrom(this.was, this.rulesValue));
        this.was = value;
        if (!value || !auto) return;
        this.floorTarget.value = nums(floorFrom(value, this.rulesValue));
        this.floorTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
