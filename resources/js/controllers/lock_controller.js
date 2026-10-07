import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { serverNow } from './timer_controller';
import { proof } from '../device-key';

// Замок менеджера (07.10.2026, x-lock.panel, Access\Lock). Состояние и конец окна приносит каждая страница (meta lock).
//   open   — пилюля справа сверху считает до конца окна; нажатие — продлить заранее (renew): окно поверх, экран цел.
//   0:00, ответ 423 сервера, frozen/enroll из meta — заморозка (freeze): экран стирается целиком (страница, снимки Turbo,
//           экраны воркера, предзагрузки, поток хаба), остаются только адрес, прокрутка и набранное в полях — в памяти;
//           окно не закрыть. Если экран на виду — код в Telegram уходит сам, иначе — когда человек вернётся.
//   link   — Telegram не привязан: только «Подключить Telegram».
// Подтверждение — шесть цифр (на шестой уходит само) или «Продолжить» в боте (событие хаба live:lock, возврат на вкладку,
// опрос раз в 4 с, пока окно открыто). Браузер подписывает nonce своим ключом (device-key.js). После подтверждения тот
// же адрес перерисовывается, прокрутка и поля возвращаются. «Вы вошли на другом устройстве» — сразу на вход.
export default class extends Controller {
    static targets = ['pill', 'dialog', 'step', 'title', 'input', 'note', 'resend', 'close', 'linkNote'];
    static values = { botApp: String, botWeb: String, linkApp: String, linkWeb: String, minutes: Number };

    connect() {
        this.offs = [];
        this.on(document, 'turbo:load', () => this.sync());
        this.on(document, 'visibilitychange', () => this.visible());
        this.on(window, 'pageshow', (e) => e.persisted && this.refresh());
        this.on(document, 'turbo:before-fetch-response', (e) => this.response(e));
        this.on(document, 'live:lock', (e) => this.live(e.detail || {}));
        this.timer = setInterval(() => this.tick(), 1000);
        this.sync();
    }

    disconnect() {
        clearInterval(this.timer);
        clearInterval(this.poll);
        clearInterval(this.cooling);
        this.offs.forEach((off) => off());
    }

    on(target, name, handler) {
        target.addEventListener(name, handler);
        this.offs.push(() => target.removeEventListener(name, handler));
    }

    sync() {
        const [state, until] = (document.querySelector('meta[name="lock"]')?.content || '').split(':');
        if (!state) return;
        this.state = state;
        this.until = Number(until) * 1000;
        if (state !== 'open') this.freeze();
        this.tick();
    }

    tick() {
        if (this.state !== 'open' || !this.until) { this.pillTarget.hidden = true; return; }
        const left = Math.ceil((this.until - serverNow()) / 1000);
        if (left <= 0) {
            this.state = 'frozen';
            this.freeze();
            return;
        }
        this.pillTarget.hidden = this.mode === 'freeze';
        const text = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
        if (this.pillTarget.textContent !== text) this.pillTarget.textContent = text;
        this.pillTarget.classList.toggle('is-ending', left <= 60);
    }

    visible() {
        if (document.visibilityState !== 'visible') return;
        this.tick();
        // Вернулись из Telegram: нажали «Продолжить» или привязали — узнать сразу; код ещё не уходил — отправить.
        this.refresh();
        if (this.dialogTarget.open && this.sendOnShow) this.send();
    }

    // ---------------------------------------------------------------- заморозка

    freeze() {
        if (this.mode === 'freeze') return;
        this.snapshot ??= this.capture();
        this.mode = 'freeze';
        this.wipe();
        this.open();
    }

    // Что вернуть после подтверждения: адрес, прокрутка и набранное (только изменённые поля, без скрытых и паролей).
    capture() {
        const fields = [];
        document.querySelectorAll('#main form').forEach((form) => [...form.elements].forEach((el) => {
            if (!el.name || el.name === '_token' || ['hidden', 'password', 'file', 'submit', 'button'].includes(el.type)) return;
            const changed = ['checkbox', 'radio'].includes(el.type) ? el.checked !== el.defaultChecked : el.value !== (el.defaultValue ?? el.value);
            if (changed) fields.push({ action: form.getAttribute('action') || '', name: el.name, value: el.value, checked: el.checked });
        }));
        return { url: location.href, y: scrollY, fields };
    }

    fill(fields) {
        fields.forEach(({ action, name, value, checked }) => {
            const form = [...document.querySelectorAll('#main form')].find((f) => (f.getAttribute('action') || '') === action);
            const el = form?.elements.namedItem(name);
            if (!el || el instanceof RadioNodeList) return;
            if (['checkbox', 'radio'].includes(el.type)) el.checked = checked;
            else el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    // Под окном ничего не остаётся: ни узлов страницы, ни снимков «назад», ни экранов воркера, ни потока хаба.
    wipe() {
        document.dispatchEvent(new CustomEvent('lock:freeze'));
        document.dispatchEvent(new CustomEvent('live:retopic', { detail: { topics: '' } }));
        document.querySelectorAll('dialog[open]').forEach((d) => d !== this.dialogTarget && d.close());
        [...document.body.children].forEach((el) => el !== this.element && el.remove());
        Turbo.cache.clear();
        navigator.serviceWorker?.controller?.postMessage({ forgetPages: true });
    }

    open() {
        const link = this.state === 'link';
        this.stepTargets.forEach((step) => { step.hidden = step.dataset.step !== (link ? 'link' : 'code'); });
        this.titleTarget.textContent = this.mode === 'renew' ? `Ещё ${this.minutesValue} ${plural(this.minutesValue, ['минута', 'минуты', 'минут'])}`
            : this.state === 'enroll' ? 'Подтвердите вход' : 'Продолжить работу';
        this.closeTarget.hidden = this.mode !== 'renew';
        this.dialogTarget.classList.toggle('is-frozen', this.mode === 'freeze');
        this.inputTarget.value = '';
        this.inputTarget.classList.remove('is-wrong');
        this.note('');
        this.nonce = null;
        if (!this.dialogTarget.open) this.dialogTarget.showModal();
        this.pillTarget.hidden = this.mode === 'freeze';
        clearInterval(this.poll);
        this.poll = setInterval(() => document.visibilityState === 'visible' && this.refresh(), 4000);
        if (link) return;
        // Заморозка — сначала спросить сервер: окно могли продлить в другой вкладке. Иначе — код в Telegram.
        if (document.visibilityState !== 'visible') { this.sendOnShow = true; return; }
        if (this.mode === 'freeze') this.refresh().then((open) => !open && this.send());
        else this.send();
    }

    // «Продлить» нажатием на пилюлю — экран цел, окно можно закрыть.
    renew() {
        if (this.dialogTarget.open || this.state !== 'open') return;
        this.mode = 'renew';
        this.open();
        this.inputTarget.focus();
    }

    close() {
        if (this.mode !== 'renew') return;
        this.mode = null;
        this.nonce = null;
        clearInterval(this.poll);
        this.dialogTarget.close();
        this.tick();
    }

    // Esc и «Назад» Android закрывают только продление; заморозку — никогда.
    cancel(event) {
        event.preventDefault();
        this.close();
    }

    // ---------------------------------------------------------------- код

    async send(again = false) {
        this.sendOnShow = false;
        if (this.sending) return;
        this.sending = true;
        this.note(again ? 'Отправляем новый код' : 'Отправляем код');
        const r = await this.post('/lock/code', again ? { again: 1 } : {});
        this.sending = false;
        if (!r) { this.note('Нет связи', true); return; }
        const data = await r.json().catch(() => ({}));
        if (r.status === 401) { location.href = '/login'; return; }
        if (!r.ok) {
            this.note(data.error || 'Не получилось', true);
            if (r.status === 429) this.cool(Number(r.headers.get('Retry-After')) || 0);
            return;
        }
        this.nonce = data.nonce;
        if (data.topics !== undefined) document.dispatchEvent(new CustomEvent('live:retopic', { detail: { topics: data.topics } }));
        if (data.approved) { this.verify(null); return; }
        this.note('Код в Telegram');
        this.cool(data.resend ?? 30);
    }

    resend() {
        if (this.resendTarget.disabled) return;
        this.inputTarget.value = '';
        this.send(true);
    }

    // «Отправить ещё раз» — не раньше, чем через n секунд.
    cool(seconds) {
        clearInterval(this.cooling);
        const button = this.resendTarget;
        const end = Date.now() + seconds * 1000;
        const step = () => {
            const left = Math.ceil((end - Date.now()) / 1000);
            button.disabled = left > 0;
            button.textContent = left > 0 ? `Ещё раз через ${left} с` : 'Отправить ещё раз';
            if (left <= 0) clearInterval(this.cooling);
        };
        step();
        this.cooling = setInterval(step, 1000);
    }

    typed() {
        const digits = this.inputTarget.value.replace(/\D/g, '').slice(0, 6);
        if (digits !== this.inputTarget.value) this.inputTarget.value = digits;
        this.inputTarget.classList.remove('is-wrong');
        if (digits.length === 6) this.verify(digits);
    }

    submit(event) {
        event.preventDefault();
        const digits = this.inputTarget.value.replace(/\D/g, '');
        if (digits.length === 6) this.verify(digits);
    }

    async verify(code) {
        if (!this.nonce || this.verifying) return;
        this.verifying = true;
        let signed;
        try {
            signed = await proof(this.nonce);
        } catch {
            this.verifying = false;
            this.note('Браузер не дал ключ устройства, обновите его', true);
            return;
        }
        if (code) this.note('Проверяем');
        const r = await this.post('/lock/verify', { ...signed, ...(code ? { code } : {}) });
        this.verifying = false;
        if (!r) { this.note('Нет связи', true); return; }
        if (r.status === 202) return;
        const data = await r.json().catch(() => ({}));
        if (r.ok) { this.done(data); return; }
        if (r.status === 401 || r.status === 409) { location.reload(); return; }
        this.note(data.error || 'Не получилось', true);
        if (code) {
            this.inputTarget.value = '';
            this.inputTarget.classList.add('is-wrong');
            navigator.vibrate?.([10, 40, 10]);
        }
        if (r.status === 410) { this.nonce = null; this.cool(0); }
    }

    done({ until, csrf, topics }) {
        this.state = 'open';
        this.until = (until || 0) * 1000;
        this.nonce = null;
        clearInterval(this.poll);
        if (csrf) {
            document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', csrf);
            document.querySelectorAll('input[name="_token"]').forEach((i) => { i.value = csrf; });
        }
        navigator.vibrate?.(20);
        if (this.mode === 'renew') {
            this.mode = null;
            this.dialogTarget.close();
            document.dispatchEvent(new CustomEvent('live:retopic', { detail: { topics } }));
            this.tick();
            return;
        }
        this.restore();
    }

    // Тот же адрес настоящей страницей; окно закрывается, когда она уже на месте — пустой экран не мелькает.
    restore() {
        const snap = this.snapshot ?? { url: location.href, y: 0, fields: [] };
        this.snapshot = null;
        this.note('');
        document.addEventListener('turbo:load', () => requestAnimationFrame(() => {
            if (snap.y) scrollTo(0, snap.y);
            this.fill(snap.fields);
            this.mode = null;
            if (this.dialogTarget.open) this.dialogTarget.close();
            this.tick();
        }), { once: true });
        Turbo.visit(snap.url, { action: 'replace' });
    }

    // ---------------------------------------------------------------- сервер и Telegram

    // Где браузер на самом деле: окно продлили в другой вкладке, нажали кнопку в боте, привязали Telegram. true — открыто.
    async refresh() {
        if (this.verifying || this.sending) return false;
        let r;
        try { r = await fetch('/lock/state', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }); } catch { return false; }
        if (r.status === 401) { location.href = '/login'; return false; }
        if (!r.ok) return false;
        const { state, until, code } = await r.json();
        if (!state) return false;
        if (state === 'open') {
            this.until = until * 1000;
            const wasFrozen = this.mode === 'freeze';
            this.state = 'open';
            if (wasFrozen) this.restore();
            this.tick();
            return true;
        }
        if (this.state === 'link' && state === 'enroll') {
            this.state = 'enroll';
            this.open();
            return false;
        }
        this.state = state;
        if (this.mode !== 'freeze' && !this.dialogTarget.open) this.freeze();
        if (code === 'approved') this.verify(null);
        else if (code === 'denied') this.note('Отклонено в Telegram', true);
        return false;
    }

    live({ state }) {
        if (state === 'approved') this.verify(null);
        else if (state === 'denied') this.note('Отклонено в Telegram', true);
        else if (state === 'kicked') { this.wipe(); location.href = '/login'; }
    }

    // Ответ 423 на любой запрос Turbo (переход, фрейм, форма) — сервер уже закрыт: замерзаем сами, ответ не рисуем.
    response(event) {
        const status = event.detail.fetchResponse?.statusCode;
        if (status === 423) {
            event.preventDefault();
            if (this.state === 'open') this.state = 'frozen';
            this.freeze();
        } else if (status === 401 && this.state) {
            event.preventDefault();
            location.href = '/login';
        }
    }

    openBot() {
        this.go(this.botAppValue, this.botWebValue);
    }

    link() {
        this.linkNoteTarget.textContent = 'Нажмите «Запустить» и вернитесь сюда';
        this.go(this.linkAppValue, this.linkWebValue);
    }

    // На телефоне — сразу в приложение Telegram, нет его — t.me; на ПК — t.me новой вкладкой.
    go(app, web) {
        if (!matchMedia('(hover: none) and (pointer: coarse)').matches) { window.open(web, '_blank', 'noopener'); return; }
        location.href = app;
        setTimeout(() => document.visibilityState === 'visible' && (location.href = web), 1200);
    }

    note(text, danger = false) {
        this.noteTarget.textContent = text;
        this.noteTarget.classList.toggle('is-danger', danger);
    }

    async post(url, data) {
        const body = new FormData();
        Object.entries(data).forEach(([k, v]) => body.append(k, v));
        try {
            const r = await fetch(url, {
                method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
            const date = Date.parse(r.headers.get('Date') || '');
            if (date) window.clockOffset = date - Date.now();
            return r;
        } catch {
            return null;
        }
    }
}

function plural(n, [one, few, many]) {
    const a = n % 10, b = n % 100;
    return a === 1 && b !== 11 ? one : a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many;
}
