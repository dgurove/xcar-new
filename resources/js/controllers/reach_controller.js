import { Controller } from '@hotwired/stimulus';
import { confirmSheet } from '../confirm';
import { post } from '../lib/http';

// Карточка «Не пропускайте вывозы / заявки» (x-reach.card): строки Telegram и пушей на этот телефон. Строку пушей прячет
// сам push_controller (браузер не умеет, уже подписан) и зовёт push:render — карточка без видимых строк уходит целиком.
// «×» — «Больше не предлагать?»: навсегда или напомнить через неделю (`TelegramController::seen`, момент reach).
export default class extends Controller {
    connect() {
        this.onRender = () => this.sync();
        this.element.addEventListener('push:render', this.onRender);
        this.sync();
    }

    disconnect() {
        this.element.removeEventListener('push:render', this.onRender);
    }

    sync() {
        this.element.hidden = ![...this.element.querySelectorAll('[data-reach-row]')].some((row) => !row.hidden);
    }

    async dismiss() {
        const answer = await confirmSheet('Больше не предлагать?', { label: 'Не предлагать', alt: { label: 'Напомнить через неделю' } });
        if (!answer) return;
        this.element.hidden = true;
        post('/account/telegram/seen', { moment: 'reach', for: answer === 'alt' ? 'week' : 'never' }, { quiet: true });
    }
}
