import { Controller } from '@hotwired/stimulus';

// Радио открывает свою группу полей (data-reveal-key = value радио), остальные прячет
// и выключает, чтобы их значения не ушли в форму. С labelSelector кнопка (обычно в плашке,
// по селектору) получает подпись выбранного исхода — data-reveal-label у радио.
// Вложенный reveal (способ оплаты → кто платит) ведёт свои панели сам: чужие цели здесь не трогаются.
// После morph-визита (форма с ошибкой вернулась на тот же адрес) контроллер не переподключается,
// а серверные hidden и disabled ложатся поверх — поэтому раскладка пересчитывается по turbo:morph.
export default class extends Controller {
    static targets = ['pane'];
    static values = { labelSelector: String };

    connect() {
        this.sync();
        this.onMorph = () => this.sync();
        document.addEventListener('turbo:morph', this.onMorph);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
    }

    sync() {
        const checked = [...this.element.querySelectorAll('input[type=radio][data-action*="reveal#pick"]:checked')].find((r) => this.owns(r));
        if (checked) this.show(checked.value, checked.dataset.revealLabel);
    }

    owns(el) {
        return el.closest('[data-controller~="reveal"]') === this.element;
    }

    pick(event) {
        this.show(event.currentTarget.value, event.currentTarget.dataset.revealLabel);
    }

    show(key, label) {
        for (const pane of this.paneTargets.filter((p) => this.owns(p))) {
            const on = pane.dataset.revealKey === key;
            pane.hidden = !on;
            // Поля вложенной панели, которую её reveal держит скрытой, так и остаются выключенными.
            for (const field of pane.querySelectorAll('input, select, textarea')) field.disabled = !on || !!field.closest('[hidden]');
        }
        if (label && this.labelSelectorValue) {
            const button = document.querySelector(this.labelSelectorValue);
            if (button) button.textContent = label;
        }
    }
}
