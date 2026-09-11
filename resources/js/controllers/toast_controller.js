import { Controller } from '@hotwired/stimulus';

// Всплывающие сообщения. Серверный flash приходит атрибутами,
// клиент зовёт window.toast('текст'), toast('текст', 'danger') или
// toast('текст', { href }) — тогда сообщение можно нажать.
export default class extends Controller {
    static values = { message: String, kind: String };

    connect() {
        window.toast = (text, options) => this.show(text, options);
        if (this.messageValue) this.show(this.messageValue, this.kindValue);
    }

    show(text, options = '') {
        const kind = typeof options === 'string' ? options : options?.kind || '';
        const href = typeof options === 'object' ? options?.href : null;
        const el = document.createElement(href ? 'a' : 'div');
        el.className = 'toast' + (kind ? ` toast-${kind}` : '');
        el.textContent = text;
        if (href) el.href = href;
        this.element.append(el);
        setTimeout(() => el.remove(), href ? 7000 : 4000);
    }
}
