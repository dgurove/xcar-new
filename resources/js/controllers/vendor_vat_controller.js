import { Controller } from '@hotwired/stimulus';

// Сменили вендора в редакторе предложения — «НДС поставщика» встаёт по вендору (`vendors.supplier_vat`, 20.11.2026: ставкой, а не
// галкой «С НДС»), как делает сервер для письма и черновика. Вендор — комбобокс: слушаем change его скрытого поля `vendor_id`.
// Выбор стоит вне формы (form=) — ищется через form.elements.
export default class extends Controller {
    static values = { rates: Object };

    sync(event) {
        const field = event?.target?.name === 'vendor_id' ? event.target : this.element.querySelector('[name="vendor_id"]');
        const pick = field?.form?.elements.namedItem('supplier_vat');
        if (!pick || !field.value) return;
        const rate = this.ratesValue[field.value];
        const value = rate === undefined || rate === null ? '' : String(rate);
        if (pick.value === value) return;
        pick.value = value;
        pick.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
