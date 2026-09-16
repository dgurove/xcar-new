import { Controller } from '@hotwired/stimulus';

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

    async enable() {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') { this.render(); return; }
        const reg = await navigator.serviceWorker.ready;
        const key = document.querySelector('meta[name="vapid-key"]')?.content;
        this.sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key(key) });
        await fetch('/push/podpiska', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.sub.toJSON()) });
        window.toast?.('Уведомления включены');
        this.render();
    }

    async disable() {
        const endpoint = this.sub?.endpoint;
        await this.sub?.unsubscribe();
        this.sub = null;
        await fetch('/push/podpiska', { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ endpoint }) });
        this.render();
    }

    key(base64) {
        const padding = '='.repeat((4 - (base64.length % 4)) % 4);
        const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
    }
}
