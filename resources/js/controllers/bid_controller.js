import { Controller } from '@hotwired/stimulus';

// Форма подтверждения: видимое поле с разделителями, на сервер — число; кнопки
// полной цены и скидок; ниже минимума кнопка гаснет.
export default class extends Controller {
    static targets = ['display', 'amount', 'submit', 'low'];
    static values = { asking: Number, min: Number };

    connect() {
        this.rubles = new Intl.NumberFormat('ru-RU');
        this.update();
    }

    set(event) {
        this.write(Number(event.params.amount));
    }

    discount(event) {
        this.write(Math.round(this.askingValue * (1 - Number(event.params.percent) / 100) / 1000) * 1000);
    }

    input() {
        const digits = this.displayTarget.value.replace(/\D/g, '');
        this.displayTarget.value = digits ? this.rubles.format(Number(digits)) : '';
        this.update();
    }

    write(value) {
        this.displayTarget.value = value ? this.rubles.format(value) : '';
        this.update();
    }

    update() {
        const value = Number(this.displayTarget.value.replace(/\D/g, '')) || 0;
        this.amountTarget.value = value || '';
        const low = this.minValue > 0 && value > 0 && value < this.minValue;
        this.displayTarget.classList.toggle('ring-2', low);
        this.displayTarget.classList.toggle('ring-danger', low);
        if (this.hasLowTarget) this.lowTarget.hidden = !low;
        if (this.hasSubmitTarget) this.submitTarget.disabled = low || !value;
    }
}
