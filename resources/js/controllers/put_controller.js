import { Controller } from '@hotwired/stimulus';

// Кнопка в поле вписывает готовое значение («б/н» в номер убытка) так, будто его набрали: форма видит правку.
export default class extends Controller {
    set(event) {
        const input = this.element.closest('.vin-box')?.querySelector('input:not([type=hidden])');
        if (!input) return;
        input.value = event.params.value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        this.element.hidden = true;
    }
}
