import { Controller } from '@hotwired/stimulus';

// Список приложенных к письму файлов: строку убираем вместе со скрытым полем.
export default class extends Controller {
    remove(event) {
        event.target.closest('[data-file]')?.remove();
    }
}
