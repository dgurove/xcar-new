import { Controller } from '@hotwired/stimulus';
import { post, csrf } from '../lib/http';

// Личная заметка к ТС (18.10.2026, x-offer.my-note): сохраняется сама, как в «Заметках» iPhone — через 800 мс тишины, при
// уходе с поля, при переходе Turbo и при закрытии страницы (sendBeacon). Уходит только последняя версия: пока идёт запрос,
// следующий ждёт его. Не дошло (нет связи) — тост «Нет связи» от lib/http, текст остаётся «несохранённым» и уйдёт
// следующей попыткой: при наборе, уходе с поля или через 5 с.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.saved = this.element.value;
        this.onLeave = () => this.flush();
        this.onHide = () => this.beacon();
        document.addEventListener('turbo:before-visit', this.onLeave);
        window.addEventListener('pagehide', this.onHide);
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('turbo:before-visit', this.onLeave);
        window.removeEventListener('pagehide', this.onHide);
        // Карточку строки закрыли или заменили — набранное не теряется.
        this.beacon();
    }

    changed() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.flush(), 800);
    }

    get dirty() {
        return this.element.value !== this.saved;
    }

    async flush() {
        clearTimeout(this.timer);
        if (!this.dirty) return;
        if (this.sending) { this.again = true; return; }
        this.sending = true;
        const text = this.element.value;
        const body = new FormData();
        body.append('text', text);
        const r = await post(this.urlValue, body);
        this.sending = false;
        if (r?.ok) this.saved = text;
        else this.timer = setTimeout(() => this.flush(), 5000);
        if (this.again || this.dirty) { this.again = false; if (r?.ok) this.flush(); }
    }

    beacon() {
        if (!this.dirty || !navigator.sendBeacon) return;
        const body = new FormData();
        body.append('_token', csrf());
        body.append('text', this.element.value);
        if (navigator.sendBeacon(this.urlValue, body)) this.saved = this.element.value;
    }
}
