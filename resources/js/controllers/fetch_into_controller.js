import { Controller } from '@hotwired/stimulus';

// Кусок с сервера по полям формы — «что будет» до отправки (окно «ТС» у ссылки на оплату: засчитаем ли в сделку и
// сколько, 09.10.2026). Поля — по именам из `fields`, на `change` и `input` с паузой; ответ — в цель `box`, устаревшие
// отброшены.
export default class extends Controller {
    static targets = ['box'];
    static values = { url: String, fields: Array };

    connect() {
        this.load();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
    }

    changed(event) {
        if (!this.fieldsValue.includes(event.target.name)) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), event.type === 'change' ? 0 : 400);
    }

    async load() {
        const url = new URL(this.urlValue, location.href);
        for (const name of this.fieldsValue) {
            const value = this.element.querySelector(`[name="${name}"]`)?.value.trim();
            if (value) url.searchParams.set(name, value);
        }
        if (url.href === this.last) return;
        this.last = url.href;
        this.abort?.abort();
        this.abort = new AbortController();
        try {
            const res = await fetch(url, { signal: this.abort.signal, headers: { Accept: 'text/html' } });
            if (res.ok) this.boxTarget.innerHTML = await res.text();
        } catch {}
    }
}
