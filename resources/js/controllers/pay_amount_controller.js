import { Controller } from '@hotwired/stimulus';

// Сумма в шторке «Оплатить»: чипы «700 000» и «весь остаток» подставляют число в поле (разряды уже готовы).
export default class extends Controller {
    static targets = ['input'];

    set({ params }) {
        this.inputTarget.value = params.value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
