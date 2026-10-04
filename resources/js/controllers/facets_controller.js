import { Controller } from '@hotwired/stimulus';

// Шторка чипа фильтра (x-ui.facet-chip): галки собираются в одно скрытое поле через запятую, число на «Показать N»
// переспрашивается тем же адресом с заголовком X-Count (контроллер списка отвечает {count} до страниц и подгрузок).
// Прежний запрос обрывается, опоздавший ответ отбрасывается. Ничего не найдено — кнопка выключена.
// Фильтр не включён — отмечено всё, снимают лишнее: отмечено всё значит «без фильтра» (в адрес уходит пустое),
// снятых меньше, чем отмеченных, — исключение `!22,5` («кроме»: на другой вкладке и с новыми вендорами значит то же),
// «Выбрать все» / «Исключить все» — одна кнопка. Один выбор (single) — радио: отправляется сразу.
export default class extends Controller {
    static targets = ['value', 'box', 'submit', 'item', 'all'];
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
        this.sync();
    }

    // Все видимые (поиск мог часть спрятать) отмечены — снять, иначе отметить.
    all() {
        const boxes = this.visible();
        const on = !boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = on; });
        this.sync();
    }

    visible() {
        return this.boxTargets.filter((b) => !b.closest('[data-facets-target="item"]')?.hidden);
    }

    sync() {
        const boxes = this.boxTargets;
        const checked = boxes.filter((b) => b.checked);
        // Отмечено всё — это «без фильтра».
        const off = boxes.filter((b) => !b.checked);
        this.valueTarget.value = off.length === 0 ? '' : off.length < checked.length ? `!${off.map((b) => b.value).join(',')}` : checked.map((b) => b.value).join(',');
        this.label();
        clearTimeout(this.timer);
        this.pending?.abort();
        this.seq++;
        if (checked.length === 0) {
            this.submitTarget.removeAttribute('aria-busy');
            this.submitTarget.textContent = 'Ничего не выбрано';
            this.submitTarget.disabled = true;
            return;
        }
        this.submitTarget.setAttribute('aria-busy', 'true');
        this.timer = setTimeout(() => this.count(), this.waitValue);
    }

    label() {
        if (!this.hasAllTarget) return;
        const boxes = this.visible();
        this.allTarget.textContent = boxes.length && boxes.every((b) => b.checked) ? 'Исключить все' : 'Выбрать все';
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
        this.label();
    }

    // Enter в поиске по вариантам не отправляет форму: выбор ещё не сделан.
    stop(event) {
        event.preventDefault();
        event.target.blur();
    }
}
