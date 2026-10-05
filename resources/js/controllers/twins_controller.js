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
        // Загрузили кадры: кадр Мигторга мог назвать номер, который уже у другого предложения.
        this.recheck = () => { this.last = null; this.check(); };
        window.addEventListener('photos:uploaded', this.recheck);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        window.removeEventListener('photos:uploaded', this.recheck);
    }

    changed(event) {
        if (!event.target.matches('[name="ref"], [name="claim_ref"], [name="vin"], [name="plate"]')) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), 400);
    }

    // «Это она» у черновика-двойника: строка стоит внутри формы редактора, поэтому своя форма — в body, а не вложенная.
    into(event) {
        const { url, confirm } = event.currentTarget.dataset;
        const form = Object.assign(document.createElement('form'), { method: 'post', action: url, hidden: true });
        form.dataset.turboConfirm = confirm;
        form.dataset.saveSkip = '';
        form.append(Object.assign(document.createElement('input'), { type: 'hidden', name: '_token', value: document.querySelector('meta[name=csrf-token]')?.content }));
        document.body.append(form);
        form.requestSubmit();
    }

    async check() {
        const url = new URL(this.urlValue, location.href);
        for (const name of ['ref', 'claim_ref', 'vin', 'plate']) {
            const value = this.element.querySelector(`[name="${name}"]`)?.value.trim();
            if (value) url.searchParams.set(name, value);
        }
        // Своё предложение (`except`) спрашиваем и с пустыми полями: двойника мог назвать кадр Мигторга.
        if (![...url.searchParams.keys()].length && !this.exceptValue) { this.boxTarget.replaceChildren(); return; }
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
