import { Controller } from '@hotwired/stimulus';

// Смахиваемая строка: открылась одна — остальные закрываются; после действия
// возвращается на место сама (ответ-стрим обычно и так заменяет строку).
// Открытая — одна на страницу и запоминается: обход всех `.swipe` на каждое событие прокрутки строки был O(строк) за кадр.
let opened = null;

export default class extends Controller {
    scrolled() {
        if (this.element.scrollLeft < 8) return;
        if (opened && opened !== this.element && opened.isConnected && opened.scrollLeft > 0) opened.scrollTo({ left: 0, behavior: 'smooth' });
        opened = this.element;
    }

    close() {
        this.element.scrollTo({ left: 0, behavior: 'smooth' });
    }
}
