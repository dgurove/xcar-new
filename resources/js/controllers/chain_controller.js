import { Controller } from '@hotwired/stimulus';

// Лента писем: при загрузке прокручивается к первому непрочитанному или последнему письму (focus).
export default class extends Controller {
    static targets = ['focus'];

    connect() {
        if (!this.hasFocusTarget || this.focusTarget === this.element.querySelector('.letter')) return;
        requestAnimationFrame(() => this.reveal(this.focusTarget));
    }

    // Прокрутка своего окна (лента лежит в шторке писем), не scrollIntoView: тот листал и страницу под шторкой — после
    // закрытия писем человек стоял в другом месте списка.
    reveal(el) {
        let box = el.parentElement;
        for (; box && box !== document.body; box = box.parentElement) {
            if (box.scrollHeight > box.clientHeight + 1 && /(auto|scroll)/.test(getComputedStyle(box).overflowY)) break;
        }
        if (!box || box === document.body) { el.scrollIntoView({ block: 'start' }); return; }
        const bar = box.querySelector(':scope > .sheet-bar');
        const top = (bar ? bar.getBoundingClientRect().bottom : box.getBoundingClientRect().top) + 8;
        box.scrollTop += el.getBoundingClientRect().top - top;
    }
}
