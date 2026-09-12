import { Controller } from '@hotwired/stimulus';

// Форма подтверждения: видимое поле с разделителями, на сервер — число; кнопка
// полной цены и скидок (сервер показывает только те, что не ниже порога). Сам порог на клиенте не известен намеренно.
export default class extends Controller {
    static targets = ['display', 'amount', 'submit'];
    static values = { asking: Number };

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
        if (this.hasSubmitTarget) this.submitTarget.disabled = !value;
    }
}
