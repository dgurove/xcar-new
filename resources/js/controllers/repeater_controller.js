import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Фокус в поле листа — через keyboard.js: иначе iOS сдвигал лист к полю, и каретка рисовалась мимо.
const focus = (el) => el && focusField(el);

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
        // Убрали строку — форма изменилась: «Сохранить изменения» (save_bar) должна это увидеть.
        this.element.querySelector('input, textarea, select')?.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
