import { Controller } from '@hotwired/stimulus';
import { post, request } from '../lib/http';
import { markPush, vapidKey } from '../push-sync';

// Уведомления на телефон: разрешение, подписка, отправка ключей на сервер.
export default class extends Controller {
    static targets = ['on', 'off', 'state', 'toggle'];

    async connect() {
        this.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        if (!this.supported) { this.element.hidden = true; return; }
        const reg = await navigator.serviceWorker.ready;
        this.sub = await reg.pushManager.getSubscription();
        this.render();
    }

    render() {
        const granted = Notification.permission === 'granted' && this.sub;
        if (this.hasOnTarget) this.onTarget.hidden = !!granted;
        if (this.hasOffTarget) this.offTarget.hidden = !granted;
        // Тумблер в строке настроек: одно поле вместо пары кнопок.
        if (this.hasToggleTarget) { this.toggleTarget.checked = !!granted; this.toggleTarget.disabled = Notification.permission === 'denied'; }
        if (this.hasStateTarget) this.stateTarget.textContent = Notification.permission === 'denied' ? 'Уведомления запрещены в настройках телефона' : '';
    }

    toggle(event) {
        event.target.checked ? this.enable() : this.disable();
    }

    // Не вышло на любом шаге (подписка браузера, ключи на сервер) — тумблер возвращается как был: подписки, о которой
    // сервер не знает, не остаётся.
    async enable() {
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return;
            const reg = await navigator.serviceWorker.ready;
            const key = document.querySelector('meta[name="vapid-key"]')?.content;
            const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKey(key) });
            if (!(await post('/push/subscription', sub.toJSON()))) { await sub.unsubscribe().catch(() => {}); return; }
            this.sub = sub;
            markPush(true);
            window.toast?.('Уведомления включены');
        } catch {
            window.toast?.('Не получилось включить уведомления', 'danger');
        } finally {
            this.render();
        }
    }

    // Сначала сервер: не ответил — подписка остаётся, тумблер включён.
    async disable() {
        try {
            if (!(await request('/push/subscription', { method: 'DELETE', json: { endpoint: this.sub?.endpoint } }))) return;
            await this.sub?.unsubscribe();
            this.sub = null;
            markPush(false);
        } catch {
            window.toast?.('Не получилось выключить уведомления', 'danger');
        } finally {
            this.render();
        }
    }
}
