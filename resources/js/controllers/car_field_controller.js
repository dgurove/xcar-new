import { Controller } from '@hotwired/stimulus';

// ТС полем формы с подсказкой машин системы (x-billing.car-field, 09.10.2026). Набранное — значение (машина словами);
// подсказка — до четырёх машин под полем, от двух знаков; выбор ставит `offer_id` и название, правка текста его снимает.
// Выбор — по щелчку: фокус уходит раньше, поэтому подсказки прячутся через 150 мс (pointerdown с preventDefault нельзя —
// WebKit тогда не даёт щелчка от пальца). preview — исход привязки в карточке ссылки (`DealCredit::outcome`): подпись
// кнопки (цель submit) и строка note.
export default class extends Controller {
    static targets = ['input', 'offer', 'hints', 'mark', 'clear', 'submit', 'note'];
    static values = { url: String, preview: String };

    connect() {
        this.picked = this.offerTarget.value ? this.inputTarget.value : null;
    }

    disconnect() {
        clearTimeout(this.timer);
        clearTimeout(this.hideTimer);
        this.abort?.abort();
        this.previewAbort?.abort();
    }

    input() {
        // Правка текста выбранной машины — уже не она, а слова.
        if (this.offerTarget.value && this.inputTarget.value !== this.picked) this.setOffer(null);
        this.sync();
        clearTimeout(this.timer);
        const q = this.inputTarget.value.trim();
        if (q.length < 2) { this.hide(); this.schedulePreview(); return; }
        this.timer = setTimeout(() => this.search(q), 250);
        this.schedulePreview();
    }

    focus() {
        clearTimeout(this.hideTimer);
    }

    blur() {
        this.hideTimer = setTimeout(() => this.hide(), 150);
    }

    // «Готово» на клавиатуре — убрать её, а не отправить форму.
    key(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        this.inputTarget.blur();
    }

    clear() {
        this.inputTarget.value = '';
        this.setOffer(null);
        this.sync();
        this.hide();
        this.schedulePreview(0);
    }

    async search(q) {
        const url = new URL(this.urlValue, location.href);
        url.searchParams.set('q', q);
        url.searchParams.set('limit', '4');
        this.abort?.abort();
        const abort = this.abort = new AbortController();
        try {
            const res = await fetch(url, { signal: abort.signal, headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const items = await res.json();
            if (abort.signal.aborted || document.activeElement !== this.inputTarget) return;
            this.render(items.filter((i) => String(i.id) !== this.offerTarget.value));
        } catch {}
    }

    render(items) {
        this.hintsTarget.replaceChildren(...items.map((item) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'car-field-hint';
            // Подписи — данные (марка, номер, имя): текстом, не разметкой.
            b.append(Object.assign(document.createElement('span'), { className: 'block truncate', textContent: item.label }));
            if (item.hint) b.append(Object.assign(document.createElement('span'), { className: 'block truncate text-sm text-ink-muted', textContent: item.hint }));
            b.addEventListener('click', () => this.choose(item));
            return b;
        }));
        this.hintsTarget.hidden = items.length === 0;
    }

    hide() {
        this.hintsTarget.hidden = true;
        this.hintsTarget.replaceChildren();
    }

    choose(item) {
        clearTimeout(this.hideTimer);
        this.inputTarget.value = item.label;
        this.setOffer(item);
        this.sync();
        this.hide();
        this.dispatch('chosen', { detail: item });
        this.schedulePreview(0);
    }

    setOffer(item) {
        const id = item ? String(item.id) : '';
        this.picked = item ? item.label : null;
        if (this.offerTarget.value === id) return;
        this.offerTarget.value = id;
        this.offerTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    sync() {
        this.markTarget.hidden = !this.offerTarget.value;
        this.clearTarget.hidden = !this.inputTarget.value || !!this.offerTarget.value;
    }

    schedulePreview(delay = 500) {
        if (!this.previewValue) return;
        clearTimeout(this.previewTimer);
        this.previewTimer = setTimeout(() => this.preview(), delay);
    }

    // Выбрали другую машину или дописали слова, пока ответ о прошлом в пути, — прошлый отменяется.
    async preview() {
        const url = new URL(this.previewValue, location.href);
        if (this.offerTarget.value) url.searchParams.set('offer_id', this.offerTarget.value);
        else if (this.inputTarget.value.trim()) url.searchParams.set('car_title', this.inputTarget.value.trim());
        this.previewAbort?.abort();
        const abort = this.previewAbort = new AbortController();
        try {
            const res = await fetch(url, { signal: abort.signal, headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const { label, note } = await res.json();
            if (abort.signal.aborted) return;
            if (this.hasSubmitTarget) this.submitTarget.textContent = label;
            if (this.hasNoteTarget) { this.noteTarget.textContent = note ?? ''; this.noteTarget.hidden = !note; }
        } catch {}
    }
}
