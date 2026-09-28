import { Controller } from '@hotwired/stimulus';

// Подсказки к полю: чип вписывает слово и переводит к сумме — печатать не обязательно.
export default class extends Controller {
    static targets = ['field'];

    fill(event) {
        this.fieldTarget.value = event.currentTarget.dataset.suggestValue;
        this.fieldTarget.form?.elements.amount?.focus();
    }
}
