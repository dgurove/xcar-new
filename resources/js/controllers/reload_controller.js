import { Controller } from '@hotwired/stimulus';

// Фрейм, который перечитывается по живому событию (карточка чата на странице сделки — по `live:chat`): адрес с меткой
// времени, иначе тот же src Turbo не перезагрузит. key/match — перечитывать только своё: сотрудникам приходят сообщения
// всех чатов, и карточка чата сделки в CRM ждёт свою машину (`detail.offer`).
export default class extends Controller {
    static values = { url: String, key: String, match: String };

    load(event) {
        if (this.keyValue && String(event?.detail?.[this.keyValue] ?? '') !== this.matchValue) return;
        this.element.src = this.urlValue + (this.urlValue.includes('?') ? '&' : '?') + 't=' + Date.now();
    }
}
