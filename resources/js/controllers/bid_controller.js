import { Controller } from '@hotwired/stimulus';

// Форма подтверждения: видимое поле с разделителями, на сервер — число; кнопка
// полной цены и скидок (сервер показывает только те, что не ниже порога). Сам порог на клиенте не известен намеренно.
// Можно в гараж — отправка сначала спрашивает, куда берёт (шторка `x-offer.bid-choice`): «Для покупателя за N ₽»
// (без цены выключена) или «В гараж на подготовку» (цена не нужна). Enter в поле тоже ведёт в шторку.
export default class extends Controller {
    static targets = ['display', 'amount', 'submit', 'choice', 'buyer', 'sum'];
    static values = { asking: Number, garage: Boolean };

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
        if (this.hasSubmitTarget) this.submitTarget.disabled = !value && !this.garageValue;
        if (this.hasBuyerTarget) this.buyerTarget.disabled = !value;
        if (this.hasSumTarget) {
            this.sumTarget.textContent = value ? ` за ${this.rubles.format(value)}\u00a0₽` : '';
            this.sumTarget.hidden = !value;
        }
    }

    // Отправка мимо шторки (кнопка, Enter в поле) — сначала спросить, куда.
    guard(event) {
        if (!this.garageValue || !this.hasChoiceTarget) return;
        if (this.choiceTarget.querySelector('dialog')?.open) return;
        event.preventDefault();
        this.ask();
    }

    ask() {
        this.application.getControllerForElementAndIdentifier(this.choiceTarget, 'sheet')?.open();
    }
}
