import { Controller } from '@hotwired/stimulus';
import { confirmSheet } from '../confirm';

// Выбор в сегменте, который почти всегда ошибка, — своим «Вы уверены?» (25.11.2026, владелец: подбор Совкомбанка «Отдельной
// ссылкой»). Радио со значением `value` спрашивает; «Ещё подумаю» возвращает прежний выбор и панели `reveal`.
export default class extends Controller {
    static values = { value: String, title: String, text: String, ok: String, cancel: String };

    connect() {
        this.was = this.checked()?.value;
    }

    async pick(event) {
        const radio = event.target;
        if (!(radio instanceof HTMLInputElement) || radio.type !== 'radio') return;
        if (radio.value !== this.valueValue || this.was === this.valueValue) {
            this.was = radio.value;
            return;
        }
        const yes = await confirmSheet(this.titleValue, { text: this.textValue, label: this.okValue, cancel: this.cancelValue });
        if (yes) {
            this.was = radio.value;
            return;
        }
        const back = this.element.querySelector(`input[type=radio][name="${radio.name}"][value="${this.was}"]`);
        if (back) {
            back.checked = true;
            back.dispatchEvent(new Event('input', { bubbles: true }));
            this.application.getControllerForElementAndIdentifier(this.element, 'reveal')?.sync();
        }
    }

    checked() {
        return this.element.querySelector('input[type=radio]:checked');
    }
}
