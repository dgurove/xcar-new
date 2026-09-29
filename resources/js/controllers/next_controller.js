import { Controller } from '@hotwired/stimulus';

// «Далее» на клавиатуре телефона: у полей формы enterkeyhint «next», у последнего — «done»; Enter переводит на
// следующее поле по порядку формы (и полям с form= снаружи), а не отправляет её — на айфоне так форму уводили
// посреди заполнения. У последнего поля Enter закрывает клавиатуру. Enter, который поле обработало само (VIN —
// расшифровка), не трогаем. На компьютере Enter отправляет форму, как везде.
const SKIP = ['hidden', 'checkbox', 'radio', 'submit', 'button', 'file', 'range', 'color', 'image', 'reset'];
const touch = matchMedia('(pointer: coarse)');

export default class extends Controller {
    connect() {
        this.onKey = (e) => this.key(e);
        this.onFocus = () => this.mark();
        document.addEventListener('keydown', this.onKey);
        this.element.addEventListener('focusin', this.onFocus);
        this.mark();
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
        this.element.removeEventListener('focusin', this.onFocus);
    }

    fields() {
        const form = this.element;
        return [...document.querySelectorAll('input, select, textarea')].filter((el) => (el.form === form || (!el.form && form.contains(el)))
            && !el.disabled && !el.readOnly && !SKIP.includes(el.type) && el.offsetParent !== null);
    }

    mark() {
        const list = this.fields().filter((el) => el.tagName === 'INPUT');
        list.forEach((el, i) => { if (!el.hasAttribute('data-keep-hint')) el.enterKeyHint = i === list.length - 1 ? 'done' : 'next'; });
    }

    key(e) {
        if (e.key !== 'Enter' || e.defaultPrevented || e.isComposing || !touch.matches || e.target.tagName !== 'INPUT') return;
        const list = this.fields();
        const i = list.indexOf(e.target);
        if (i < 0) return;
        e.preventDefault();
        const next = list[i + 1];
        if (next) next.focus();
        else e.target.blur();
    }
}
