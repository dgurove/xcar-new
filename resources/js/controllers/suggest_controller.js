import { Controller } from '@hotwired/stimulus';

// Фокус в поле листа — через keyboardInset (window.focusField): иначе iOS сдвигал лист к полю, и каретка рисовалась мимо.
const focus = (el) => el && (window.focusField ? window.focusField(el) : el.focus());

// Подсказки к полю: чип вписывает слово и переводит к сумме — печатать не обязательно.
export default class extends Controller {
    static targets = ['field'];

    fill(event) {
        this.fieldTarget.value = event.currentTarget.dataset.suggestValue;
        focus(this.fieldTarget.form?.elements.amount);
    }
}
