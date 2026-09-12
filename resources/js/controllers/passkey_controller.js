import { Controller } from '@hotwired/stimulus';

const webpass = () => import('@laragear/webpass').then((m) => m.default);

// Ключ доступа (Face ID / Touch ID). Блок показывается только там, где
// устройство умеет проверять владельца, иначе кнопки нет вовсе.
export default class extends Controller {
    static targets = ['root', 'alias'];

    async connect() {
        const Webpass = await webpass();
        if (Webpass.isSupported()) this.element.hidden = false;
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
