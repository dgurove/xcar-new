import { Controller } from '@hotwired/stimulus';

// Поле, нужное только части вендоров (оценочная стоимость — у тех, чья ставка от неё, `vendors.rate_by_value`):
// видно, пока в той же форме выбран такой вендор; сменили вендора — поле появляется или прячется сразу.
export default class extends Controller {
    static values = { ids: Array };

    connect() {
        // Поле бывает вне формы и ходит в неё через form= (деньги редактора): форма — та, куда уходит поле.
        const form = this.element.querySelector('[name]')?.form ?? this.element.closest('form');
        this.select = form?.querySelector('select[name="vendor_id"]') ?? (form?.id ? document.querySelector(`select[name="vendor_id"][form="${form.id}"]`) : null);
        this.onChange = () => this.sync();
        this.select?.addEventListener('change', this.onChange);
        this.sync();
    }

    disconnect() {
        this.select?.removeEventListener('change', this.onChange);
    }

    sync() {
        if (this.select) this.element.hidden = !this.idsValue.map(String).includes(this.select.value);
    }
}
