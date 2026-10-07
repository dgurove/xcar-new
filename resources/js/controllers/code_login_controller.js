import { Controller } from '@hotwired/stimulus';
import { proof } from '../device-key';

// Вход кодом из Telegram (страница входа, 07.10.2026): логин из поля формы выше → «Получить код» → бот присылает шесть
// цифр с кнопкой «Войти». Цифры уходят сами на шестой; нажали «Войти» в боте — страница узнаёт событием хаба (live:lock)
// или, без него, когда человек вернулся на вкладку или в окно (опроса нет, CLAUDE.md). Браузер подписывает nonce своим
// ключом (device-key.js): менеджер под замком этим входом заводит браузер устройством.
export default class extends Controller {
    static targets = ['request', 'box', 'code', 'note'];

    connect() {
        this.onLive = (e) => e.detail?.state === 'approved' ? this.verify(null) : e.detail?.state === 'denied' && this.note('Отклонено в Telegram', true);
        this.onVisible = () => document.visibilityState === 'visible' && this.nonce && this.verify(null);
        document.addEventListener('live:lock', this.onLive);
        document.addEventListener('visibilitychange', this.onVisible);
        window.addEventListener('focus', this.onVisible);
    }

    disconnect() {
        document.removeEventListener('live:lock', this.onLive);
        document.removeEventListener('visibilitychange', this.onVisible);
        window.removeEventListener('focus', this.onVisible);
        clearInterval(this.cooling);
    }

    async request() {
        const login = document.querySelector('input[name="login"]');
        if (!login?.value.trim()) {
            login?.focus();
            this.note('Введите логин, телефон или почту', true);
            return;
        }
        if (this.requestTarget.disabled) return;
        this.note('Отправляем код');
        this.requestTarget.disabled = true;
        const r = await this.post('/login/code', { login: login.value.trim() });
        const data = r ? await r.json().catch(() => ({})) : {};
        if (!r?.ok) {
            this.requestTarget.disabled = false;
            this.note(data.error || (data.errors ? 'Введите логин, телефон или почту' : 'Нет связи'), true);
            return;
        }
        this.nonce = data.nonce;
        if (data.topics !== undefined) document.dispatchEvent(new CustomEvent('live:retopic', { detail: { topics: data.topics } }));
        this.boxTarget.hidden = false;
        this.codeTarget.value = '';
        this.codeTarget.focus();
        this.note('Код в Telegram');
        this.cool(data.resend ?? 30);
    }

    cool(seconds) {
        clearInterval(this.cooling);
        const button = this.requestTarget;
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
        const digits = this.codeTarget.value.replace(/\D/g, '').slice(0, 6);
        if (digits !== this.codeTarget.value) this.codeTarget.value = digits;
        this.codeTarget.classList.remove('is-wrong');
        if (digits.length === 6) this.verify(digits);
    }

    async verify(code) {
        if (!this.nonce || this.verifying) return;
        this.verifying = true;
        let signed;
        try { signed = await proof(this.nonce); } catch { this.verifying = false; this.note('Браузер не дал ключ устройства, обновите его', true); return; }
        const r = await this.post('/login/code/verify', { ...signed, ...(code ? { code } : {}) });
        this.verifying = false;
        if (!r || r.status === 202) return;
        const data = await r.json().catch(() => ({}));
        if (r.ok) {
            this.note('Входим');
            location.href = data.href || '/';
            return;
        }
        if (!code && r.status !== 410) return;
        this.note(data.error || 'Не получилось', true);
        if (code) {
            this.codeTarget.value = '';
            this.codeTarget.classList.add('is-wrong');
            navigator.vibrate?.([10, 40, 10]);
        }
        if (r.status === 410) { this.nonce = null; this.cool(0); }
    }

    note(text, danger = false) {
        this.noteTarget.textContent = text;
        this.noteTarget.classList.toggle('is-danger', danger);
    }

    async post(url, data) {
        const body = new FormData();
        Object.entries(data).forEach(([k, v]) => body.append(k, v));
        try {
            return await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } });
        } catch {
            return null;
        }
    }
}
