import { Controller } from '@hotwired/stimulus';

// Письмо в песочнице iframe: высота по содержимому. Письмо в закрытой шторке грузится невидимым
// (высота нулевая) — подгоняется заново, когда рамка получает ширину.
export default class extends Controller {
    static targets = ['frame'];

    connect() {
        if (this.frameTarget.contentDocument?.readyState === 'complete') this.fit();
        this.width = this.frameTarget.clientWidth;
        this.observer = new ResizeObserver(() => {
            const width = this.frameTarget.clientWidth;
            if (width > 0 && width !== this.width) { this.width = width; this.fit(); }
        });
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
