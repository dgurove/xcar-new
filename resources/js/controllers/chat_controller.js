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
        const atBottom = this.atBottom();
        let mine = false;
        for (const el of [...box.children]) {
            const seq = Number(el.dataset.seq);
            if (!seq || seq <= this.lastValue) continue;
            this.listTarget.querySelector('[data-pending]')?.remove();
            this.listTarget.append(el);
            this.lastValue = seq;
            if (el.classList.contains('justify-end')) mine = true;
        }
        // Вниз — если и так были внизу или это своё; иначе читающего не дёргать, показать «↓».
        if (atBottom || mine) this.scroll();
        else this.unseen(1);
    }

    atBottom() {
        const l = this.listTarget;
        return l.scrollHeight - l.scrollTop - l.clientHeight < 80;
    }

    scroll() {
        this.listTarget.scrollTop = this.listTarget.scrollHeight;
        this.unseen(0);
    }

    unseen(add) {
        let pill = this.element.querySelector('.chat-down');
        if (!add) { pill?.remove(); return; }
        if (!pill) {
            pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'chat-down';
            pill.dataset.count = '0';
            pill.addEventListener('click', () => this.scroll());
            this.listTarget.after(pill);
        }
        pill.dataset.count = String(Number(pill.dataset.count) + add);
        pill.textContent = `↓ ${pill.dataset.count}`;
    }

    // Пузырь появляется в момент отправки; ответ сервера его заменяет, ошибка красит — тап повторяет.
    pending(text, files) {
        const el = document.createElement('div');
        el.className = 'flex justify-end';
        el.dataset.pending = '1';
        el.innerHTML = '<div class="max-w-[85%] rounded-(--radius-l) bg-accent-soft px-3.5 py-2.5 opacity-70"><div class="whitespace-pre-line break-words"></div></div>';
        el.querySelector('div > div').textContent = text || (files.length ? `Файлов: ${files.length}` : '');
        this.listTarget.append(el);
        this.scroll();
        return el;
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
        event?.preventDefault();
        const text = this.inputTarget.value.trim();
        const files = [...this.filesTarget.files];
        if (!text && !files.length) return;
        this.inputTarget.value = '';
        this.formTarget.dispatchEvent(new CustomEvent('draft:clear'));
        this.filesTarget.value = '';
        this.filesPicked();
        this.inputTarget.focus();
        await this.deliver(text, files);
    }

    async deliver(text, files) {
        this.listTarget.querySelector('[data-pending]')?.remove();
        const bubble = this.pending(text, files);
        const form = new FormData();
        form.append('text', text);
        form.append('after', this.lastValue);
        files.forEach((f) => form.append('files[]', f));
        try {
            // Чата ещё нет — первое сообщение уходит на open, ответ приносит адрес ленты.
            const r = await fetch(this.urlValue || this.openValue, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!r.ok) {
                const d = await r.json().catch(() => ({}));
                throw new Error(d.message || (r.status === 401 || r.status === 419 ? 'Войдите заново' : 'Не отправилось'));
            }
            if (!this.urlValue) { this.urlValue = r.headers.get('X-Chat-Url') || ''; this.idValue = Number(r.headers.get('X-Chat-Id') || 0); }
            this.append(await r.text());
            bubble.remove();
        } catch (e) {
            bubble.classList.add('is-failed');
            bubble.title = e.message;
            bubble.addEventListener('click', () => { bubble.remove(); this.deliver(text, files); }, { once: true });
        }
    }
}
