import { Controller } from '@hotwired/stimulus';

// Смахиваемая строка: открылась одна — остальные закрываются; после действия
// возвращается на место сама (ответ-стрим обычно и так заменяет строку).
export default class extends Controller {
    scrolled() {
        if (this.element.scrollLeft < 8) return;
        for (const other of document.querySelectorAll('.swipe')) {
            if (other !== this.element && other.scrollLeft > 0) other.scrollTo({ left: 0, behavior: 'smooth' });
        }
    }

    close() {
        this.element.scrollTo({ left: 0, behavior: 'smooth' });
    }
}
