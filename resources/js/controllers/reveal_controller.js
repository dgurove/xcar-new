import { Controller } from '@hotwired/stimulus';

// Радио открывает свою группу полей (data-reveal-key = value радио), остальные прячет
// и выключает, чтобы их значения не ушли в форму.
export default class extends Controller {
    static targets = ['pane'];

    connect() {
        const checked = this.element.querySelector('input[type=radio][data-action*="reveal#pick"]:checked');
        if (checked) this.show(checked.value);
    }

    pick(event) {
        this.show(event.currentTarget.value);
    }

    show(key) {
        for (const pane of this.paneTargets) {
            const on = pane.dataset.revealKey === key;
            pane.hidden = !on;
            for (const field of pane.querySelectorAll('input, select, textarea')) field.disabled = !on;
        }
    }
}
