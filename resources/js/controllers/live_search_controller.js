import { Controller } from '@hotwired/stimulus';

// Поиск по ходу набора. Два слоя, чтобы отклик был мгновенным:
// 1) первый же знак сужает то, что уже на экране — строки без набранного просто не рисуются;
// 2) через паузу приходит ответ сервера по всей почте внутри текущей пилюли и фильтров и подменяет список.
// Адрес не меняется: поиск — не фильтр. Устаревшие ответы отбрасываются по счётчику.
export default class extends Controller {
    static targets = ['input', 'clear'];
    static values = { target: { type: String, default: '#threads' }, min: { type: Number, default: 2 }, wait: { type: Number, default: 200 } };

    connect() {
        this.seq = 0;
        this.sync();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.pending?.abort();
    }

    input() {
        const q = this.query();
        this.sync();
        this.narrow(q);
        clearTimeout(this.timer);
        if (q.length && q.length < this.minValue) return;
        this.timer = setTimeout(() => this.load(q), this.waitValue);
    }

    clear() {
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.input();
    }

    // Enter ничего не отправляет: список уже показан, уводить со страницы незачем.
    stop(event) {
        event.preventDefault();
        this.inputTarget.blur();
    }

    // Мгновенное сужение: прячем строки, в которых нет набранного. Возвращает их ответ сервера.
    narrow(q) {
        const list = this.list();
        if (!list) return;
        const needle = q.toLowerCase();
        for (const row of list.querySelectorAll('[data-search-row]')) {
            const miss = needle !== '' && !row.textContent.toLowerCase().includes(needle);
            row.toggleAttribute('data-live-hidden', miss);
        }
        // Секция, в которой не осталось ни одной строки, тоже уходит.
        for (const section of list.querySelectorAll('[data-search-group]')) {
            const alive = [...section.querySelectorAll('[data-search-row]')].some((r) => !r.hasAttribute('data-live-hidden'));
            section.toggleAttribute('data-live-hidden', !alive);
        }
    }

    async load(q) {
        const list = this.list();
        if (!list) return;
        const url = new URL(location.href);
        url.searchParams.delete('page');
        if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
        this.pending?.abort();
        this.pending = new AbortController();
        const seq = ++this.seq;
        this.element.classList.add('is-busy');
        try {
            const html = await fetch(url, { headers: { 'X-List': '1' }, signal: this.pending.signal }).then((r) => r.text());
            if (seq !== this.seq) return;
            list.innerHTML = html;
        } catch (e) {
            if (e.name !== 'AbortError') this.narrow('');
        } finally {
            if (seq === this.seq) this.element.classList.remove('is-busy');
        }
    }

    list() {
        return document.querySelector(this.targetValue);
    }

    query() {
        return this.inputTarget.value.trim();
    }

    sync() {
        if (this.hasClearTarget) this.clearTarget.hidden = this.inputTarget.value === '';
    }
}
