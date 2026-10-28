import { Controller } from '@hotwired/stimulus';
import { confirmSheet } from '../confirm';

// «Спросить менеджера» (Альфа, после «Поставщик согласовал»): шторка с третьей кнопкой «Уже согласился» (28.10.2026,
// владелец: «для случаев, когда он мне уже устно подтвердил»). Она шлёт ту же форму с then_take=1 — сервер сразу жмёт за
// менеджера «Беру в работу» (`RouteController::exit`).
export default class extends Controller {
    static targets = ['take'];

    async ask(event) {
        // Наша же отправка после ответа — пропускаем (requestSubmit снова зовёт submit).
        if (this.sending) { this.sending = false; return; }
        event.preventDefault();
        const answer = await confirmSheet('Поставщик согласовал. Спросить менеджера?', {
            form: this.element,
            label: 'Спросить менеджера',
            danger: false,
            text: 'Ответ — до 17:00 завтра, поставщику пока ничего не уходит. Менеджер уже согласился — дальше сразу, без вопроса',
            alt: { label: 'Уже согласился' },
        });
        if (!answer) return;
        this.takeTarget.value = answer === 'alt' ? '1' : '';
        this.sending = true;
        this.element.requestSubmit();
    }
}
