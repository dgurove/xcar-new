import { Controller } from '@hotwired/stimulus';

// Чат по офферу. Лента дополняется фрагментами «всё после N», где N —
// последний номер на экране: догон после обрыва идемпотентен. По каналу
// приходит только событие {chat, seq}, текст всегда берётся с сервера.
export default class extends Controller {
    static targets = ['list', 'form', 'input', 'files', 'status'];
    static values = { url: String, id: Number, last: Number };

    connect() {
        this.onLive = (e) => e.detail?.chat === this.idValue && e.detail.seq > this.lastValue && this.fetch();
        this.onVisible = () => document.visibilityState === 'visible' && this.fetch();
        document.addEventListener('live:chat', this.onLive);
        document.addEventListener('visibilitychange', this.onVisible);
        this.scroll();
    }

    disconnect() {
        document.removeEventListener('live:chat', this.onLive);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    async fetch() {
        const r = await fetch(`${this.urlValue}?after=${this.lastValue}`, { headers: { Accept: 'text/html' } });
        if (r.ok) this.append(await r.text());
    }

    append(html) {
        const box = document.createElement('div');
        box.innerHTML = html;
        for (const el of [...box.children]) {
            const seq = Number(el.dataset.seq);
            if (seq <= this.lastValue) continue;
            this.listTarget.append(el);
            this.lastValue = seq;
        }
        this.scroll();
    }

    scroll() {
        this.listTarget.scrollTop = this.listTarget.scrollHeight;
    }

    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !('ontouchstart' in window)) {
            event.preventDefault();
            this.formTarget.requestSubmit();
        }
    }

    pick() {
        this.filesTarget.click();
    }

    filesPicked() {
        const n = this.filesTarget.files.length;
        this.statusTarget.hidden = n === 0;
        this.statusTarget.textContent = n ? `Файлов: ${n}` : '';
    }

    async send(event) {
        event.preventDefault();
        const text = this.inputTarget.value.trim();
        const files = [...this.filesTarget.files];
        if (!text && !files.length) return;
        const form = new FormData();
        form.append('text', text);
        form.append('after', this.lastValue);
        files.forEach((f) => form.append('files[]', f));
        this.formTarget.querySelector('button[type=submit], button:not([type])').disabled = true;
        try {
            const r = await fetch(this.urlValue, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'text/html' } });
            if (!r.ok) { const d = await r.json().catch(() => ({})); window.toast?.(d.message || 'Не отправилось', 'danger'); return; }
            this.append(await r.text());
            this.inputTarget.value = '';
            this.filesTarget.value = '';
            this.filesPicked();
        } finally {
            this.formTarget.querySelector('button[type=submit], button:not([type])').disabled = false;
            this.inputTarget.focus();
        }
    }
}
