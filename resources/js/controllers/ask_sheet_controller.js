import { Controller } from '@hotwired/stimulus';
import { openSheet } from '../sheet';
import { post } from '../lib/http';

// Вопрос при входе (10.11.2026, владелец: «Забрал» за менеджеров жали админы — 80 раз из 96): «Забрали автомобиль?» шторкой
// сама через 1,2 с на «Предложениях» и «Сделках», если поверх ничего нет (Telegram и пуши ждут её — `dialog:modal`). Закрыли без
// ответа — до завтра, как «Ещё нет» (`POST /deals/{id}/later`); ответили — форма уходит, отметки не нужно.
export default class extends Controller {
    static values = { later: String };

    connect() {
        this.dialog = this.element.querySelector('dialog');
        this.onClose = () => !this.answered && this.laterValue && post(this.laterValue, new FormData(), { quiet: true });
        this.onSubmit = () => { this.answered = true; };
        this.dialog?.addEventListener('close', this.onClose);
        this.element.addEventListener('submit', this.onSubmit);
        this.timer = setTimeout(() => this.dialog && !document.querySelector('dialog:modal') && openSheet(this.dialog), 1200);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.dialog?.removeEventListener('close', this.onClose);
        this.element.removeEventListener('submit', this.onSubmit);
    }
}
