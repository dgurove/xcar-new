import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }

    // Поиск по мере ввода: отправка через паузу, чтобы не слать каждую букву.
    debounced() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.submit(), 300);
    }
}
