import { Controller } from '@hotwired/stimulus';

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
        const base = this.frameTarget.src.replace(/\/lk\/uvedomleniya\/svezhie.*$/, '');
        try {
            await fetch(`${base}/lk/uvedomleniya/prochitano`, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'include' });
        } catch { return; }
        document.querySelectorAll('[data-badge="/lk/uvedomleniya"]').forEach((el) => { el.innerHTML = ''; });
        document.querySelector('meta[name="badge-count"]')?.setAttribute('content', '0');
        navigator.clearAppBadge?.();
    }
}
