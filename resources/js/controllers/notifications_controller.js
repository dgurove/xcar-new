import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { request } from '../lib/http';

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
        const base = this.frameTarget.src.replace(/\/account\/notifications\/latest.*$/, '');
        // Фоновая отметка: не дошла — бейджи остаются, тоста нет.
        if (!(await request(`${base}/account/notifications/read`, { method: 'POST', accept: 'text/html', quiet: true }))) return;
        document.querySelectorAll('[data-badge="/account/notifications"]').forEach((el) => { el.innerHTML = ''; });
        // Значок приложения — из свежих счётчиков: непрочитанные чаты в нём остаются.
        const r = await request(`${base}/live/badges`, { accept: 'text/vnd.turbo-stream.html', quiet: true });
        if (r) Turbo.renderStreamMessage(await r.text());
        document.dispatchEvent(new CustomEvent('badges:updated'));
    }
}
