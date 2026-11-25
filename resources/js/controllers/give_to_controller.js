import { Controller } from '@hotwired/stimulus';

// «Кому» в «Отдать» и «Передать» (25.11.2026): менеджеры и покупатели с гаражом одним списком `x-people.pick`.
// `whom` — выбор строки ставит скрытое радио `whom` (manager, buyer, self), а оно через `reveal` — поля своего вида.
// `garage` — в «В сделку» выбрали покупателя с гаражом (`people:away`): шторка уходит на «В гараж» с ним выбранным.
export default class extends Controller {
    whom(event) {
        const holder = event.detail?.holder;
        const radio = holder && this.element.querySelector(`input[name="whom"][value="${holder}"]`);
        if (!radio || radio.checked) return;
        radio.checked = true;
        radio.dispatchEvent(new Event('input', { bubbles: true }));
    }

    garage(event) {
        const way = this.element.querySelector('input[type=radio][name="_to"][value="garage"], input[type=radio][name="_way"][value="garage"]');
        if (way && !way.checked) {
            way.checked = true;
            way.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const pane = this.element.querySelector('[data-reveal-key="garage"]');
        const option = pane?.querySelector(`[data-controller~="people-pick"] [data-people-option] input[type=radio][value="${CSS.escape(event.detail.id)}"]`);
        if (!option || option.disabled) return;
        option.checked = true;
        option.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
