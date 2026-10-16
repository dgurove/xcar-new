import { Controller } from '@hotwired/stimulus';
import { debounce } from '../lib/debounce';

export default class extends Controller {
    connect() {
        this.later = debounce(() => this.submit(), 300);
    }

    disconnect() {
        this.later.cancel();
    }

    submit() {
        this.element.requestSubmit();
    }

    // Поиск по мере ввода: отправка через паузу, чтобы не слать каждую букву.
    debounced() {
        this.later();
    }
}
