import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Колокольчик: рядом со sheet на том же элементе. При открытии фрейм с пятью
// последними перечитывается, после загрузки всё отмечается прочитанным и
// бейджи гаснут.
export default class extends Controller {
    static targets = ['frame'];

    refresh() {
        const frame = this.frameTarget;
        frame.addEventListener('turbo:frame-load', () => this.markRead(), { once: true });
        if (frame.complete) frame.reload(); else frame.loading = 'eager';
    }

    async markRead() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const base = this.frameTarget.src.replace(/\/account\/notifications\/latest.*$/, '');
        try {
            await fetch(`${base}/account/notifications/read`, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'include' });
        } catch { return; }
        document.querySelectorAll('[data-badge="/account/notifications"]').forEach((el) => { el.innerHTML = ''; });
        // Значок приложения — из свежих счётчиков: непрочитанные чаты в нём остаются.
        try {
            const r = await fetch(`${base}/live/badges`, { headers: { Accept: 'text/vnd.turbo-stream.html' }, credentials: 'include' });
            if (r.ok) Turbo.renderStreamMessage(await r.text());
        } catch {}
        document.dispatchEvent(new CustomEvent('badges:updated'));
    }
}
