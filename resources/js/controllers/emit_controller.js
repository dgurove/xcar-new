import { Controller } from '@hotwired/stimulus';

// Кнопка, которая шлёт событие на window: другой контроллер его ловит
// (deal:open открывает блок цены, chat:open — чат).
export default class extends Controller {
    send(event) {
        window.dispatchEvent(new CustomEvent(event.params.event));
    }
}
