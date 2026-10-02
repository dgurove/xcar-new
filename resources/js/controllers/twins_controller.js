import { Controller } from '@hotwired/stimulus';

// Такая ТС уже есть: пока человек вводит номер убытка, VIN или госномер, сервер ищет живые ТС (предложения) с тем
// же номером, и они встают строками под полями — до «Завести», а не ошибкой после. Пусто — ничего не видно.
// Поля — по именам внутри элемента (ref и claim_ref предложения, vin, plate); запрос через 400 мс тишины, устаревшие
// ответы отброшены.
export default class extends Controller {
    static targets = ['box'];
    static values = { url: String, except: String, candidate: String };

    connect() {
        this.check();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
    }

    changed(event) {
        if (!event.target.matches('[name="ref"], [name="claim_ref"], [name="vin"], [name="plate"]')) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), 400);
    }

    async check() {
        const url = new URL(this.urlValue, location.href);
        for (const name of ['ref', 'claim_ref', 'vin', 'plate']) {
            const value = this.element.querySelector(`[name="${name}"]`)?.value.trim();
            if (value) url.searchParams.set(name, value);
        }
        if (![...url.searchParams.keys()].length) { this.boxTarget.replaceChildren(); return; }
        if (this.exceptValue) url.searchParams.set('except', this.exceptValue);
        if (this.candidateValue) url.searchParams.set('candidate', this.candidateValue);
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
