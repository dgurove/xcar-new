import { Controller } from '@hotwired/stimulus';

// «Отправить» в редакторе письма ждёт настоящей отправки (15.11.2026, начальник «постоянно переживает, отправлено письмо
// или нет»; владелец: «в нормальных почтовых клиентах, пока письмо не отправилось, редактор тупо не закрывается, анимация —
// в редакторе при нажатии на „Отправить“»). Нажали — поля замирают, на кнопке крутилка «Отправляем». Сервер завёл письмо и
// ответил редиректом на `messages/{id}/sent` — с заголовком `X-Compose-Send` там JSON; Turbo этот ответ не рисует
// (`turbo:before-fetch-response` отменён), редактор ждёт `send_state = sent`: событие автору `live:mail-sent`
// (`SendMessage::tellAuthor`) и опрос `state` раз в 1,5 с — хаб мог моргнуть. Ушло — кнопка зелёная с галкой «Отправлено»,
// потом окно закрывается как сделанное (`then=close`) или открывается ветка. Не ушло — причина над кнопкой, всё на месте,
// повторная отправка заменяет неудачное письмо (`replaces`, `DropFailed`). Дольше 20 с — «уйдёт само, можно закрыть».
// Ошибки полей (редирект на `retry`) Turbo рисует как обычно.
const FINAL = ['sent', 'failed'];
const SENT = /\/messages\/(\d+)\/sent(?:\?|$)/;
// «Запланировать» (27.11.2026, `Offers\PlannedLetters`): письмо не уходит сейчас — ответ `planned/{id}/done`, окно закрывается.
const PLANNED = /\/planned\/\d+\/done(?:\?|$)/;

export default class extends Controller {
    static targets = ['button', 'label', 'error', 'cancel', 'replaces'];

    connect() {
        this.onLive = (e) => { if (this.id && Number(e.detail?.id) === this.id) this.apply(e.detail); };
        this.onRequest = (e) => { if (e.target === this.element) e.detail.fetchOptions.headers['X-Compose-Send'] = '1'; };
        this.onStart = (e) => { if (e.target === this.element) this.sending(); };
        this.onResponse = (e) => this.response(e);
        // Turbo по концу отправки снова включает кнопку — письмо ещё уходит, держим её выключенной.
        this.onEnd = (e) => {
            if (e.target !== this.element) return;
            if (this.id && !this.done) this.buttonTarget.disabled = true;
            else if (!e.detail.success && !this.id) { delete this.element.dataset.draftKeep; this.idle(); }
        };
        document.addEventListener('live:mail-sent', this.onLive);
        this.element.addEventListener('turbo:before-fetch-request', this.onRequest);
        this.element.addEventListener('turbo:submit-start', this.onStart);
        this.element.addEventListener('turbo:before-fetch-response', this.onResponse);
        this.element.addEventListener('turbo:submit-end', this.onEnd);
    }

    disconnect() {
        document.removeEventListener('live:mail-sent', this.onLive);
        clearTimeout(this.timer);
        clearTimeout(this.after);
    }

    sending() {
        this.id = null;
        this.done = false;
        this.started = Date.now();
        this.slow = false;
        this.element.dataset.mailSending = '';
        // Черновик стирается, только когда письмо ушло (`draft:clear`), а не концом отправки формы.
        this.element.dataset.draftKeep = '';
        this.state('sending', this.element.querySelector('input[name="plan"]') ? 'Планируем' : 'Отправляем');
        this.errorTarget.hidden = true;
        this.fields(false);
    }

    async response(e) {
        const res = e.detail.fetchResponse;
        const href = res?.location?.href || res?.response?.url || '';
        if (PLANNED.test(href) && res.succeeded) {
            e.preventDefault();
            this.done = true;
            delete this.element.dataset.mailSending;
            delete this.element.dataset.draftKeep;
            this.element.dispatchEvent(new CustomEvent('draft:clear'));
            this.state('sent', 'Запланировано');
            try { const data = JSON.parse(await res.responseText); if (data.toast) window.toast?.(data.toast); } catch { /* без тоста */ }
            this.after = setTimeout(() => window.dispatchEvent(new CustomEvent('letters:close')), 900);
            return;
        }
        const match = href.match(SENT);
        if (!match || !res.succeeded) return;
        e.preventDefault();
        this.id = Number(match[1]);
        this.url = res.location.href.replace(/\/sent(\?.*)?$/, '/state');
        this.close = /[?&]then=close/.test(res.location.href);
        try {
            const data = JSON.parse(await res.responseText);
            if (data.toast) window.toast?.(data.toast);
            this.apply(data);
        } catch { /* ответ без JSON — узнаем опросом */ }
        if (!this.done) this.poll();
    }

    async poll() {
        clearTimeout(this.timer);
        const id = this.id;
        if (!this.element.isConnected || this.done || !id) return;
        try {
            const res = await fetch(this.url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const data = res.ok ? await res.json() : null;
            if (data && id === this.id) this.apply(data);
        } catch { /* нет связи — следующий раз */ }
        if (this.done || id !== this.id) return;
        if (Date.now() - this.started > 20000 && !this.slow) {
            this.slow = true;
            this.say('Письмо в очереди и уйдёт само, окно можно закрыть', false);
            this.cancelTargets.forEach((c) => { c.hidden = false; c.textContent = 'Закрыть'; });
        }
        this.timer = setTimeout(() => this.poll(), 1500);
    }

    // Ответ одного письма приходит и событием, и опросом — применяется первый, пока ждём именно его (`this.id`).
    apply({ state, error, thread } = {}) {
        if (thread) this.thread = thread;
        if (!this.id || this.done || !FINAL.includes(state)) return;
        this.done = true;
        clearTimeout(this.timer);
        delete this.element.dataset.mailSending;
        if (state === 'sent') {
            this.state('sent', 'Отправлено');
            this.errorTarget.hidden = true;
            delete this.element.dataset.draftKeep;
            this.element.dispatchEvent(new CustomEvent('draft:clear'));
            if (!/iphone|ipad/i.test(navigator.userAgent)) navigator.vibrate?.([10, 40, 10]);
            this.after = setTimeout(() => this.leave(), 1300);
        } else {
            this.replacesTarget.value = String(this.id);
            this.id = null;
            this.done = false;
            clearTimeout(this.timer);
            this.idle();
            this.say(error || 'Почтовый сервер не принял письмо, попробуйте ещё раз', true);
        }
    }

    // Ушло: после «Принять» окно закрывается как сделанное (`window_controller#close`), ответ — ветка в окне.
    leave() {
        if (this.close || !this.thread) {
            window.dispatchEvent(new CustomEvent('letters:close'));
            return;
        }
        const frame = this.element.closest('turbo-frame[id="letters-frame"]') || document.getElementById('letters-frame');
        if (frame) frame.src = this.thread; else window.Turbo?.visit(this.thread.replace(/\/window$/, ''));
    }

    idle() {
        delete this.element.dataset.mailSending;
        this.state(null, this.element.querySelector('input[name="plan"]') ? 'Запланировать' : 'Отправить');
        this.fields(true);
        this.cancelTargets.forEach((c) => { c.hidden = false; c.textContent = 'Отмена'; });
    }

    state(name, label) {
        const b = this.buttonTarget;
        if (name) b.dataset.state = name; else delete b.dataset.state;
        b.disabled = name === 'sending' || name === 'sent';
        b.toggleAttribute('aria-busy', false);
        this.labelTarget.textContent = label;
        // Пока письма ещё нет на сервере, «Отмена» его не отменит — прячем; появится, если отправка затянулась.
        if (name) this.cancelTargets.forEach((c) => { c.hidden = name === 'sending' && !this.slow || name === 'sent'; });
    }

    say(text, danger) {
        this.errorTarget.textContent = text;
        this.errorTarget.classList.toggle('is-quiet', !danger);
        this.errorTarget.hidden = false;
    }

    // Поля замирают, пока письмо уходит: `inert` у плашки письма, кроме подвала с кнопкой.
    fields(on) {
        const compose = this.element.querySelector('.compose');
        compose?.querySelectorAll(':scope > :not(.compose-foot):not(.compose-status)').forEach((el) => { el.inert = !on; });
        compose?.querySelectorAll('.compose-tool').forEach((el) => { el.disabled = !on; });
        compose?.classList.toggle('is-sending', !on);
    }
}
