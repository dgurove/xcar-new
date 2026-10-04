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

    // Выбор в списке открывает панели ключа (`data-reveal-key-param`), пока выбрано непустое: «Кто вывозит» — менеджер,
    // и тогда есть «К менеджеру». Спрятанное радио, если было выбрано, уступает соседнему.
    toggle(event) {
        const on = event.currentTarget.value !== '';
        for (const pane of this.paneTargets.filter((p) => this.owns(p) && p.dataset.revealKey === event.params.key)) {
            pane.hidden = !on;
            const radio = pane.querySelector('input[type=radio]');
            if (!on && radio?.checked) {
                radio.checked = false;
                const next = [...this.element.querySelectorAll(`input[name="${radio.name}"]`)].find((r) => r !== radio);
                if (next) next.checked = true;
            }
        }
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
