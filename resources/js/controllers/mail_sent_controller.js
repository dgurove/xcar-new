import { Controller } from '@hotwired/stimulus';

// Экран отправки письма (admin/mail/sent-frame, 15.11.2026: начальник не был уверен, что письмо ушло). Ждёт, пока почтовый
// сервер примет письмо: событие автору `live:mail-sent` (`SendMessage::tellAuthor`) и, на случай если хаб моргнул, опрос
// `state` раз в 1,5 с. Ушло — зелёная галка; после «Принять» (`close`) окно закрывается само через 1,6 с — как поток
// `letters-close` (письмо считается сделанным, «Отдано» не напомнит). Не ушло — красный круг, причина и «Отправить снова».
// Дольше 30 с без ответа — «Письмо в очереди…» и «Готово»: уведомление «Не ушло» придёт и так.
const FINAL = ['sent', 'failed'];

export default class extends Controller {
    static targets = ['title', 'note', 'retry', 'done'];
    static values = { id: Number, url: String, close: Boolean };

    connect() {
        this.started = Date.now();
        this.onLive = (e) => { if (Number(e.detail?.id) === this.idValue) this.apply(e.detail); };
        document.addEventListener('live:mail-sent', this.onLive);
        if (FINAL.includes(this.element.dataset.state)) {
            // Окно открыли на уже ушедшем письме (перечитывание, «Назад») — без повторной анимации.
            this.element.classList.add('is-still');
            return;
        }
        this.poll();
    }

    disconnect() {
        document.removeEventListener('live:mail-sent', this.onLive);
        clearTimeout(this.timer);
        clearTimeout(this.closer);
    }

    async poll() {
        if (!this.element.isConnected || FINAL.includes(this.element.dataset.state)) return;
        try {
            const res = await fetch(this.urlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (res.ok) this.apply(await res.json());
        } catch { /* нет связи — следующий раз */ }
        if (FINAL.includes(this.element.dataset.state)) return;
        if (Date.now() - this.started > 30000 && !this.slow) {
            this.slow = true;
            this.noteTarget.textContent = 'Письмо в очереди и уйдёт само. Если не уйдёт, придёт уведомление';
            this.doneTarget.textContent = 'Закрыть';
            this.doneTarget.hidden = false;
        }
        this.timer = setTimeout(() => this.poll(), 1500);
    }

    apply({ state, error, at } = {}) {
        if (!state || FINAL.includes(this.element.dataset.state) || !FINAL.includes(state)) return;
        this.element.dataset.state = state;
        clearTimeout(this.timer);
        if (state === 'sent') {
            this.titleTarget.textContent = 'Письмо ушло';
            this.noteTarget.textContent = at || '';
            this.doneTarget.hidden = false;
            if (!/iphone|ipad/i.test(navigator.userAgent)) navigator.vibrate?.([10, 40, 10]);
            if (this.closeValue) this.closer = setTimeout(() => window.dispatchEvent(new CustomEvent('letters:close')), 1600);
        } else {
            this.titleTarget.textContent = 'Не ушло';
            this.noteTarget.textContent = error || '';
            this.doneTarget.textContent = 'Закрыть';
            this.retryTarget.hidden = false;
            this.doneTarget.hidden = false;
        }
    }

    // «Готово»: ушло — как поток `letters-close` (письмо сделано), иначе обычное закрытие окна.
    close() {
        window.dispatchEvent(new CustomEvent('letters:close'));
    }
}
