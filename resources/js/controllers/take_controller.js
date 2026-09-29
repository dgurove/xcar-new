import { Controller } from '@hotwired/stimulus';

// «В новом письме …» под полем разбора письма (x-ui.take): подставить значение в поле по имени, чип убрать.
export default class extends Controller {
    take({ params: { name, value }, currentTarget }) {
        const field = this.element.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!field) return;
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        currentTarget.closest('.field')?.classList.remove('field-changed');
        currentTarget.remove();
        field.focus({ preventScroll: true });
    }
}
