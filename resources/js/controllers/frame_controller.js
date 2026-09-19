import { Controller } from '@hotwired/stimulus';

// Письмо в песочнице iframe: высота по содержимому. Письмо в закрытой шторке грузится невидимым
// (высота нулевая) — подгоняется заново, когда рамка получает ширину.
export default class extends Controller {
    static targets = ['frame'];

    connect() {
        if (this.frameTarget.contentDocument?.readyState === 'complete') this.fit();
        this.observer = new ResizeObserver(() => { if (this.frameTarget.clientWidth > 0) this.fit(); });
        this.observer.observe(this.frameTarget);
    }

    disconnect() {
        this.observer?.disconnect();
    }

    fit() {
        try {
            const doc = this.frameTarget.contentDocument;
            const height = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight || 0);
            if (!height) return;
            this.frameTarget.style.height = Math.min(Math.max(height + 8, 60), 4000) + 'px';
        } catch {
            this.frameTarget.style.height = '480px';
        }
    }
}
