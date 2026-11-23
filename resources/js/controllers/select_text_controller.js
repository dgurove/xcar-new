import { Controller } from '@hotwired/stimulus';

// Выбор словом в строке текста (23.11.2026, НДС поставщика в заголовке «Цены с НДС 5 %» — на телефоне системный `select` встаёт
// 16 px: иначе iOS приближает страницу, и слово выходило крупнее текста рядом). Видно подпись размером строки, сам `select` —
// прозрачным поверх неё: нажатие открывает системный выбор, выбранное переписывает подпись (`data-label` пункта, иначе его
// текст); пустое значение — `is-unset` (красным).
export default class extends Controller {
    static targets = ['label', 'input'];

    sync() {
        const opt = this.inputTarget.selectedOptions[0];
        if (opt) this.labelTarget.textContent = (opt.dataset.label ?? opt.textContent).trim();
        this.labelTarget.classList.toggle('is-unset', !this.inputTarget.value);
    }
}
