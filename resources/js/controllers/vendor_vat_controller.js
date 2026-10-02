import { Controller } from '@hotwired/stimulus';

// Сменили вендора в редакторе предложения — «С НДС» у закупочной встаёт по вендору (`vendors.offers_include_vat`),
// как делает сервер для письма и черновика. Галка стоит вне формы (form=) — ищется через form.elements.
export default class extends Controller {
    static values = { ids: Array };

    sync() {
        const box = this.element.form?.elements.namedItem('prices_include_vat');
        if (!box || !this.element.value) return;
        const vat = this.idsValue.map(String).includes(this.element.value);
        if (box.checked === vat) return;
        box.checked = vat;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
