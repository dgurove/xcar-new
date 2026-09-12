import { Controller } from '@hotwired/stimulus';

// Чат по офферу. Лента дополняется фрагментами «всё после N», где N —
// последний номер на экране: догон после обрыва идемпотентен. По каналу
// приходит только событие {chat, seq}, текст всегда берётся с сервера.
export default class extends Controller {
    static targets = ['list', 'form', 'input', 'files', 'status'];
    static values = { url: String, open: String, id: Number, last: Number };

    connect() {
        this.onLive = (e) => e.detail?.chat === this.idValue && e.detail.seq > this.lastValue && this.fetch();
        this.onVisible = () => document.visibilityState === 'visible' && this.fetch();
        this.onOpen = () => setTimeout(() => this.fetch(), 50);
        document.addEventListener('live:chat', this.onLive);
        document.addEventListener('visibilitychange', this.onVisible);
        window.addEventListener('chat:open', this.onOpen);
        // Страховка без живого канала: раз в 20 секунд, пока лента на экране.
        this.timer = setInterval(() => this.fetch(), 20000);
        this.scroll();
    }

    disconnect() {
        document.removeEventListener('live:chat', this.onLive);
        document.removeEventListener('visibilitychange', this.onVisible);
        window.removeEventListener('chat:open', this.onOpen);
        clearInterval(this.timer);
    }

    // Лента видна — значит прочитано; в закрытой шторке догоняем молча, бейдж остаётся.
    visible() {
        return this.element.checkVisibility?.() ?? true;
    }

    async fetch() {
        if (!this.urlValue) return;
        const r = await fetch(`${this.urlValue}?after=${this.lastValue}&read=${this.visible() ? 1 : 0}`, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
        if (r.ok) this.append(await r.text());
    }

    append(html) {
        const box = document.createElement('div');
        box.innerHTML = html;
        // Первое сообщение завело чат: приветствие-заглушка уходит, лента дальше живёт по seq.
        if (!this.lastValue) this.listTarget.replaceChildren();
        for (const el of [...box.children]) {
            const seq = Number(el.dataset.seq);
            if (!seq || seq <= this.lastValue) continue;
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
            // Чата ещё нет — первое сообщение уходит на open, ответ приносит адрес ленты.
            const r = await fetch(this.urlValue || this.openValue, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!r.ok) {
                const d = await r.json().catch(() => ({}));
                window.toast?.(d.message || (r.status === 401 || r.status === 419 ? 'Войдите заново' : 'Не отправилось'), 'danger');
                return;
            }
            if (!this.urlValue) { this.urlValue = r.headers.get('X-Chat-Url') || ''; this.idValue = Number(r.headers.get('X-Chat-Id') || 0); }
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
