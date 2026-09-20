import { Controller } from '@hotwired/stimulus';

// Радио открывает свою группу полей (data-reveal-key = value радио), остальные прячет
// и выключает, чтобы их значения не ушли в форму. С labelSelector кнопка (обычно в плашке,
// по селектору) получает подпись выбранного исхода — data-reveal-label у радио.
export default class extends Controller {
    static targets = ['pane'];
    static values = { labelSelector: String };

    connect() {
        const checked = this.element.querySelector('input[type=radio][data-action*="reveal#pick"]:checked');
        if (checked) this.show(checked.value, checked.dataset.revealLabel);
    }

    pick(event) {
        this.show(event.currentTarget.value, event.currentTarget.dataset.revealLabel);
    }

    show(key, label) {
        for (const pane of this.paneTargets) {
            const on = pane.dataset.revealKey === key;
            pane.hidden = !on;
            for (const field of pane.querySelectorAll('input, select, textarea')) field.disabled = !on;
        }
        if (label && this.labelSelectorValue) {
            const button = document.querySelector(this.labelSelectorValue);
            if (button) button.textContent = label;
        }
    }
}
