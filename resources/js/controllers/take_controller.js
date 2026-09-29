import { Controller } from '@hotwired/stimulus';

// «Взять» значение из нового письма цепочки (разбор письма в CRM): подставить в поле формы по имени и убрать строку.
export default class extends Controller {
    take({ params: { name, value }, currentTarget }) {
        const field = this.element.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!field) return;
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        currentTarget.closest('[data-take-row]')?.remove();
        field.focus({ preventScroll: false });
    }
}
