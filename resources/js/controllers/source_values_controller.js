import { Controller } from '@hotwired/stimulus';

// Значения источников под полем правки (x-car.source-values, 08.11.2026): фокус в поле — строки видны, ушёл — прячутся
// (150 мс: нажатие по строке уводит фокус раньше щелчка, как у комбобокса). Нажатие — значение в поле и события ввода:
// разряды суммы и «Сохранить изменения» срабатывают сами, пишет обычное сохранение формы.
export default class extends Controller {
    connect() {
        this.field = this.element.closest('.field');
        this.input = this.field?.querySelector('input:not([type=hidden]), textarea');
        if (!this.input) return;
        this.onIn = () => { clearTimeout(this.timer); this.element.hidden = false; };
        this.onOut = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { if (!this.field.contains(document.activeElement)) this.element.hidden = true; }, 150);
        };
        this.field.addEventListener('focusin', this.onIn);
        this.field.addEventListener('focusout', this.onOut);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.field?.removeEventListener('focusin', this.onIn);
        this.field?.removeEventListener('focusout', this.onOut);
    }

    pick(event) {
        this.input.value = String(event.params.value ?? '');
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
        this.element.hidden = true;
    }
}
