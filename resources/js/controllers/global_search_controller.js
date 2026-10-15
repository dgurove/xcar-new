import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Поиск в шапке — один на три хоста (10.10.2026): со второго знака через 150 мс фрейм `global-search` берёт выдачу
// `/search?q=` (устаревший ответ отбрасывает сам фрейм — новый src отменяет прежний запрос). На ПК выдача — выпадающим
// листом под полем, на телефоне — в шторке. ↑/↓ — по строкам `[data-hit]`, Enter — выбранная (иначе первая), Esc —
// закрыть; «/» и ⌘K — фокус в поле из любого места (`hotkey: false` — на странице `/search`, там поле своё). Пустое
// поле — пять последних запросов пилюлями. «Все ›» стоит в подписи группы, а стрелками идёт после её строк.
export default class extends Controller {
    static targets = ['input', 'frame', 'pop', 'recent', 'clear'];
    static values = { url: { type: String, default: '/search' }, hotkey: { type: Boolean, default: true } };

    connect() {
        this.index = -1;
        this.onKey = (e) => this.hotkey(e);
        this.onOutside = (e) => { if (this.hasPopTarget && !this.element.contains(e.target)) this.close(); };
        // Выдача по какому запросу на экране: Enter не должен открыть строку прежнего запроса, пока новый ещё в пути.
        this.onLoad = () => { this.index = -1; this.shown = new URL(this.frameTarget.src || '', location.href).searchParams.get('q'); this.mark(); };
        document.addEventListener('keydown', this.onKey);
        document.addEventListener('pointerdown', this.onOutside);
        this.frameTarget.addEventListener('turbo:frame-load', this.onLoad);
        this.renderRecent();
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
        document.removeEventListener('pointerdown', this.onOutside);
        this.frameTarget.removeEventListener('turbo:frame-load', this.onLoad);
        clearTimeout(this.timer);
    }

    input() {
        clearTimeout(this.timer);
        const q = this.inputTarget.value.trim();
        if (this.hasClearTarget) this.clearTarget.hidden = !this.inputTarget.value;
        if (q.length < 2) {
            this.frameTarget.removeAttribute('src');
            this.frameTarget.innerHTML = '';
            this.renderRecent();
            // Пустой блок не рисуется: без последних запросов показывать нечего.
            if (this.recent().length) this.open(); else this.close();
            return;
        }
        if (this.hasRecentTarget) this.recentTarget.hidden = true;
        this.open();
        this.timer = setTimeout(() => { this.frameTarget.src = `${this.urlValue}?q=${encodeURIComponent(q)}`; }, 150);
    }

    focus() {
        this.renderRecent();
        if (this.inputTarget.value.trim().length >= 2 || this.recent().length) this.open();
    }

    keydown(event) {
        const hits = this.hits();
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!hits.length) return;
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.index = (this.index + step + hits.length) % hits.length;
            this.mark();
            hits[this.index].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter') {
            const q = this.inputTarget.value.trim();
            const hit = this.shown === q ? (hits[this.index] ?? hits[0]) : null;
            this.remember(q);
            // Выдача по этому запросу ещё не пришла — форма уходит как есть: поиск страницей `/search?q=`.
            if (!hit) return;
            event.preventDefault();
            hit.click();
        } else if (event.key === 'Escape') {
            if (this.inputTarget.value) {
                this.inputTarget.value = '';
                this.input();
            } else {
                this.close();
                this.inputTarget.blur();
            }
        }
    }

    // Нажатие по строке выдачи — запрос в «последние».
    picked(event) {
        if (event.target.closest('[data-hit]')) this.remember(this.inputTarget.value.trim());
    }

    clear() {
        this.inputTarget.value = '';
        focusField(this.inputTarget);
        this.input();
    }

    pick(event) {
        this.inputTarget.value = event.params.q;
        focusField(this.inputTarget);
        this.input();
    }

    hotkey(event) {
        const typing = event.target.closest?.('input, textarea, select, [contenteditable]');
        const slash = event.key === '/' && !typing;
        const cmdK = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k';
        if (!this.hotkeyValue || (!slash && !cmdK)) return;
        // Поле на телефоне — в шторке: его открывает своя кнопка, клавиатурой не зовём.
        if (!this.inputTarget.offsetParent) return;
        event.preventDefault();
        this.inputTarget.focus();
        this.inputTarget.select();
    }

    open() {
        if (this.hasPopTarget) this.popTarget.hidden = false;
    }

    close() {
        if (this.hasPopTarget) this.popTarget.hidden = true;
        this.index = -1;
        this.mark();
    }

    hits() {
        return [...this.frameTarget.querySelectorAll('.search-group')].flatMap((g) => [...g.querySelectorAll('.search-rows [data-hit]'), ...g.querySelectorAll('.search-all')]);
    }

    mark() {
        this.hits().forEach((h, i) => h.classList.toggle('is-active', i === this.index));
    }

    recent() {
        try { return JSON.parse(localStorage.getItem('search:recent') || '[]'); } catch { return []; }
    }

    remember(q) {
        if (q.length < 2) return;
        const list = [q, ...this.recent().filter((x) => x !== q)].slice(0, 5);
        try { localStorage.setItem('search:recent', JSON.stringify(list)); } catch {}
    }

    renderRecent() {
        if (!this.hasRecentTarget) return;
        const list = this.recent();
        this.recentTarget.hidden = !list.length || this.inputTarget.value.trim().length >= 2;
        this.recentTarget.replaceChildren(...list.map((q) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pill';
            b.textContent = q;
            b.dataset.action = 'global-search#pick';
            b.dataset.globalSearchQParam = q;
            return b;
        }));
    }
}
