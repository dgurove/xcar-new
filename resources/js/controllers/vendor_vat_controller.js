import { Controller } from '@hotwired/stimulus';

// Сменили вендора в редакторе предложения — «С НДС» у закупочной встаёт по вендору (`vendors.offers_include_vat`),
// как делает сервер для письма и черновика. Вендор — комбобокс: слушаем change его скрытого поля `vendor_id`.
// Галка стоит вне формы (form=) — ищется через form.elements.
export default class extends Controller {
    static values = { ids: Array };

    sync(event) {
        const field = event?.target?.name === 'vendor_id' ? event.target : this.element.querySelector('[name="vendor_id"]');
        const box = field?.form?.elements.namedItem('prices_include_vat');
        if (!box || !field.value) return;
        const vat = this.idsValue.map(String).includes(field.value);
        if (box.checked === vat) return;
        box.checked = vat;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
