import { Controller } from '@hotwired/stimulus';

const webpass = () => import('@laragear/webpass').then((m) => m.default);

// Ключ доступа (Face ID / Touch ID). Блок показывается только там, где
// устройство умеет проверять владельца, иначе кнопки нет вовсе. Где клавиатура
// умеет предлагать ключ сама (conditional mediation — iPhone, Android), церемония
// запускается тихо при открытии страницы: «Войти с ключом» появляется над
// клавиатурой в поле логина, кнопка и «или» тогда не нужны.
export default class extends Controller {
    static targets = ['root', 'alias'];
    static values = { mode: String };

    async connect() {
        const Webpass = await webpass();
        if (!Webpass.isSupported()) return;
        this.element.hidden = false;
        if (this.modeValue === 'login' && document.querySelector('input[autocomplete$="webauthn"]') && await Webpass.isAutofillable()) {
            this.element.hidden = true;
            this.autofill(Webpass);
        }
    }

    async autofill(Webpass) {
        this.autofilling = true;
        const { success, data, error } = await Webpass.assert('/passkey/login/options', { path: '/passkey/login', useAutofill: true });
        this.autofilling = false;
        if (success) window.Turbo.visit(data?.redirect ?? '/');
        else if (error && !/Abort|NotAllowed/.test(error.cause?.name || error.name || '')) this.element.hidden = false;
    }

    async login() {
        const Webpass = await webpass();
        const { success, data, error } = await Webpass.assert('/passkey/login/options', '/passkey/login');
        if (success) {
            window.Turbo.visit(data?.redirect ?? '/');
        } else if (error?.name !== 'NotAllowedError') {
            window.toast?.('Не удалось войти по ключу', 'danger');
        }
    }

    async register() {
        const alias = this.hasAliasTarget ? this.aliasTarget.value : navigator.platform;
        const Webpass = await webpass();
        const { success, error } = await Webpass.attest('/passkey/register/options', {
            path: '/passkey/register',
            body: { alias },
        });
        if (success) {
            window.Turbo.visit(location.href, { action: 'replace' });
        } else if (error?.name !== 'NotAllowedError') {
            window.toast?.('Не удалось создать ключ', 'danger');
        }
    }
}
