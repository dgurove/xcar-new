import { Controller } from '@hotwired/stimulus';

// Показывает спрятанный блок (форму привязки ветки из меню «···», исходное письмо в ленте), ставит курсор
// в первое поле, кнопку-trigger прячет. Ленивый turbo-frame в блоке грузится сам, как только его видно.
export default class extends Controller {
    static targets = ['block', 'trigger'];

    show() {
        this.blockTarget.hidden = false;
        this.blockTarget.querySelector('input:not([type=hidden]), [role=combobox]')?.focus();
        for (const t of this.triggerTargets) t.hidden = true;
    }
}
