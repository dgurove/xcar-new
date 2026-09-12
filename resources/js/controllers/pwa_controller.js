import { Controller } from '@hotwired/stimulus';

// Приложение на телефоне: воркер, бейдж на иконке, подсказка «на экран Домой».
export default class extends Controller {
    connect() {
        this.register();
        this.badge();
        this.onLoad = () => this.badge();
        document.addEventListener('turbo:load', this.onLoad);
        this.installHint();
        window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); window.installPrompt = e; });
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.onLoad);
    }

    async register() {
        if (!('serviceWorker' in navigator) || window.swRegistered) return;
        window.swRegistered = true;
        try { await navigator.serviceWorker.register('/sw.js'); } catch {}
    }

    badge() {
        const count = Number(document.querySelector('meta[name="badge-count"]')?.content || 0);
        if (!('setAppBadge' in navigator)) return;
        (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
    }

    // iOS: только «Поделиться → На экран Домой», подсказываем не чаще раза в неделю.
    installHint() {
        const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
        const ios = /iphone|ipad/i.test(navigator.userAgent) && !window.MSStream;
        if (standalone || !ios || !document.querySelector('meta[name="badge-count"]')) return;
        let last = 0;
        try { last = Number(localStorage.getItem('install-hint') || 0); } catch {}
        if (Date.now() - last < 7 * 86400 * 1000) return;
        try { localStorage.setItem('install-hint', String(Date.now())); } catch {}
        setTimeout(() => window.toast?.(`Добавьте ${document.querySelector('meta[name="apple-mobile-web-app-title"]')?.content ?? 'XCar'} на экран «Домой»: Поделиться → На экран «Домой»`), 3000);
    }
}
