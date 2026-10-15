import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Фокус в поле листа — через keyboard.js: иначе iOS сдвигал лист к полю, и каретка рисовалась мимо.
const focus = (el) => el && focusField(el);

// Показывает спрятанный блок (форму привязки ветки из меню «···», исходное письмо в ленте), ставит курсор
// в первое поле, кнопку-trigger прячет. Ленивый turbo-frame в блоке грузится сам, как только его видно.
export default class extends Controller {
    static targets = ['block', 'trigger'];

    show() {
        this.blockTarget.hidden = false;
        focus(this.blockTarget.querySelector('input:not([type=hidden]), [role=combobox]'));
        for (const t of this.triggerTargets) t.hidden = true;
    }
}
