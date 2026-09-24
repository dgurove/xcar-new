import { Controller } from '@hotwired/stimulus';

// Поле с нижней границей длины (причина выдачи без QR): короткий текст не уходит — поле подсвечивается и
// говорит, сколько нужно. Сервер проверяет то же самое.
export default class extends Controller {
    static targets = ['input', 'error'];
    static values = { min: Number };

    guard(event) {
        if (this.inputTarget.value.trim().length >= this.minValue) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        this.show(true);
        this.inputTarget.focus();
    }

    check() {
        if (this.inputTarget.value.trim().length >= this.minValue) this.show(false);
    }

    show(on) {
        this.inputTarget.closest('.field')?.classList.toggle('field-invalid', on);
        this.errorTarget.classList.toggle('hidden', !on);
    }
}
