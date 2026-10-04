import { Controller } from '@hotwired/stimulus';

// Чип «Мигторг» в шапке редактора следит за полем номера убытка: вписали номер и ещё не сохранили — через полсекунды
// сервер отвечает чипом по этому номеру (`/offers/{n}/migtorg?ref=`), и лот, если он есть, виден сразу; «Это она»
// в его шторке сохранит номер сам. Поле — в форме редактора, чип — вне её, поэтому поле ищется по имени.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.field = document.querySelector('#offer-form [name="claim_ref"]');
        this.onInput = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.check(), 500);
        };
        this.field?.addEventListener('input', this.onInput);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.field?.removeEventListener('input', this.onInput);
    }

    async check() {
        const ref = this.field.value.trim();
        if (ref === this.last || this.element.querySelector('dialog[open]')) return;
        this.last = ref;
        this.abort?.abort();
        this.abort = new AbortController();
        try {
            const res = await fetch(`${this.urlValue}?ref=${encodeURIComponent(ref)}`, { signal: this.abort.signal, headers: { Accept: 'text/html' } });
            if (res.ok) this.element.outerHTML = await res.text();
        } catch {}
    }
}
