import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Фокус в поле листа — через keyboard.js: иначе iOS сдвигал лист к полю, и каретка рисовалась мимо.
const focus = (el) => el && focusField(el);

// Подсказки к полю: чип вписывает слово и переводит к сумме — печатать не обязательно.
export default class extends Controller {
    static targets = ['field'];

    fill(event) {
        this.fieldTarget.value = event.currentTarget.dataset.suggestValue;
        focus(this.fieldTarget.form?.elements.amount);
    }
}
