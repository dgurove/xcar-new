import { Controller } from '@hotwired/stimulus';

// Заявка об оплате при нескольких счетах: выбор счёта меняет адрес формы.
export default class extends Controller {
    pick(event) {
        this.element.action = this.element.action.replace(/\/invoices\/\d+\//, `/invoices/${event.target.value}/`);
    }
}
