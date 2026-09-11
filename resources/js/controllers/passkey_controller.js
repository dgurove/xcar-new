import { Controller } from '@hotwired/stimulus';
import Webpass from '@laragear/webpass';

// Ключ доступа (Face ID / Touch ID). Блок показывается только там, где
// устройство умеет проверять владельца, иначе кнопки нет вовсе.
export default class extends Controller {
    static targets = ['root', 'alias'];

    connect() {
        if (Webpass.isSupported()) this.element.hidden = false;
    }

    async login() {
        const { success, data, error } = await Webpass.assert('/passkey/login/options', '/passkey/login');
        if (success) {
            window.Turbo.visit(data?.redirect ?? '/');
        } else if (error?.name !== 'NotAllowedError') {
            window.toast?.('Не удалось войти по ключу', 'danger');
        }
    }

    async register() {
        const alias = this.hasAliasTarget ? this.aliasTarget.value : navigator.platform;
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
