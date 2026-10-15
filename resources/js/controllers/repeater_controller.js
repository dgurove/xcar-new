import { Controller } from '@hotwired/stimulus';

// Фокус в поле листа — через keyboardInset (window.focusField): иначе iOS сдвигал лист к полю, и каретка рисовалась мимо.
const focus = (el) => el && (window.focusField ? window.focusField(el) : el.focus());

// Повторяющиеся строки формы: исходы этапа, поля. Шаблон строки лежит в
// <template>, индекс подставляется вместо __i__.
export default class extends Controller {
    static targets = ['list', 'template'];

    add() {
        const index = Date.now();
        const html = this.templateTarget.innerHTML.replaceAll('__i__', index);
        this.listTarget.insertAdjacentHTML('beforeend', html);
        focus(this.listTarget.lastElementChild.querySelector('input, select, textarea'));
    }

    remove(event) {
        event.target.closest('[data-row]').remove();
    }
}
