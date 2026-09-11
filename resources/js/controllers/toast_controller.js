import { Controller } from '@hotwired/stimulus';

// Всплывающие сообщения. Серверный flash приходит атрибутами,
// клиент зовёт window.toast('текст') или toast('текст', 'danger').
export default class extends Controller {
    static values = { message: String, kind: String };

    connect() {
        window.toast = (text, kind) => this.show(text, kind);
        if (this.messageValue) this.show(this.messageValue, this.kindValue);
    }

    show(text, kind = '') {
        const el = document.createElement('div');
        el.className = 'toast' + (kind ? ` toast-${kind}` : '');
        el.textContent = text;
        this.element.append(el);
        setTimeout(() => el.remove(), 4000);
    }
}
