import { Controller } from '@hotwired/stimulus';
import { confirmSheet } from '../confirm';

// Оценка менеджера в окошке «Оценить» CRM (11.10.2026): строка и есть кнопка — лист снизу, как в iOS:
// «Взять» (ценой продажи) лаймом, «Отклонить» красным, «Отмена». Ответ отправляет свою скрытую форму.
export default class extends Controller {
    static targets = ['take', 'reject'];
    static values = { title: String };

    async open(event) {
        event.preventDefault();
        const answer = await confirmSheet(this.titleValue, { form: this.takeTarget, label: 'Взять', danger: false, alt: { label: 'Отклонить', danger: true } });
        if (answer === 'ok') this.takeTarget.requestSubmit();
        else if (answer === 'alt') this.rejectTarget.requestSubmit();
    }
}
