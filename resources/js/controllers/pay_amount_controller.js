import { Controller } from '@hotwired/stimulus';

// Чипы подставляют значение в поле: сумму в «Оплатить» («700 000», «весь остаток»; разряды уже готовы) и услугу в
// «Ссылке на оплату». Чип с тем, что сейчас в поле, — нажат (aria-pressed).
export default class extends Controller {
    static targets = ['input'];

    connect() { this.sync(); }

    set({ params }) {
        this.inputTarget.value = params.value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.sync();
    }

    sync() {
        if (!this.hasInputTarget) return;
        for (const chip of this.element.querySelectorAll('[data-action~="pay-amount#set"]')) {
            chip.setAttribute('aria-pressed', String(chip.dataset.payAmountValueParam === this.inputTarget.value));
        }
    }
}
