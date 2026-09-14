import { Controller } from '@hotwired/stimulus';

// Выбор-фильтр (x-ui.choose): зелёный — в тот же кадр, что и новое значение,
// не дожидаясь перерисованной страницы. Десктоп — select, телефон — пилюля и строки шторки.
export default class extends Controller {
    static targets = ['select', 'pill'];

    change() {
        this.selectTarget.toggleAttribute('data-choose-active', this.selectTarget.value !== '');
        this.selectTarget.form.requestSubmit();
    }

    pick(event) {
        const row = event.currentTarget;
        if (row.dataset.default === undefined) this.pillTarget.setAttribute('aria-current', 'true');
        else this.pillTarget.removeAttribute('aria-current');
        this.pillTarget.firstChild.textContent = row.dataset.label;
    }
}
