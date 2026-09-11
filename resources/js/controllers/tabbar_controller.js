import { Controller } from '@hotwired/stimulus';

// Таб-бар уходит под клавиатуру, как нативный: при фокусе в поле прячется,
// при потере фокуса возвращается.
export default class extends Controller {
    static targets = ['bar'];

    connect() {
        this.onFocusIn = (e) => { if (this.isField(e.target)) this.element.classList.add('is-hidden'); };
        this.onFocusOut = (e) => { if (this.isField(e.target)) this.element.classList.remove('is-hidden'); };
        document.addEventListener('focusin', this.onFocusIn);
        document.addEventListener('focusout', this.onFocusOut);
    }

    disconnect() {
        document.removeEventListener('focusin', this.onFocusIn);
        document.removeEventListener('focusout', this.onFocusOut);
    }

    isField(el) {
        return el instanceof HTMLElement && el.matches('input:not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select, [contenteditable="true"], trix-editor');
    }
}
