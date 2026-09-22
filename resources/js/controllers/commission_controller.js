import { Controller } from '@hotwired/stimulus';

// Деньги сделки при принятии подтверждения: поле вознаграждения с разделителями
// (на сервер — число) и «нам остаётся», пересчитанное на месте: разница минус вознаграждение.
export default class extends Controller {
    static targets = ['display', 'amount', 'ours'];
    static values = { margin: Number };

    connect() {
        this.rubles = new Intl.NumberFormat('ru-RU');
        this.input();
    }

    input() {
        const digits = this.displayTarget.value.replace(/\D/g, '');
        const value = digits ? Number(digits) : 0;
        this.displayTarget.value = digits ? this.rubles.format(value) : '';
        this.amountTarget.value = digits ? value : '';
        if (this.hasOursTarget && this.hasMarginValue) {
            const ours = this.marginValue - value;
            this.oursTarget.textContent = this.rubles.format(ours) + ' ₽';
            this.oursTarget.classList.toggle('text-danger', ours < 0);
        }
    }
}
