import { Controller } from '@hotwired/stimulus';

// Всплывающие сообщения. Узел #toasts постоянный (data-turbo-permanent), серверный
// flash приходит соседним <template id="flash"> и читается после каждого рендера —
// и после полного, и после морфа (редирект на тот же адрес connect не повторяет).
// Клиент зовёт window.toast('текст'), toast('текст', 'danger'),
// toast('текст', { href }) — сообщение можно нажать, или
// toast('текст', { action: { label, run } }) — с кнопкой внутри.
export default class extends Controller {
    connect() {
        window.toast = (text, options) => this.show(text, options);
        this.onRender = () => this.flash();
        document.addEventListener('turbo:load', this.onRender);
        document.addEventListener('turbo:render', this.onRender);
        this.flash();
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.onRender);
        document.removeEventListener('turbo:render', this.onRender);
    }

    flash() {
        const flash = document.getElementById('flash');
        if (!flash) return;
        flash.remove();
        this.show(flash.dataset.message, flash.dataset.kind);
    }

    show(text, options = '') {
        const kind = typeof options === 'string' ? options : options?.kind || '';
        const href = typeof options === 'object' ? options?.href : null;
        const action = typeof options === 'object' ? options?.action : null;
        const el = document.createElement(href ? 'a' : 'div');
        el.className = 'toast' + (kind ? ` toast-${kind}` : '');
        el.textContent = text;
        if (href) el.href = href;
        if (action) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'toast-action';
            button.textContent = action.label;
            button.addEventListener('click', () => { el.remove(); action.run(); });
            el.append(button);
        }
        this.element.append(el);
        setTimeout(() => el.remove(), href || action ? 8000 : 4000);
    }
}
