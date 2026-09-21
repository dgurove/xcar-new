import { Controller } from '@hotwired/stimulus';

// Лента писем: при загрузке прокручивается к первому непрочитанному или последнему письму (focus).
export default class extends Controller {
    static targets = ['focus'];

    connect() {
        if (!this.hasFocusTarget || this.focusTarget === this.element.querySelector('.letter')) return;
        requestAnimationFrame(() => this.focusTarget.scrollIntoView({ block: 'start' }));
    }
}
