import { Controller } from '@hotwired/stimulus';

// «Изменить оценку» (11.10.2026): вместо карточки «отправлена» — то же поле с нынешней суммой, «Отмена» возвращает
// карточку. Две крупные суммы подряд не стоят.
export default class extends Controller {
    static targets = ['done', 'form', 'input'];

    edit() {
        this.doneTarget.hidden = true;
        this.formTarget.hidden = false;
        this.inputTarget.focus();
        this.inputTarget.select();
    }

    cancel() {
        this.formTarget.hidden = true;
        this.doneTarget.hidden = false;
    }
}
