import { Controller } from '@hotwired/stimulus';
import { nums, rub } from '../lib/money';

// Шторка оплаты «Счёт и подбор» (25.11.2026, `x-deal.payment-sheet`): выбрали, за что, — «осталось N ₽» этого счёта; «Всё» вписывает
// остаток выбранного (при правке — вместе с самой оплатой: она погаснет перед новой).
export default class extends Controller {
    static targets = ['left', 'amount'];
    static values = { left: Object, whole: Object };

    get kind() {
        return this.element.querySelector('[name="for"]:checked')?.value ?? this.element.querySelector('[name="for"]')?.value;
    }

    pick() {
        this.leftTarget.textContent = rub(this.leftValue[this.kind] ?? 0);
    }

    all() {
        this.amountTarget.value = nums(this.wholeValue[this.kind] ?? this.leftValue[this.kind] ?? 0);
        this.amountTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.amountTarget.focus();
    }
}
