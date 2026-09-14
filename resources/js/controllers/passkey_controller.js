import { Controller } from '@hotwired/stimulus';

const lib = () => import('@simplewebauthn/browser');

// Ключ доступа (Face ID / Touch ID / Windows Hello). Кнопка есть везде, где
// браузер умеет WebAuthn. На входе, если клавиатура умеет предлагать ключ
// сама (conditional mediation), церемония стартует тихо при открытии
// страницы и не мешает: нажатие кнопки или уход со страницы её снимает.
export default class extends Controller {
    static targets = ['button'];
    static values = { mode: String };

    async connect() {
        const w = await lib();
        if (!w.browserSupportsWebAuthn()) return;
        this.element.hidden = false;
        if (this.hasButtonTarget) this.buttonTarget.querySelector('[data-label]').textContent = this.label();
        if (this.modeValue === 'login' && document.querySelector('input[autocomplete$="webauthn"]') && await w.browserSupportsWebAuthnAutofill()) {
            this.assert(w, true);
        }
    }

    async disconnect() {
        (await lib()).WebAuthnAbortService.cancelCeremony();
    }

    label() {
        const ua = navigator.userAgent;
        const ios = /iPhone|iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (ios) return 'Войти по Face ID';
        if (/Macintosh/.test(ua)) return 'Войти по Touch ID';
        return 'Войти по ключу';
    }

    async login() {
        this.assert(await lib(), false);
    }

    async assert(w, autofill) {
        let step = 'options';
        try {
            const options = await this.post('/passkey/login/options');
            step = 'ceremony';
            const answer = await w.startAuthentication({ optionsJSON: options, useBrowserAutofill: autofill });
            step = 'login';
            const { redirect } = await this.post('/passkey/login', answer);
            window.Turbo.visit(redirect ?? '/');
        } catch (e) {
            if (autofill && step === 'ceremony') return; // тихая церемония закончилась — это норма
            this.fail('login', step, e, 'Не удалось войти по ключу');
        }
    }

    async register() {
        const w = await lib();
        let step = 'options';
        try {
            const options = await this.post('/passkey/register/options');
            step = 'ceremony';
            const answer = await w.startRegistration({ optionsJSON: options });
            step = 'save';
            await this.post('/passkey/register', { ...answer, alias: this.device() });
            window.Turbo.visit(location.href, { action: 'replace' });
        } catch (e) {
            if (e.code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') {
                window.toast?.('Ключ этого устройства уже добавлен — им можно входить');
                return;
            }
            this.fail('register', step, e, 'Не удалось создать ключ');
        }
    }

    device() {
        const ua = navigator.userAgent;
        if (/iPhone/.test(ua)) return 'iPhone';
        if (/iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return 'iPad';
        if (/Macintosh/.test(ua)) return 'Mac';
        if (/Android/.test(ua)) return 'Android';
        if (/Windows/.test(ua)) return 'Windows';
        return 'Устройство';
    }

    // Отмена — не ошибка. Остальное: тост и след на сервере, чтобы причину
    // было видно в логе без доступа к чужому телефону.
    fail(stage, step, e, fallback) {
        const name = e.cause?.name || e.name || '';
        if (/AbortError|NotAllowedError/.test(name)) return;
        let text = fallback;
        if (name === 'SecurityError') text = 'Ключ не работает на этом адресе';
        else if (e.status && e.body?.message) text = e.body.message;
        window.toast?.(text, 'danger');
        // Без _token в теле маяк ловит 419 — заголовков у sendBeacon нет.
        navigator.sendBeacon?.('/passkey/oshibka', new Blob([JSON.stringify({
            _token: document.querySelector('meta[name=csrf-token]')?.content, stage: `${stage}/${step}`, name, code: e.code ?? (e.status ? `http ${e.status}` : null),
            message: String(e.message ?? '').slice(0, 300),
            standalone: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
        })], { type: 'application/json' }));
    }

    async post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify(body ?? {}),
        });
        const json = res.status === 204 ? {} : await res.json().catch(() => ({}));
        if (!res.ok) throw Object.assign(new Error(json.message || `HTTP ${res.status}`), { status: res.status, body: json });
        return json;
    }
}
