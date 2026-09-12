import { Controller } from '@hotwired/stimulus';

// Приложение на телефоне: воркер, бейдж на иконке, установка на экран «Домой».
export default class extends Controller {
    static targets = ['install'];

    connect() {
        this.register();
        this.badge();
        this.onLoad = () => this.badge();
        document.addEventListener('turbo:load', this.onLoad);
        this.installHint();
        // Android: Chrome отдаёт событие один раз на полную загрузку, дальше живёт в window
        // (тело страницы Turbo меняет, контроллер подключается заново).
        window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); window.installPrompt = e; this.showInstall(); });
        window.addEventListener('appinstalled', () => { window.installPrompt = null; this.showInstall(); });
        this.showInstall();
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.onLoad);
    }

    // Скрипт воркера всегда из сети; в standalone полных загрузок нет, поэтому
    // обновление проверяется ещё и при возврате из фона.
    async register() {
        if (!('serviceWorker' in navigator) || window.swRegistered) return;
        window.swRegistered = true;
        try {
            const registration = await navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' });
            document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && registration.update().catch(() => {}));
        } catch {}
    }

    badge() {
        const count = Number(document.querySelector('meta[name="badge-count"]')?.content || 0);
        if (!('setAppBadge' in navigator)) return;
        (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
    }

    get standalone() {
        return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    }

    get ios() {
        return /iphone|ipad/i.test(navigator.userAgent) && !window.MSStream;
    }

    // Кнопка «Установить приложение» в кабинете: на Android — когда Chrome готов, на iOS — всегда вне приложения.
    showInstall() {
        if (!this.hasInstallTarget) return;
        this.installTarget.hidden = this.standalone || !(window.installPrompt || this.ios);
    }

    async install() {
        if (window.installPrompt) {
            window.installPrompt.prompt();
            const { outcome } = await window.installPrompt.userChoice;
            if (outcome === 'accepted') window.installPrompt = null;
            this.showInstall();
            return;
        }
        this.hint();
    }

    // iOS: только «Поделиться → На экран Домой», подсказываем не чаще раза в неделю.
    installHint() {
        if (this.standalone || !this.ios || !document.querySelector('meta[name="badge-count"]')) return;
        let last = 0;
        try { last = Number(localStorage.getItem('install-hint') || 0); } catch {}
        if (Date.now() - last < 7 * 86400 * 1000) return;
        try { localStorage.setItem('install-hint', String(Date.now())); } catch {}
        setTimeout(() => this.hint(), 3000);
    }

    hint() {
        window.toast?.(`Добавьте ${document.querySelector('meta[name="apple-mobile-web-app-title"]')?.content ?? 'XCar'} на экран «Домой»: Поделиться → На экран «Домой»`);
    }
}
