import { Controller } from '@hotwired/stimulus';

// Поиск лупой в тулбаре списка (03.10.2026). Лупа ставит тулбару data-searching: ряд кнопок и чипов уходит, на его
// месте поле с «Отмена», как в iOS. Два слоя, чтобы отклик был мгновенным:
// 1) первый же знак сужает то, что уже на экране — строки без набранного просто не рисуются;
// 2) через паузу приходит ответ сервера по всему списку — мимо пилюль и чипов — и подменяет список.
// Адрес не меняется: поиск — не фильтр. Сервер отвечает куском списка на X-List, а кто не умеет — целой страницей,
// из неё берётся тот же блок. «Отмена» возвращает список, каким он был, без сети. Устаревшие ответы отбрасываются.
export default class extends Controller {
    static targets = ['input', 'clear'];
    static values = { target: { type: String, default: '#list' }, url: String, min: { type: Number, default: 2 }, wait: { type: Number, default: 200 } };

    connect() {
        this.seq = 0;
        this.sync();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.pending?.abort();
    }

    // Фокус — синхронно в обработчике нажатия: иначе iOS не поднимет клавиатуру.
    open() {
        this.snapshot ??= this.list()?.innerHTML ?? null;
        this.element.setAttribute('data-searching', '');
        this.inputTarget.focus();
    }

    cancel() {
        clearTimeout(this.timer);
        this.pending?.abort();
        this.seq++;
        const loaded = this.inputTarget.value.trim() !== '' || this.loaded;
        this.inputTarget.value = '';
        this.inputTarget.blur();
        this.element.removeAttribute('data-searching');
        this.element.classList.remove('is-busy');
        this.inputTarget.form && delete this.inputTarget.form.dataset.dirty;
        const list = this.list();
        if (list && this.snapshot !== null && this.snapshot !== undefined && loaded) list.innerHTML = this.snapshot;
        else this.narrow('');
        this.snapshot = null;
        this.loaded = false;
        // Пришли сюда с поиском в адресе (страница результатов) — отмена ведёт на сам список.
        if (new URL(location.href).searchParams.has('q')) {
            const url = new URL(location.href);
            url.searchParams.delete('q');
            url.searchParams.delete('page');
            window.Turbo?.visit(url.toString(), { action: 'replace' });
        }
        this.sync();
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

    // Esc — как «Отмена».
    key(event) {
        if (event.key === 'Escape') this.cancel();
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
        const url = new URL(this.urlValue || location.href, location.href);
        if (this.urlValue) new URL(location.href).searchParams.forEach((v, k) => url.searchParams.has(k) || url.searchParams.set(k, v));
        url.searchParams.delete('page');
        url.searchParams.delete('peek');
        if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
        this.pending?.abort();
        this.pending = new AbortController();
        const seq = ++this.seq;
        this.element.classList.add('is-busy');
        try {
            const html = await fetch(url, { headers: { 'X-List': '1' }, signal: this.pending.signal }).then((r) => r.text());
            if (seq !== this.seq) return;
            list.innerHTML = this.extract(html);
            this.loaded = true;
        } catch (e) {
            if (e.name !== 'AbortError') this.narrow('');
        } finally {
            if (seq === this.seq) this.element.classList.remove('is-busy');
        }
    }

    // Целая страница в ответе — берём из неё тот же блок списка.
    extract(html) {
        if (!/^\s*<!doctype/i.test(html)) return html;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.querySelector(this.targetValue)?.innerHTML ?? '';
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
