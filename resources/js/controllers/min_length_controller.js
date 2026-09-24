import { Controller } from '@hotwired/stimulus';

// Поле с нижней границей длины (причина выдачи без QR): внутри поля счётчик «12 / 20», кнопка неактивна, пока
// текста мало. Дошёл до границы — счётчик гаснет, кнопка оживает. Сервер проверяет то же самое.
export default class extends Controller {
    static targets = ['input', 'counter', 'submit'];
    static values = { min: Number };

    connect() {
        this.check();
    }

    guard(event) {
        if (this.enough()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        this.inputTarget.focus();
    }

    check() {
        const length = this.inputTarget.value.trim().length;
        const ok = this.enough();
        if (this.hasCounterTarget) {
            this.counterTarget.textContent = `${length} / ${this.minValue}`;
            this.counterTarget.hidden = ok;
        }
        if (this.hasSubmitTarget) this.submitTarget.disabled = !ok;
    }

    enough() {
        return this.inputTarget.value.trim().length >= this.minValue;
    }
}
