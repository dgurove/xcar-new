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

    // Цена целая, а клавиатура с запятой (inputmode="decimal") даёт набрать «150 000,5». Копейки видны, пока человек
    // в поле (иначе следующая цифра приклеилась бы к рублям: 1 500 005), на сервер не идут и уходят при выходе из
    // поля. Разделитель — запятая; точка — только одна и не перед тремя цифрами («1.500.000» из буфера — разряды).
    split(text) {
        let at = text.indexOf(',');
        if (at < 0 && text.split('.').length === 2 && !/\.\d{3}/.test(text)) at = text.indexOf('.');
        if (at < 0) return [text.replace(/\D/g, ''), null];
        return [text.slice(0, at).replace(/\D/g, ''), text.slice(at + 1).replace(/\D/g, '').slice(0, 2)];
    }

    input() {
        const [rubs, kop] = this.split(this.displayTarget.value);
        const whole = rubs ? this.rubles.format(Number(rubs)) : '';
        this.displayTarget.value = kop === null ? whole : `${whole || '0'},${kop}`;
        this.update();
    }

    displayTargetConnected(el) {
        this.onBlur ??= () => this.trim();
        el.addEventListener('blur', this.onBlur);
    }

    displayTargetDisconnected(el) {
        el.removeEventListener('blur', this.onBlur);
    }

    trim() {
        const [rubs, kop] = this.split(this.displayTarget.value);
        if (kop === null) return;
        this.displayTarget.value = Number(rubs) ? this.rubles.format(Number(rubs)) : '';
        this.update();
    }

    // Кнопка цены или скидки — как ввод руками: change снимает ошибку «Укажите цену» (formCheck в app.js).
    write(value) {
        this.displayTarget.value = value ? this.rubles.format(value) : '';
        this.update();
        this.displayTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    update() {
        const value = Number(this.split(this.displayTarget.value)[0]) || 0;
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
