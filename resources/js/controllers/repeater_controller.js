import { Controller } from '@hotwired/stimulus';

// Повторяющиеся строки формы: исходы этапа, поля. Шаблон строки лежит в
// <template>, индекс подставляется вместо __i__.
export default class extends Controller {
    static targets = ['list', 'template'];

    add() {
        const index = Date.now();
        const html = this.templateTarget.innerHTML.replaceAll('__i__', index);
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.listTarget.lastElementChild.querySelector('input, select, textarea')?.focus();
    }

    remove(event) {
        event.target.closest('[data-row]').remove();
    }
}
