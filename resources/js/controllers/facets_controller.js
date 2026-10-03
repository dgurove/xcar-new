import { Controller } from '@hotwired/stimulus';

// Шторка чипа фильтра (x-ui.facet-chip): галки собираются в одно скрытое поле через запятую, число на «Показать N»
// переспрашивается тем же адресом с заголовком X-Count (контроллер списка отвечает {count} до страниц и подгрузок).
// Прежний запрос обрывается, опоздавший ответ отбрасывается. Ничего не найдено — кнопка выключена.
// Один выбор (single) — радио: отправляется сразу.
export default class extends Controller {
    static targets = ['value', 'box', 'submit', 'item'];
    static values = { single: Boolean, wait: { type: Number, default: 150 } };

    connect() {
        this.seq = 0;
    }

    disconnect() {
        clearTimeout(this.timer);
        this.pending?.abort();
    }

    tick(event) {
        if (!event.target.matches?.('[data-facets-target="box"]')) return;
        if (this.singleValue) {
            this.element.requestSubmit();
            return;
        }
        this.valueTarget.value = this.boxTargets.filter((b) => b.checked).map((b) => b.value).join(',');
        clearTimeout(this.timer);
        this.submitTarget.setAttribute('aria-busy', 'true');
        this.timer = setTimeout(() => this.count(), this.waitValue);
    }

    async count() {
        const url = new URL(this.element.action, location.href);
        for (const [k, v] of new FormData(this.element)) url.searchParams.set(k, v);
        url.searchParams.delete('page');
        this.pending?.abort();
        this.pending = new AbortController();
        const seq = ++this.seq;
        try {
            const res = await fetch(url, { headers: { 'X-Count': '1', Accept: 'application/json' }, signal: this.pending.signal });
            const { count } = await res.json();
            if (seq !== this.seq) return;
            this.render(count);
        } catch (e) {
            if (e.name !== 'AbortError' && seq === this.seq) this.render(null);
        }
    }

    render(n) {
        const button = this.submitTarget;
        button.removeAttribute('aria-busy');
        if (n === 0) {
            button.textContent = 'Ничего не найдено';
            button.disabled = true;
            return;
        }
        button.disabled = false;
        button.innerHTML = n === null ? 'Показать' : `Показать <span class="nums">${n}</span>`;
    }

    filter(event) {
        const q = event.target.value.trim().toLowerCase();
        this.itemTargets.forEach((el) => { el.hidden = q !== '' && !el.dataset.name.includes(q); });
    }

    // Enter в поиске по вариантам не отправляет форму: выбор ещё не сделан.
    stop(event) {
        event.preventDefault();
        event.target.blur();
    }
}
