import { Controller } from '@hotwired/stimulus';

// Список чатов и открытый чат рядом (от 1024): строки ведут в фрейм chat-screen, адрес
// меняется (advance), выделение строки — по адресу. На телефоне ссылки обычные:
// фрейма на экране нет, страница чата открывается целиком.
export default class extends Controller {
    connect() {
        this.media = matchMedia('(min-width: 1024px)');
        this.apply = () => this.element.querySelectorAll('a.chat-row').forEach((a) => this.media.matches ? (a.dataset.turboFrame = 'chat-screen') : delete a.dataset.turboFrame);
        this.mark = () => this.element.querySelectorAll('a.chat-row').forEach((a) => a.toggleAttribute('aria-current', a.getAttribute('href') === location.pathname));
        this.media.addEventListener('change', this.apply);
        document.addEventListener('turbo:frame-load', this.mark);
        this.apply();
    }

    disconnect() {
        this.media.removeEventListener('change', this.apply);
        document.removeEventListener('turbo:frame-load', this.mark);
    }
}
